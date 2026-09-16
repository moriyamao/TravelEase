<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/trip_validation.php';
require_once __DIR__ . '/../../includes/itinerary_validation.php';

require_role(['customer']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed.');
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Your session expired. Please go back and try again.');
}

$tripId = filter_input(INPUT_POST, 'trip_id', FILTER_VALIDATE_INT);
if ($tripId === false || $tripId === null) {
    http_response_code(400);
    die('Invalid trip.');
}

$pdo = get_db_connection();

// Confirm this trip belongs to the logged-in user BEFORE touching
// itinerary_items at all -- an item has no user_id of its own, so
// this parent-trip ownership check is the only thing standing
// between a customer and someone else's itinerary (§26 -- IDOR
// prevention, two levels deep).
$stmt = $pdo->prepare('SELECT start_date, end_date, budget_currency FROM trips WHERE id = :id AND user_id = :user_id LIMIT 1');
$stmt->execute(['id' => $tripId, 'user_id' => current_user_id()]);
$trip = $stmt->fetch();

if (!$trip) {
    http_response_code(404);
    die('Trip not found.');
}

$itemDate     = trim($_POST['item_date'] ?? '');
$itemTime     = trim($_POST['item_time'] ?? '');
$title        = trim($_POST['title'] ?? '');
$location     = trim($_POST['location'] ?? '');
$notes        = trim($_POST['notes'] ?? '');
$estimated    = trim($_POST['estimated_cost'] ?? '');
$actual       = trim($_POST['actual_cost'] ?? '');

$errors = [];
if ($err = validate_item_date($itemDate, $trip['start_date'], $trip['end_date'])) $errors[] = $err;
if ($err = validate_item_time($itemTime))     $errors[] = $err;
if ($err = validate_item_title($title))       $errors[] = $err;
if ($err = validate_item_location($location)) $errors[] = $err;
if ($err = validate_item_notes($notes))       $errors[] = $err;
if ($err = validate_money_amount($estimated, 'Estimated cost')) $errors[] = $err;
if ($err = validate_money_amount($actual, 'Actual cost'))       $errors[] = $err;

if (!empty($errors)) {
    // Simple flash-style pass-through via session for this one-off
    // case -- no full flash-message system exists yet, and adding one
    // for a single form isn't warranted.
    $_SESSION['itinerary_errors'] = $errors;
    header('Location: /customer/trips/view.php?id=' . $tripId);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO itinerary_items (trip_id, item_date, item_time, title, location, notes, estimated_cost, actual_cost)
         VALUES (:trip_id, :item_date, :item_time, :title, :location, :notes, :estimated_cost, :actual_cost)'
    );
    $stmt->execute([
        'trip_id'        => $tripId,
        'item_date'      => $itemDate,
        'item_time'      => $itemTime !== '' ? $itemTime : null,
        'title'          => $title,
        'location'       => $location !== '' ? $location : null,
        'notes'          => $notes !== '' ? $notes : null,
        'estimated_cost' => $estimated !== '' ? $estimated : null,
        'actual_cost'    => $actual !== '' ? $actual : null,
    ]);

    header('Location: /customer/trips/view.php?id=' . $tripId);
    exit;

} catch (PDOException $e) {
    error_log('TravelEase: itinerary item creation failed: ' . $e->getMessage());
    $_SESSION['itinerary_errors'] = ['Something went wrong while saving. Please try again.'];
    header('Location: /customer/trips/view.php?id=' . $tripId);
    exit;
}
