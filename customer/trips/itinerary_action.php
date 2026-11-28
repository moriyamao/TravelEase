<?php
/**
 * FR-19: complete / reopen an activity, and move it up or down within
 * its day. Owners and editors only ('edit_itinerary').
 *
 * Every change runs under the trip lock (the same lock itinerary edits,
 * deletes and member changes take first), so concurrent reorders are
 * serialized. These updates keep updated_at as it was: completing or
 * reordering is not an edit of the activity, so FR-17's "Edited after
 * feedback" label is unaffected. Comments, votes and history reference
 * the activity by id and are untouched.
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/itinerary_validation.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_activity.php';

require_role(['customer']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    die('Method not allowed.');
}

if (!csrf_verify(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    http_response_code(403);
    die('Your session expired. Please go back and try again.');
}

$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
if (!$itemId || !in_array($action, ['complete', 'reopen', 'move_up', 'move_down'], true)) {
    http_response_code(400);
    die('Invalid request.');
}

$pdo = get_db_connection();

// Find the activity's trip, then authorize the trip role. Non-members get
// the same 404 as a missing activity.
$stmt = $pdo->prepare('SELECT trip_id FROM itinerary_items WHERE id = :id');
$stmt->execute(['id' => $itemId]);
$tripId = $stmt->fetchColumn();
if ($tripId === false) {
    http_response_code(404);
    die('Item not found.');
}
$tripId = (int) $tripId;
require_trip_permission($pdo, $tripId, 'edit_itinerary');

$back = '/customer/trips/view.php?id=' . $tripId . '#item-' . $itemId;

try {
    $pdo->beginTransaction();
    lock_trip_and_require_permission($pdo, $tripId, 'edit_itinerary');

    $stmt = $pdo->prepare(
        'SELECT title, item_date FROM itinerary_items WHERE id = :id AND trip_id = :trip_id FOR UPDATE'
    );
    $stmt->execute(['id' => $itemId, 'trip_id' => $tripId]);
    $item = $stmt->fetch();
    if (!$item) {
        $pdo->rollBack();
        http_response_code(404);
        die('Item not found.');
    }

    if ($action === 'complete' || $action === 'reopen') {
        // Conditional update: replaying the same request changes nothing
        // and logs nothing.
        if ($action === 'complete') {
            $stmt = $pdo->prepare(
                'UPDATE itinerary_items
                 SET completed_at = UTC_TIMESTAMP(), completed_by = :user_id, updated_at = updated_at
                 WHERE id = :id AND trip_id = :trip_id AND completed_at IS NULL'
            );
            $stmt->execute(['user_id' => (int) current_user_id(), 'id' => $itemId, 'trip_id' => $tripId]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE itinerary_items
                 SET completed_at = NULL, completed_by = NULL, updated_at = updated_at
                 WHERE id = :id AND trip_id = :trip_id AND completed_at IS NOT NULL'
            );
            $stmt->execute(['id' => $itemId, 'trip_id' => $tripId]);
        }
        if ($stmt->rowCount() === 1) {
            log_trip_activity($pdo, $tripId, $action === 'complete' ? 'activity_completed' : 'activity_reopened', null, $item['title']);
        }
    } else {
        // Reorder within the activity's own trip and day: read the day in
        // display order, swap with the neighbour, renumber the day 1..n.
        $stmt = $pdo->prepare(
            'SELECT id FROM itinerary_items
             WHERE trip_id = :trip_id AND item_date = :item_date
             ORDER BY ' . ITINERARY_DAY_ORDER_SQL . ' FOR UPDATE'
        );
        $stmt->execute(['trip_id' => $tripId, 'item_date' => $item['item_date']]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $pos = array_search($itemId, $ids, true);
        $target = $action === 'move_up' ? $pos - 1 : $pos + 1;
        if ($pos !== false && $target >= 0 && $target < count($ids)) {
            [$ids[$pos], $ids[$target]] = [$ids[$target], $ids[$pos]];
            $update = $pdo->prepare(
                'UPDATE itinerary_items SET sort_order = :sort_order, updated_at = updated_at
                 WHERE id = :id AND trip_id = :trip_id'
            );
            foreach ($ids as $index => $id) {
                $update->execute(['sort_order' => $index + 1, 'id' => $id, 'trip_id' => $tripId]);
            }
            log_trip_activity($pdo, $tripId, $action === 'move_up' ? 'activity_moved_up' : 'activity_moved_down', null, $item['title']);
        }
        // Already first (up) or last (down): nothing to change or log.
    }

    $pdo->commit();
    header('Location: ' . $back);
    exit;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TravelEase: itinerary action failed (database code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    http_response_code(500);
    die('Something went wrong. Please try again.');
}
