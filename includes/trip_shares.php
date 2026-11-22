<?php
/** Bearer links store only hashes; raw tokens travel in fragments and POST bodies. */
function valid_trip_share_token(mixed $token): bool
{
    return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
}

function trip_share_url(string $token): string
{
    $base = rtrim(getenv('APP_URL') ?: '', '/');
    $parts = parse_url($base);
    if (!filter_var($base, FILTER_VALIDATE_URL) || !is_array($parts)
        || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || !in_array($parts['path'] ?? '', ['', '/'], true)) {
        throw new InvalidArgumentException('Share links are unavailable until the site address is configured.');
    }
    return $base . '/public/share.php#' . $token;
}

/** Caller must already have checked manage_trip; hashes are never returned. */
function list_trip_share_links(PDO $pdo, int $tripId): array
{
    $stmt = $pdo->prepare('SELECT id, kind, created_at, expires_at, revoked_at FROM trip_share_links WHERE trip_id = :trip
        ORDER BY (revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()) DESC, id DESC LIMIT 100');
    $stmt->execute(['trip' => $tripId]);
    return $stmt->fetchAll();
}

/** Shared across sessions and all tokens. Do not trust client-supplied proxy headers. */
function reserve_trip_share_access(PDO $pdo, string $ip): int
{
    $hash = hash('sha256', filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown');
    // Bounded, indexed cleanup outside the reservation transaction.
    $pdo->exec('DELETE FROM trip_share_access_limits WHERE window_started < UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 100');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO trip_share_access_limits (ip_hash, window_started, attempts)
            VALUES (:ip, UTC_TIMESTAMP(), 0) ON DUPLICATE KEY UPDATE ip_hash = VALUES(ip_hash)');
        $stmt->execute(['ip' => $hash]);
        $stmt = $pdo->prepare('SELECT attempts, TIMESTAMPDIFF(SECOND, window_started, UTC_TIMESTAMP()) AS elapsed
            FROM trip_share_access_limits WHERE ip_hash = :ip FOR UPDATE');
        $stmt->execute(['ip' => $hash]);
        $row = $stmt->fetch();
        $elapsed = (int) $row['elapsed'];
        if ($elapsed >= 60) {
            $stmt = $pdo->prepare('UPDATE trip_share_access_limits SET attempts = 1, window_started = UTC_TIMESTAMP() WHERE ip_hash = :ip');
        } elseif ((int) $row['attempts'] >= 60) {
            $pdo->rollBack();
            return max(1, 60 - $elapsed);
        } else {
            $stmt = $pdo->prepare('UPDATE trip_share_access_limits SET attempts = attempts + 1 WHERE ip_hash = :ip');
        }
        $stmt->execute(['ip' => $hash]);
        $pdo->commit();
        return 0;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/** Inside a transaction: trip first, then link, matching owner/member writes. */
function load_trip_share(PDO $pdo, string $token, bool $write = false): ?array
{
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare('SELECT trip_id FROM trip_share_links WHERE token_hash = :hash');
    $stmt->execute(['hash' => $hash]);
    $tripId = $stmt->fetchColumn();
    if ($tripId === false) { return null; }
    $stmt = $pdo->prepare('SELECT id, user_id, destination, start_date, end_date, time_zone FROM trips WHERE id = :id '
        . ($write ? 'FOR UPDATE' : 'LOCK IN SHARE MODE'));
    $stmt->execute(['id' => $tripId]);
    $trip = $stmt->fetch();
    if (!$trip) { return null; }
    $stmt = $pdo->prepare('SELECT id, kind FROM trip_share_links WHERE trip_id = :trip AND token_hash = :hash
        AND created_by = :owner AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP() LOCK IN SHARE MODE');
    $stmt->execute(['trip' => $tripId, 'hash' => $hash, 'owner' => $trip['user_id']]);
    $link = $stmt->fetch();
    if (!$link) { return null; }
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LOCK IN SHARE MODE');
    $stmt->execute(['id' => $trip['user_id']]);
    return $stmt->fetchColumn() === 'customer' ? ['trip' => $trip, 'link' => $link] : null;
}
