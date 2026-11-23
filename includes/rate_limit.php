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
 * The visitor's IP address used for per-IP limits, or null when no
 * trustworthy address is available (per-IP limits are then skipped and
 * only the per-email / per-account limits apply). Returning null instead of
 * a proxy's address avoids putting every visitor in one shared bucket,
 * where a burst from anyone would lock everyone out.
 *
 * CLIENT_IP_SOURCE chooses where the address comes from:
 * - unset / "remote_addr": REMOTE_ADDR. X-Forwarded-For is honoured only
 *   when REMOTE_ADDR is listed in TRUSTED_PROXIES (comma-separated IPs or
 *   CIDR ranges); then the right-most forwarded address that is not itself
 *   a trusted proxy is used.
 * - "header:<Name>", e.g. "header:CF-Connecting-IP": that request header.
 *   Only for hosts where the app cannot be reached except through a proxy
 *   that always overwrites the header (verify this before enabling it).
 * - "none": no per-IP limits. The Docker image defaults to this because
 *   Render publishes no ingress ranges to put in TRUSTED_PROXIES.
 */
function client_ip(): ?string
{
    $source = trim((string) (getenv('CLIENT_IP_SOURCE') ?: 'remote_addr'));
    if (strcasecmp($source, 'none') === 0) {
        return null;
    }
    if (strncasecmp($source, 'header:', 7) === 0) {
        $name = 'HTTP_' . strtoupper(str_replace('-', '_', trim(substr($source, 7))));
        $value = trim((string) ($_SERVER[$name] ?? ''));
        return filter_var($value, FILTER_VALIDATE_IP) ? $value : null;
    }
    if (strcasecmp($source, 'remote_addr') !== 0) {
        return null;                     // unknown setting: fail safe, skip per-IP limits
    }

    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($remote, FILTER_VALIDATE_IP)) {
        return null;
    }
    $trusted = array_filter(array_map('trim', explode(',', (string) (getenv('TRUSTED_PROXIES') ?: ''))));
    if (!$trusted || !ip_in_ranges($remote, $trusted)) {
        return $remote;                  // direct client: the header is ignored
    }
    // The peer is a trusted proxy, so its own address is shared by everyone.
    $hops = array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
    foreach ($hops as $hop) {
        if (!filter_var($hop, FILTER_VALIDATE_IP)) {
            break;                       // missing or malformed entry: stop trusting the chain
        }
        if (!ip_in_ranges($hop, $trusted)) {
            return $hop;
        }
    }
    return null;
}

/** Rate-limit key for the visitor's IP, or null when per-IP limits are skipped. */
function rate_limit_ip_key(string $bucket): ?string
{
    $ip = client_ip();
    return $ip === null ? null : rate_limit_key($bucket, $ip);
}

/** Whether $ip is inside any of the given IPs / CIDR ranges (IPv4 or IPv6). */
function ip_in_ranges(string $ip, array $ranges): bool
{
    $addr = @inet_pton($ip);
    if ($addr === false) {
        return false;
    }
    foreach ($ranges as $range) {
        [$net, $bits] = array_pad(explode('/', $range, 2), 2, null);
        $netAddr = @inet_pton((string) $net);
        if ($netAddr === false || strlen($netAddr) !== strlen($addr)) {
            continue;
        }
        $bits = $bits === null ? strlen($addr) * 8 : (int) $bits;
        if ($bits < 0 || $bits > strlen($addr) * 8) {
            continue;
        }
        $full = intdiv($bits, 8);
        $rest = $bits % 8;
        if (substr($addr, 0, $full) !== substr($netAddr, 0, $full)) {
            continue;
        }
        if ($rest === 0 || ((ord($addr[$full]) ^ ord($netAddr[$full])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Count one attempt for $key (starting a new window when the old one ended)
 * and return [this request's own count, the window it was counted in].
 * The upsert stores the new count through LAST_INSERT_ID(expr), which
 * MySQL/MariaDB keep per connection, so parallel requests each see exactly
 * the value they wrote. The window start is read in the same transaction,
 * while the upsert's row lock is held, so it is the window this attempt was
 * counted in even if another request rolls the window right after.
 */
function rate_limit_hit(PDO $pdo, string $key, int $windowSeconds): array
{
    if (random_int(1, 50) === 1) {
        // Bounded, indexed cleanup of long-expired windows.
        $pdo->exec('DELETE FROM rate_limits WHERE window_started < UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 100');
    }
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        // attempts is assigned before window_started, so both read the old window start.
        $stmt = $pdo->prepare(
            'INSERT INTO rate_limits (limit_key, window_started, attempts) VALUES (:key, UTC_TIMESTAMP(), 1)
             ON DUPLICATE KEY UPDATE
                 attempts = LAST_INSERT_ID(IF(TIMESTAMPDIFF(SECOND, window_started, UTC_TIMESTAMP()) >= :w1, 1, attempts + 1)),
                 window_started = IF(TIMESTAMPDIFF(SECOND, window_started, UTC_TIMESTAMP()) >= :w2, UTC_TIMESTAMP(), window_started)'
        );
        $stmt->execute(['key' => $key, 'w1' => $windowSeconds, 'w2' => $windowSeconds]);
        // 1 affected row = new row (count 1); 2 = existing row updated.
        $count = $stmt->rowCount() === 1 ? 1 : (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
        $window = $pdo->prepare('SELECT window_started FROM rate_limits WHERE limit_key = :key');
        $window->execute(['key' => $key]);
        $started = (string) $window->fetchColumn();
        if ($ownTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return [$count, $started];
}

/**
 * Count an attempt and report whether it is over the limit: returns 0 when
 * allowed, otherwise the seconds to wait. Counting first, and judging by
 * this request's own count, keeps concurrent requests from all slipping
 * through the last free slot. A null key (per-IP limit skipped) is allowed.
 * $window receives the window the attempt was counted in (null when nothing
 * was counted); pass it to rate_limit_refund().
 */
function rate_limit_reserve(PDO $pdo, ?string $key, int $max, int $windowSeconds, ?string &$window = null): int
{
    $window = null;
    if ($key === null) {
        return 0;
    }
    [$count, $window] = rate_limit_hit($pdo, $key, $windowSeconds);
    if ($count <= $max) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, window_started, UTC_TIMESTAMP()) FROM rate_limits WHERE limit_key = :key');
    $stmt->execute(['key' => $key]);
    return max(1, $windowSeconds - (int) $stmt->fetchColumn());
}

/**
 * Give back one counted attempt (an admitted attempt that turned out not to
 * be a failure). Only the window that counted it is refunded: once that
 * window has ended, the refund does nothing, so late refunds can never
 * lower a newer window's count.
 */
function rate_limit_refund(PDO $pdo, ?string $key, ?string $window): void
{
    if ($key === null || $window === null) {
        return;
    }
    $stmt = $pdo->prepare(
        'UPDATE rate_limits SET attempts = IF(attempts > 0, attempts - 1, 0)
         WHERE limit_key = :key AND window_started = :window'
    );
    $stmt->execute(['key' => $key, 'window' => $window]);
}
