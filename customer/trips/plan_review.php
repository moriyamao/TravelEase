<?php
/**
 * FR-28: ask the planning assistant to review a trip (owners and editors).
 * Results are kept in the session for 30 minutes with a random review ID and
 * a server-only fingerprint of the trip data they were based on; nothing in
 * the trip changes here. action=clear removes them, but only when the form's
 * review ID matches the current review (an old tab cannot clear a newer one).
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
    $current = $_SESSION['plan_review'][$tripId]['id'] ?? null;
    $posted = $_POST['review_id'] ?? null;
    if (is_string($current) && is_string($posted) && hash_equals($current, $posted)) {
        unset($_SESSION['plan_review'][$tripId]);
    } else {
        $_SESSION['plan_review_error'][$tripId] = 'Those suggestions were already replaced or cleared.';
    }
    header('Location: ' . $back);
    exit;
}

$source = plan_review_source($pdo, $tripId);
$trip = $source['trip'];
$items = $source['items'];
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
    $_SESSION['plan_review'][$tripId] = [
        'id' => bin2hex(random_bytes(16)),
        'created' => time(),
        'fingerprint' => plan_review_fingerprint($source),
        'review' => $result['review'],
    ];
} else {
    unset($_SESSION['plan_review'][$tripId]);
    $_SESSION['plan_review_error'][$tripId] = $result['error'];
}
header('Location: ' . $back);
exit;
