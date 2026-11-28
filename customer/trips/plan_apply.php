<?php
/**
 * FR-29: apply ONE schedule suggestion from the current plan review, after
 * the owner or editor presses Apply.
 *
 * - The form must carry the current review's random ID; a stale tab from an
 *   older review is refused without changing anything (and without touching
 *   the newer review).
 * - The change itself comes from the session (the page only sends its number).
 * - Under the trip lock and with the trip's activities locked, the trip data
 *   is fingerprinted again and must match the fingerprint taken when the
 *   review was made; a change since to the relevant trip fields (destination,
 *   dates, time zone, budget) or to any activity's times, title, category,
 *   place, costs, completion or order, even within the same second, refuses
 *   the apply and discards the whole review.
 * - After a successful apply the review is discarded too, because the plan it
 *   was based on has changed. Ask for a new review for more suggestions.
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
$postedReviewId = $_POST['review_id'] ?? null;
if (!$tripId || $index === false || $index === null || !is_string($postedReviewId)) {
    http_response_code(400);
    exit('Invalid request.');
}

$pdo = get_db_connection();
require_trip_permission($pdo, $tripId, 'edit_itinerary');
$back = '/customer/trips/view.php?id=' . $tripId . '#plan-review';

$review = $_SESSION['plan_review'][$tripId] ?? null;
if (!is_array($review) || !is_string($review['id'] ?? null) || !hash_equals($review['id'], $postedReviewId)) {
    // Old tab or already used: leave any newer review exactly as it is.
    $_SESSION['plan_review_error'][$tripId] = 'That suggestion is from an older or finished review. Use the current suggestions or ask for a new review.';
    header('Location: ' . $back);
    exit;
}
$suggestion = $review['created'] > time() - PLAN_REVIEW_TTL ? ($review['review']['schedule'][$index] ?? null) : null;
if (!is_array($suggestion)) {
    $_SESSION['plan_review_error'][$tripId] = 'That suggestion has expired. Ask for a new review.';
    header('Location: ' . $back);
    exit;
}

/** Record the outcome, discard this review (its basis has changed or it was used) and go back. */
function plan_apply_finish(int $tripId, string $key, string $message, string $back): void
{
    $_SESSION[$key][$tripId] = $message;
    unset($_SESSION['plan_review'][$tripId]);
    header('Location: ' . $back);
    exit;
}

try {
    $pdo->beginTransaction();
    lock_trip_and_require_permission($pdo, $tripId, 'edit_itinerary');
    $source = plan_review_source($pdo, $tripId, true);
    if (!hash_equals((string) ($review['fingerprint'] ?? ''), plan_review_fingerprint($source))) {
        $pdo->rollBack();
        plan_apply_finish($tripId, 'plan_review_error',
            'The trip changed after the review, so its suggestions were discarded. Ask for a new review.', $back);
    }
    $trip = $source['trip'];
    $item = null;
    foreach ($source['items'] as $row) {
        if ((int) $row['id'] === (int) $suggestion['item_id']) {
            $item = $row;
            break;
        }
    }
    $to = $suggestion['to'];
    if ($item === null
        || validate_item_date($to['date'], $trip['start_date'], $trip['end_date']) !== null
        || validate_item_time($to['time']) !== null || validate_item_end_time($to['time'], $to['end']) !== null) {
        $pdo->rollBack();
        plan_apply_finish($tripId, 'plan_review_error', 'That suggestion no longer fits the trip, so it was not applied.', $back);
    }
    // Moving to another day places it by that day's order, as the edit form does.
    $sortOrder = $to['date'] === $item['item_date'] ? (int) $item['sort_order']
        : itinerary_next_sort_order($pdo, $tripId, $to['date'], (int) $item['id']);
    $stmt = $pdo->prepare('UPDATE itinerary_items
                           SET item_date = :item_date, item_time = :item_time, end_time = :end_time,
                               sort_order = :sort_order, version = version + 1
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
plan_apply_finish($tripId, 'plan_review_notice', 'Updated “' . $item['title'] . '”. Ask for a new review for more suggestions.', $back);
