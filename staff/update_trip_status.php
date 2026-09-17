<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

require_role(['staff', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed.');
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Your session expired. Please go back and try again.');
}

$tripId    = filter_input(INPUT_POST, 'trip_id', FILTER_VALIDATE_INT);
$newStatus = trim($_POST['status'] ?? '');

$validStatuses = ['planning', 'confirmed', 'completed', 'cancelled'];

if ($tripId === false || $tripId === null) {
    http_response_code(400);
    die('Invalid trip.');
}

if (!in_array($newStatus, $validStatuses, true)) {
    $_SESSION['staff_errors'] = ['Invalid status.'];
    header('Location: /staff/dashboard.php');
    exit;
}

$pdo = get_db_connection();

try {
    // No ownership check here by design -- staff operates across all
    // customers' trips, unlike customer-facing pages which are always
    // scoped to current_user_id().
    $stmt = $pdo->prepare('UPDATE trips SET status = :status WHERE id = :id');
    $stmt->execute([
        'status' => $newStatus,
        'id'     => $tripId,
    ]);

    header('Location: /staff/dashboard.php');
    exit;

} catch (PDOException $e) {
    error_log('TravelEase: staff trip status update failed: ' . $e->getMessage());
    $_SESSION['staff_errors'] = ['Something went wrong while updating the status. Please try again.'];
    header('Location: /staff/dashboard.php');
    exit;
}