<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/trip_validation.php';
require_once __DIR__ . '/../../includes/itinerary_validation.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_activity.php';

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

// Authorize against the parent trip BEFORE touching itinerary_items
// at all -- an item has no user_id of its own, so the trip role
// (owner or editor) is the only thing standing between a customer and
// someone else's itinerary (§26 -- IDOR prevention, two levels deep).
require_trip_permission($pdo, $tripId, 'edit_itinerary');
$stmt = $pdo->prepare('SELECT start_date, end_date, budget_currency FROM trips WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $tripId]);
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
// FR-19 fields. Older forms (e.g. AI suggestion cards) do not send them,
// so a missing field means no end time, no category and unassigned.
// A non-string value (e.g. category[]=x) becomes "\0", which fails validation.
$postString   = fn(string $key): string => !array_key_exists($key, $_POST) ? ''
    : (is_string($_POST[$key]) ? trim($_POST[$key]) : "\0");
$endTime      = $postString('end_time');
$category     = $postString('category');
$assigneeId   = parse_item_assignee($postString('assignee_id'));

$errors = [];
if ($err = validate_item_date($itemDate, $trip['start_date'], $trip['end_date'])) $errors[] = $err;
if ($err = validate_item_time($itemTime))     $errors[] = $err;
if ($err = validate_item_title($title))       $errors[] = $err;
if ($err = validate_item_location($location)) $errors[] = $err;
if ($err = validate_item_notes($notes))       $errors[] = $err;
if ($err = validate_money_amount($estimated, 'Estimated cost')) $errors[] = $err;
if ($err = validate_money_amount($actual, 'Actual cost'))       $errors[] = $err;
if ($err = validate_item_end_time($itemTime, $endTime)) $errors[] = $err;
if ($err = validate_item_category($category))  $errors[] = $err;
if ($assigneeId === false) $errors[] = 'Choose a current trip member to assign.';

if (!empty($errors)) {
    // Simple flash-style pass-through via session for this one-off
    // case -- no full flash-message system exists yet, and adding one
    // for a single form isn't warranted.
    $_SESSION['itinerary_errors'] = $errors;
    header('Location: /customer/trips/view.php?id=' . $tripId);
    exit;
}

try {
    $pdo->beginTransaction();
    // Re-check the role under the trip lock so a removal or demotion
    // that commits after the check above cannot be bypassed.
    lock_trip_and_require_permission($pdo, $tripId, 'edit_itinerary');
    // The assignee must be a current customer member, checked under the
    // same lock that member removals take.
    if ($assigneeId !== null && !trip_member_is_assignable($pdo, $tripId, $assigneeId)) {
        $pdo->rollBack();
        $_SESSION['itinerary_errors'] = ['Choose a current trip member to assign.'];
        header('Location: /customer/trips/view.php?id=' . $tripId);
        exit;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO itinerary_items
            (trip_id, item_date, item_time, end_time, title, location, category, assignee_id, sort_order,
             notes, estimated_cost, actual_cost)
         VALUES (:trip_id, :item_date, :item_time, :end_time, :title, :location, :category, :assignee_id, :sort_order,
                 :notes, :estimated_cost, :actual_cost)'
    );
    $stmt->execute([
        'trip_id'        => $tripId,
        'item_date'      => $itemDate,
        'item_time'      => $itemTime !== '' ? $itemTime : null,
        'end_time'       => $endTime !== '' ? $endTime : null,
        'title'          => $title,
        'location'       => $location !== '' ? $location : null,
        'category'       => $category !== '' ? $category : null,
        'assignee_id'    => $assigneeId,
        'sort_order'     => itinerary_next_sort_order($pdo, $tripId, $itemDate),
        'notes'          => $notes !== '' ? $notes : null,
        'estimated_cost' => $estimated !== '' ? $estimated : null,
        'actual_cost'    => $actual !== '' ? $actual : null,
    ]);
    log_trip_activity($pdo, $tripId, 'activity_added', null, $title);
    $pdo->commit();

    header('Location: /customer/trips/view.php?id=' . $tripId);
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TravelEase: itinerary item creation failed: ' . $e->getMessage());
    $_SESSION['itinerary_errors'] = ['Something went wrong while saving. Please try again.'];
    header('Location: /customer/trips/view.php?id=' . $tripId);
    exit;
}
