<?php
/**
 * FR-29: apply ONE schedule suggestion from the current plan review, after
 * the owner or editor presses Apply. The change comes from the session (the
 * page only sends its number), is re-validated against the trip as it is now
 * under the trip lock, and is refused if the activity changed since the review.
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_activity.php';
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
$index = filter_var($_POST['suggestion'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 20]]);
if (!$tripId || $index === false || $index === null) {
    http_response_code(400);
    exit('Invalid request.');
}

$pdo = get_db_connection();
require_trip_permission($pdo, $tripId, 'edit_itinerary');
$back = '/customer/trips/view.php?id=' . $tripId . '#plan-review';
$review = $_SESSION['plan_review'][$tripId] ?? null;
$suggestion = (is_array($review) && $review['created'] > time() - PLAN_REVIEW_TTL)
    ? ($review['review']['schedule'][$index] ?? null) : null;

/** Record the outcome, drop this suggestion so it cannot be applied twice, and go back. */
function plan_apply_finish(int $tripId, int $index, string $key, string $message, string $back): void
{
    $_SESSION[$key][$tripId] = $message;
    unset($_SESSION['plan_review'][$tripId]['review']['schedule'][$index]);
    header('Location: ' . $back);
    exit;
}

if (!is_array($suggestion)) {
    $_SESSION['plan_review_error'][$tripId] = 'That suggestion has expired or was already used. Ask for a new review.';
    header('Location: ' . $back);
    exit;
}

try {
    $pdo->beginTransaction();
    lock_trip_and_require_permission($pdo, $tripId, 'edit_itinerary');
    $stmt = $pdo->prepare('SELECT start_date, end_date FROM trips WHERE id = :id');
    $stmt->execute(['id' => $tripId]);
    $trip = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT id, title, item_date, item_time, end_time, sort_order FROM itinerary_items
                           WHERE id = :id AND trip_id = :trip FOR UPDATE');
    $stmt->execute(['id' => $suggestion['item_id'], 'trip' => $tripId]);
    $item = $stmt->fetch();
    $current = $item ? [
        $item['item_date'],
        $item['item_time'] !== null ? substr($item['item_time'], 0, 5) : '',
        $item['end_time'] !== null ? substr($item['end_time'], 0, 5) : '',
    ] : null;
    if ($current !== [$suggestion['from']['date'], $suggestion['from']['time'], $suggestion['from']['end']]) {
        $pdo->rollBack();
        plan_apply_finish($tripId, $index, 'plan_review_error',
            'That activity changed or was removed after the review, so the suggestion was not applied.', $back);
    }
    $to = $suggestion['to'];
    if (validate_item_date($to['date'], $trip['start_date'], $trip['end_date']) !== null
        || validate_item_time($to['time']) !== null || validate_item_end_time($to['time'], $to['end']) !== null) {
        $pdo->rollBack();
        plan_apply_finish($tripId, $index, 'plan_review_error',
            'That suggestion no longer fits the trip dates, so it was not applied.', $back);
    }
    // Moving to another day places it by that day's order, as the edit form does.
    $sortOrder = $to['date'] === $item['item_date'] ? (int) $item['sort_order']
        : itinerary_next_sort_order($pdo, $tripId, $to['date'], (int) $item['id']);
    $stmt = $pdo->prepare('UPDATE itinerary_items
                           SET item_date = :item_date, item_time = :item_time, end_time = :end_time, sort_order = :sort_order
                           WHERE id = :id AND trip_id = :trip');
    $stmt->execute([
        'item_date' => $to['date'], 'item_time' => $to['time'] !== '' ? $to['time'] : null,
        'end_time' => $to['end'] !== '' ? $to['end'] : null, 'sort_order' => $sortOrder,
        'id' => $item['id'], 'trip' => $tripId,
    ]);
    log_trip_activity($pdo, $tripId, 'activity_updated', null, (string) $item['title']);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TravelEase: plan suggestion apply failed (code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    $_SESSION['plan_review_error'][$tripId] = 'Could not apply the suggestion. Nothing was changed.';
    header('Location: ' . $back);
    exit;
}
plan_apply_finish($tripId, $index, 'plan_review_notice', 'Updated “' . $item['title'] . '”.', $back);
