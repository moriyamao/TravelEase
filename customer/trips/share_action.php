<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_activity.php';
require_once __DIR__ . '/../../includes/trip_shares.php';
require_role(['customer']);
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST'); http_response_code(405); exit('Method not allowed.');
}
$csrf = $_POST['csrf_token'] ?? null;
if (!is_string($csrf) || !csrf_verify($csrf)) { http_response_code(403); exit('Your session expired. Reload and try again.'); }
$tripId = filter_var($_POST['trip_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = $_POST['action'] ?? null;
if (!$tripId || !in_array($action, ['create', 'revoke'], true)) { http_response_code(400); exit('Invalid share-link request.'); }
$pdo = get_db_connection();
require_trip_permission($pdo, $tripId, 'manage_trip');
$me = (int) current_user_id();
$back = '/customer/trips/share_links.php?id=' . $tripId;
try {
    $pdo->beginTransaction();
    lock_trip_and_require_permission($pdo, $tripId, 'manage_trip');
    if ($action === 'create') {
        $kind = $_POST['kind'] ?? null;
        $days = $_POST['days'] ?? null;
        if (!in_array($kind, ['join', 'view'], true) || !in_array($days, ['1', '7'], true)
            || ($kind === 'view' && ($_POST['publish'] ?? null) !== '1')) {
            throw new InvalidArgumentException('Choose a link type and expiry. Confirm the public disclosure for a view-only link.');
        }
        $token = bin2hex(random_bytes(32));
        $url = trip_share_url($token);
        // This row also serializes the account-wide quota across different trips.
        $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $me]);
        if ($stmt->fetchColumn() !== 'customer') { throw new DomainException('Your account access changed.'); }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM trip_share_links WHERE created_by = :user AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR');
        $stmt->execute(['user' => $me]);
        if ((int) $stmt->fetchColumn() >= 30) { throw new InvalidArgumentException('You can create up to 30 share links per hour.'); }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM trip_share_links WHERE trip_id = :trip AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()');
        $stmt->execute(['trip' => $tripId]);
        if ((int) $stmt->fetchColumn() >= 10) { throw new InvalidArgumentException('Revoke an existing link before creating more than 10 active links.'); }
        $expires = gmdate('Y-m-d H:i:s', time() + (int) $days * 86400);
        $stmt = $pdo->prepare('INSERT INTO trip_share_links (trip_id, created_by, kind, token_hash, expires_at)
            VALUES (:trip, :user, :kind, :hash, :expires)');
        $stmt->execute(['trip' => $tripId, 'user' => $me, 'kind' => $kind, 'hash' => hash('sha256', $token), 'expires' => $expires]);
        log_trip_activity($pdo, $tripId, 'share_link_created', null, $kind === 'view' ? 'view-only' : 'join');
        $pdo->commit();
        $_SESSION['share_link_new'][$tripId] = ['url' => $url, 'kind' => $kind, 'expires_at' => $expires];
        $_SESSION['share_notice'][$tripId] = 'Copy the link now. Its address will not be shown again.';
    } else {
        $id = filter_var($_POST['link_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) { throw new InvalidArgumentException('Choose a valid share link.'); }
        $stmt = $pdo->prepare('UPDATE trip_share_links SET revoked_at = UTC_TIMESTAMP() WHERE id = :id AND trip_id = :trip AND revoked_at IS NULL');
        $stmt->execute(['id' => $id, 'trip' => $tripId]);
        if ($stmt->rowCount() > 0) { log_trip_activity($pdo, $tripId, 'share_link_revoked'); }
        $pdo->commit();
        unset($_SESSION['share_link_new'][$tripId]);
        $_SESSION['share_notice'][$tripId] = 'The link is revoked or was already unavailable.';
    }
} catch (DomainException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(403); exit($e->getMessage());
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $_SESSION['share_error'][$tripId] = $e->getMessage();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('TravelEase: share-link action failed (database code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    $_SESSION['share_error'][$tripId] = 'The share link could not be saved. Please try again.';
}
header('Location: ' . $back);
exit;
