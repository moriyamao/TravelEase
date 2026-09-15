<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';

require_role(['customer']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed.');
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Your session expired. Please go back and try again.');
}

$tripId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($tripId === false || $tripId === null) {
    http_response_code(400);
    die('Invalid trip.');
}

$pdo = get_db_connection();

try {
    // Scoped by user_id -- deleting someone else's trip by guessing
    // an ID simply matches zero rows (§26 -- IDOR prevention).
    $stmt = $pdo->prepare('DELETE FROM trips WHERE id = :id AND user_id = :user_id');
    $stmt->execute([
        'id'      => $tripId,
        'user_id' => current_user_id(),
    ]);

    header('Location: /customer/dashboard.php');
    exit;

} catch (PDOException $e) {
    error_log('TravelEase: trip deletion failed: ' . $e->getMessage());
    http_response_code(500);
    die('Something went wrong while deleting your trip. Please try again.');
}
