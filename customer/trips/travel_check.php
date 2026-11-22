<?php
/**
 * FR-20: fetch missing driving-time estimates for a trip's consecutive
 * activities at different saved places, then return to the trip page.
 *
 * Any trip member may run it. Only saved places' coordinates are sent to
 * the routing provider. At most TRAVEL_CHECKS_PER_REQUEST lookups per
 * request, each counted against the account's place-lookup limit
 * (30 per hour, shared with place search). Failures leave the itinerary
 * untouched and simply show fewer warnings.
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/travel_time.php';

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
if (!$tripId) {
    http_response_code(400);
    exit('Invalid trip.');
}

$pdo = get_db_connection();
require_trip_permission($pdo, $tripId, 'view');
$back = '/customer/trips/view.php?id=' . $tripId . '#itinerary';

$stmt = $pdo->prepare(
    'SELECT i.id, i.item_date, i.item_time, i.end_time, tp.latitude AS place_lat, tp.longitude AS place_lon
     FROM itinerary_items i
     JOIN trip_places tp ON tp.id = i.place_id AND tp.trip_id = i.trip_id
     WHERE i.trip_id = :trip_id'
);
$stmt->execute(['trip_id' => $tripId]);
$pairs = itinerary_travel_pairs($stmt->fetchAll());
$unchecked = unchecked_travel_pairs($pairs, cached_travel_estimates($pdo, $pairs));

if (!$unchecked) {
    $_SESSION['travel_notice'][$tripId] = 'Travel times are already checked for this itinerary.';
    header('Location: ' . $back);
    exit;
}

$checked = 0;
$message = null;
try {
    foreach (array_slice($unchecked, 0, TRAVEL_CHECKS_PER_REQUEST) as $pair) {
        if (reserve_place_lookup($pdo, (int) current_user_id()) > 0) {
            $message = 'You have reached the hourly limit for place lookups. Try again later.';
            break;
        }
        if (!claim_provider_slot($pdo)) {
            $message = 'Travel time checks are busy. Try again in a moment.';
            break;
        }
        fetch_and_cache_travel_estimate($pdo, $pair);
        $checked++;
    }
} catch (UnexpectedValueException | JsonException $e) {
    $message = 'Travel times are unavailable right now. Your itinerary is unchanged; try again later.';
} catch (PDOException $e) {
    error_log('TravelEase: travel check database failure (code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    $message = 'Travel times are unavailable right now. Your itinerary is unchanged; try again later.';
}

$remaining = count($unchecked) - $checked;
$summary = $checked === 1 ? 'Checked 1 travel time.' : "Checked $checked travel times.";
if ($remaining > 0 && $message === null) {
    $message = $remaining === 1 ? '1 more to check: press the button again.' : "$remaining more to check: press the button again.";
}
$_SESSION['travel_notice'][$tripId] = trim(($checked > 0 ? $summary . ' ' : '') . ($message ?? ''));
header('Location: ' . $back);
exit;
