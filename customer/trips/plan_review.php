<?php
/**
 * FR-28: ask the planning assistant to review a trip (owners and editors).
 * Results are kept in the session for 30 minutes and shown on the trip page;
 * nothing in the trip changes here. action=clear removes them.
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/itinerary_schedule.php';
require_once __DIR__ . '/../../includes/travel_time.php';
require_once __DIR__ . '/../../includes/plan_review.php';

require_role(['customer']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}
$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || !csrf_verify($token)) {
    http_response_code(403);
    exit('Your session expired. Reload the page and try again.');
}
$tripId = filter_var($_POST['trip_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = $_POST['action'] ?? 'review';
if (!$tripId || !in_array($action, ['review', 'clear'], true)) {
    http_response_code(400);
    exit('Invalid request.');
}

$pdo = get_db_connection();
require_trip_permission($pdo, $tripId, 'edit_itinerary');
$back = '/customer/trips/view.php?id=' . $tripId . '#plan-review';

if ($action === 'clear') {
    unset($_SESSION['plan_review'][$tripId]);
    header('Location: ' . $back);
    exit;
}

$stmt = $pdo->prepare('SELECT name, destination, start_date, end_date, budget_currency, budget_amount, time_zone FROM trips WHERE id = :id');
$stmt->execute(['id' => $tripId]);
$trip = $stmt->fetch();
$stmt = $pdo->prepare(
    'SELECT i.id, i.item_date, i.item_time, i.end_time, i.title, i.category, i.estimated_cost, i.actual_cost,
            tp.name AS place_name, tp.latitude AS place_lat, tp.longitude AS place_lon
     FROM itinerary_items i
     LEFT JOIN trip_places tp ON tp.id = i.place_id AND tp.trip_id = i.trip_id
     WHERE i.trip_id = :trip_id
     ORDER BY i.item_date ASC, ' . ITINERARY_DAY_ORDER_SQL
);
$stmt->execute(['trip_id' => $tripId]);
$items = $stmt->fetchAll();

$warnings = itinerary_schedule_warnings($items, $trip['start_date'], $trip['end_date']);
$pairs = itinerary_travel_pairs($items);
foreach (itinerary_travel_warnings($pairs, cached_travel_estimates($pdo, $pairs)) as $warnedId => $list) {
    $warnings[$warnedId] = array_merge($warnings[$warnedId] ?? [], $list);
}

try {
    $wait = $items ? reserve_plan_review($pdo, (int) current_user_id()) : 0;
} catch (PDOException $e) {
    error_log('TravelEase: plan review limit failure (code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    $wait = -1;
}
if ($wait !== 0) {
    $_SESSION['plan_review_error'][$tripId] = $wait > 0
        ? 'You have used your ' . PLAN_REVIEW_LIMIT_PER_HOUR . ' plan reviews for this hour. Try again in about ' . max(1, (int) ceil($wait / 60)) . ' min.'
        : 'The planning assistant is unavailable right now. Please try again later.';
    header('Location: ' . $back);
    exit;
}

$result = run_plan_review($trip, $items, $warnings);
if ($result['ok']) {
    $_SESSION['plan_review'][$tripId] = ['created' => time(), 'review' => $result['review']];
} else {
    unset($_SESSION['plan_review'][$tripId]);
    $_SESSION['plan_review_error'][$tripId] = $result['error'];
}
header('Location: ' . $back);
exit;
