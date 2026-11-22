<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_activity.php';
require_role(['customer']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST'); http_response_code(405); exit('Method not allowed.');
}
$csrf = $_POST['csrf_token'] ?? null;
if (!is_string($csrf) || !csrf_verify($csrf)) { http_response_code(403); exit('Reload the trip and try again.'); }
$tripId = filter_var($_POST['trip_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = $_POST['action'] ?? null;
if (!$tripId || !in_array($action, ['archive', 'restore'], true)) { http_response_code(400); exit('Invalid archive request.'); }
$pdo = get_db_connection();
require_trip_permission($pdo, $tripId, 'manage_trip');
try {
    $pdo->beginTransaction();
    lock_trip_and_require_permission($pdo, $tripId, 'manage_trip');
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LOCK IN SHARE MODE');
    $stmt->execute(['id' => current_user_id()]);
    if ($stmt->fetchColumn() !== 'customer') {
        $pdo->rollBack(); http_response_code(403); exit('Your account access changed.');
    }
    $stmt = $pdo->prepare($action === 'archive'
        ? 'UPDATE trips SET archived_at = UTC_TIMESTAMP() WHERE id = :trip AND archived_at IS NULL'
        : 'UPDATE trips SET archived_at = NULL WHERE id = :trip AND archived_at IS NOT NULL');
    $stmt->execute(['trip' => $tripId]);
    if ($stmt->rowCount() === 1) {
        log_trip_activity($pdo, $tripId, $action === 'archive' ? 'trip_archived' : 'trip_restored');
    }
    $pdo->commit();
    $_SESSION['dashboard_notice'] = $action === 'archive' ? 'Trip moved to Archived. Its data and permissions are unchanged.' : 'Trip restored to Active trips.';
    header('Location: /customer/dashboard.php' . ($action === 'archive' ? '?archived=1' : ''));
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('TravelEase: trip archive action failed (database code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    http_response_code(503); echo 'Could not update the trip archive. Please try again.';
}
exit;
