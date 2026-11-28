<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_activity.php';
require_once __DIR__ . '/../../includes/trip_shares.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
require_role(['customer']);
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST'); http_response_code(405); exit('Method not allowed.');
}
$csrf = $_POST['csrf_token'] ?? null;
if (!is_string($csrf) || !csrf_verify($csrf)) { http_response_code(403); exit('Reload the original share link after signing in, then try again.'); }
$token = $_POST['token'] ?? null;
if (!valid_trip_share_token($token)) { http_response_code(404); exit('This link is unavailable or has expired.'); }
$pdo = get_db_connection();
$me = (int) current_user_id();
try {
    $wait = reserve_trip_share_access($pdo, client_ip());
    if ($wait) { header('Retry-After: ' . $wait); http_response_code(429); exit('Too many requests. Try again shortly.'); }
    $pdo->beginTransaction();
    $share = load_trip_share($pdo, $token, true);
    if (!$share || $share['link']['kind'] !== 'join') {
        $pdo->rollBack(); http_response_code(404); exit('This link is unavailable or has expired.');
    }
    $stmt = $pdo->prepare('SELECT role, email_verified_at FROM users WHERE id = :id LOCK IN SHARE MODE');
    $stmt->execute(['id' => $me]);
    $user = $stmt->fetch();
    if (!$user || $user['role'] !== 'customer' || $user['email_verified_at'] === null) {
        $pdo->rollBack(); http_response_code(403); exit('Sign in with a verified customer account before joining.');
    }
    $tripId = (int) $share['trip']['id'];
    // A locking read sees joins committed while this request waited for the
    // trip lock; the initial token lookup may have started an older snapshot.
    $stmt = $pdo->prepare('SELECT role FROM trip_memberships WHERE trip_id = :trip AND user_id = :user LOCK IN SHARE MODE');
    $stmt->execute(['trip' => $tripId, 'user' => $me]);
    $memberRole = $stmt->fetchColumn();
    if ((int) $share['trip']['user_id'] !== $me && $memberRole === false) {
        $stmt = $pdo->prepare('INSERT INTO trip_memberships (trip_id, user_id, role) VALUES (:trip, :user, \'viewer\')');
        $stmt->execute(['trip' => $tripId, 'user' => $me]);
        // A bearer join is not acceptance of an older email invitation. Retire
        // that pending issue so it cannot later change or duplicate access.
        $stmt = $pdo->prepare('UPDATE trip_invitations SET status = \'revoked\', responded_at = UTC_TIMESTAMP()
            WHERE trip_id = :trip AND invitee_id = :user AND status = \'pending\'');
        $stmt->execute(['trip' => $tripId, 'user' => $me]);
        log_trip_activity($pdo, $tripId, 'invitation_accepted', null, 'Viewer');
    }
    $pdo->commit();
    header('Location: /customer/trips/view.php?id=' . $tripId);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('TravelEase: share-link join failed (database code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    http_response_code(503); echo 'Could not join the trip. Please try again.';
}
exit;
