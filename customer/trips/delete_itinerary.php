<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/trip_membership.php';

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

// Find the item's trip, then authorize the trip role (owner or editor)
// before deleting. Non-members get the same 404 as a missing item.
$stmt = $pdo->prepare('SELECT trip_id FROM itinerary_items WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $itemId]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    die('Item not found.');
}

require_trip_permission($pdo, (int) $row['trip_id'], 'edit_itinerary');

try {
    // Scoped to the trip that was authorized above.
    $stmt = $pdo->prepare('DELETE FROM itinerary_items WHERE id = :id AND trip_id = :trip_id');
    $stmt->execute(['id' => $itemId, 'trip_id' => $row['trip_id']]);

    header('Location: /customer/trips/view.php?id=' . $row['trip_id']);
    exit;

} catch (PDOException $e) {
    error_log('TravelEase: itinerary item deletion failed: ' . $e->getMessage());
    http_response_code(500);
    die('Something went wrong while deleting. Please try again.');
}
