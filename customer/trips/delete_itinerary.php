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

$itemId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($itemId === false || $itemId === null) {
    http_response_code(400);
    die('Invalid item.');
}

$pdo = get_db_connection();

// Find the trip_id first (for the redirect) while confirming
// ownership through the join -- if this returns nothing, the item
// either doesn't exist or isn't the logged-in user's.
$stmt = $pdo->prepare(
    'SELECT i.trip_id
     FROM itinerary_items i
     INNER JOIN trips t ON t.id = i.trip_id
     WHERE i.id = :id AND t.user_id = :user_id
     LIMIT 1'
);
$stmt->execute(['id' => $itemId, 'user_id' => current_user_id()]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    die('Item not found.');
}

try {
    // Ownership re-checked in the DELETE itself, not just the SELECT above.
    $stmt = $pdo->prepare(
        'DELETE i FROM itinerary_items i
         INNER JOIN trips t ON t.id = i.trip_id
         WHERE i.id = :id AND t.user_id = :user_id'
    );
    $stmt->execute(['id' => $itemId, 'user_id' => current_user_id()]);

    header('Location: /customer/trips/view.php?id=' . $row['trip_id']);
    exit;

} catch (PDOException $e) {
    error_log('TravelEase: itinerary item deletion failed: ' . $e->getMessage());
    http_response_code(500);
    die('Something went wrong while deleting. Please try again.');
}
