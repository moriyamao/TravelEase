<?php
/**
 * Database-backed fixed-window rate limits (sign-in, registration, chat).
 * Same storage pattern as the share-link limiter: one row per hashed key
 * with a window start and a counter, so limits survive dropped cookies,
 * new sessions and re-login. Keys are sha256 hashes; raw emails and IP
 * addresses are never stored.
 *
 * Table: rate_limits (migration 029).
 */

function rate_limit_key(string $bucket, string $subject): string
{
    return hash('sha256', $bucket . '|' . $subject);
}

/**
 * The visitor's IP address. Render terminates TLS at its proxy, so the
 * direct peer (REMOTE_ADDR) is a private proxy address there; in that case
 * only, use the right-most valid entry of X-Forwarded-For, which the proxy
 * appends itself. A public REMOTE_ADDR is always used as-is, so a client
 * cannot choose its own address by sending the header directly.
 */
function client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $isPublic = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    if (!$isPublic && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $hops = array_reverse(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])));
        foreach ($hops as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP)) {
                return $hop;
            }
        }
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
}

/** Seconds until $key may act again (0 = allowed). Read-only check. */
function rate_limit_wait(PDO $pdo, string $key, int $max, int $windowSeconds): int
{
    $stmt = $pdo->prepare('SELECT attempts, TIMESTAMPDIFF(SECOND, window_started, UTC_TIMESTAMP()) AS elapsed
                           FROM rate_limits WHERE limit_key = :key');
    $stmt->execute(['key' => $key]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['elapsed'] >= $windowSeconds || (int) $row['attempts'] < $max) {
        return 0;
    }
    return max(1, $windowSeconds - (int) $row['elapsed']);
}

/** Count one attempt for $key (starts a new window when the old one ended). Atomic. */
function rate_limit_hit(PDO $pdo, string $key, int $windowSeconds): void
{
    if (random_int(1, 50) === 1) {
        // Bounded, indexed cleanup of long-expired windows.
        $pdo->exec('DELETE FROM rate_limits WHERE window_started < UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 100');
    }
    // attempts is assigned before window_started, so both read the old window start.
    $stmt = $pdo->prepare(
        'INSERT INTO rate_limits (limit_key, window_started, attempts) VALUES (:key, UTC_TIMESTAMP(), 1)
         ON DUPLICATE KEY UPDATE
             attempts = IF(TIMESTAMPDIFF(SECOND, window_started, UTC_TIMESTAMP()) >= :w1, 1, attempts + 1),
             window_started = IF(TIMESTAMPDIFF(SECOND, window_started, UTC_TIMESTAMP()) >= :w2, UTC_TIMESTAMP(), window_started)'
    );
    $stmt->execute(['key' => $key, 'w1' => $windowSeconds, 'w2' => $windowSeconds]);
}

/**
 * Count an attempt and report whether it is over the limit: returns 0 when
 * allowed, otherwise the seconds to wait. Counting first keeps concurrent
 * requests from all slipping through between a check and a write.
 */
function rate_limit_reserve(PDO $pdo, string $key, int $max, int $windowSeconds): int
{
    rate_limit_hit($pdo, $key, $windowSeconds);
    $stmt = $pdo->prepare('SELECT attempts, TIMESTAMPDIFF(SECOND, window_started, UTC_TIMESTAMP()) AS elapsed
                           FROM rate_limits WHERE limit_key = :key');
    $stmt->execute(['key' => $key]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['attempts'] <= $max) {
        return 0;
    }
    return max(1, $windowSeconds - (int) $row['elapsed']);
}
