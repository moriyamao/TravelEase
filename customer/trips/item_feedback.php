<?php
/**
 * FR-17 activity slice: vote on or comment on an itinerary activity.
 *
 * Actions: vote (up/down; one vote per member per activity, changeable),
 *          clear_vote, comment (1-1000 Unicode characters).
 * Allowed:  trip roles with the 'feedback' permission (owner, editor,
 *          commenter). Viewers get 403, non-members 404, and staff/admin
 *          accounts are stopped by the customer-only route guard.
 *
 * The write runs under the trip lock (lock_trip_and_require_permission),
 * the same lock member changes and itinerary edits/deletes take first, so
 * a removal, demotion or item deletion that commits first is always seen.
 * Feedback never updates itinerary_items, so it cannot move the
 * activity's updated_at (used for the "edited after feedback" label).
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';
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

$tripId = filter_input(INPUT_POST, 'trip_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
// Text fields must be plain strings: an array (e.g. body[]=x) is rejected
// instead of being cast to "Array".
foreach (['action', 'vote', 'body'] as $field) {
    if (isset($_POST[$field]) && !is_string($_POST[$field])) {
        http_response_code(400);
        die('Invalid request.');
    }
}
$action = $_POST['action'] ?? '';
if (!$tripId || !$itemId || !in_array($action, ['vote', 'clear_vote', 'comment'], true)) {
    http_response_code(400);
    die('Invalid request.');
}

$pdo = get_db_connection();

// Non-members get 404; viewers (no 'feedback' permission) get 403.
require_trip_permission($pdo, $tripId, 'feedback');

$back = '/customer/trips/view.php?id=' . $tripId . '#item-' . $itemId;

$vote = null;
$body = '';
if ($action === 'vote') {
    $choice = $_POST['vote'] ?? '';
    if (!in_array($choice, ['up', 'down'], true)) {
        http_response_code(400);
        die('Invalid vote.');
    }
    $vote = $choice === 'up' ? 1 : -1;
} elseif ($action === 'comment') {
    $body = $_POST['body'] ?? '';
    // Cheap byte cap before any regex work: 1000 characters are at most
    // 4000 bytes, plus room for CRLF line breaks.
    $tooLong = strlen($body) > 8000;
    // Browsers submit textarea line breaks as CRLF but count them as one
    // character for maxlength; normalise so the server counts the same.
    $body = str_replace("\r\n", "\n", $body);
    // Trim Unicode whitespace too (e.g. NBSP, ideographic space). PCRE's
    // UTF-8 mode is core PHP; it returns null/false on malformed UTF-8.
    $body = $tooLong ? '' : preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $body);
    $length = $body === null ? false : preg_match_all('/./us', $body);
    if ($length === false) {
        http_response_code(400);
        die('Invalid comment.');
    }
    if ($tooLong || $length < 1 || $length > 1000) {
        $_SESSION['itinerary_errors'] = ['Comments must be between 1 and 1000 characters.'];
        header('Location: ' . $back);
        exit;
    }
}

try {
    $pdo->beginTransaction();
    // Trip row first, then the member's role (re-checked under lock).
    lock_trip_and_require_permission($pdo, $tripId, 'feedback');

    // The activity must belong to this trip. Item deletes take the same
    // trip lock, so the item cannot disappear between here and the write.
    $stmt = $pdo->prepare('SELECT title FROM itinerary_items WHERE id = :id AND trip_id = :trip_id');
    $stmt->execute(['id' => $itemId, 'trip_id' => $tripId]);
    $itemTitle = $stmt->fetchColumn();
    if ($itemTitle === false) {
        $pdo->rollBack();
        http_response_code(404);
        die('Item not found.');
    }

    $me = (int) current_user_id();
    if ($action === 'vote') {
        // Changing a vote keeps created_at (first feedback time).
        $stmt = $pdo->prepare(
            'INSERT INTO itinerary_item_votes (itinerary_item_id, user_id, vote)
             VALUES (:item_id, :user_id, :vote)
             ON DUPLICATE KEY UPDATE vote = :new_vote'
        );
        $stmt->execute(['item_id' => $itemId, 'user_id' => $me, 'vote' => $vote, 'new_vote' => $vote]);
    } elseif ($action === 'clear_vote') {
        $stmt = $pdo->prepare('DELETE FROM itinerary_item_votes WHERE itinerary_item_id = :item_id AND user_id = :user_id');
        $stmt->execute(['item_id' => $itemId, 'user_id' => $me]);
    } else {
        $stmt = $pdo->prepare(
            // FR-34: keep the author's display name with the comment, so it still
            // shows who wrote it if they later delete their account.
            'INSERT INTO itinerary_item_comments (itinerary_item_id, user_id, author_name, body)
             SELECT :item_id, id, name, :body FROM users WHERE id = :user_id'
        );
        $stmt->execute(['item_id' => $itemId, 'user_id' => $me, 'body' => $body]);
        log_trip_activity($pdo, $tripId, 'comment_added', null, (string) $itemTitle);
    }
    $pdo->commit();

    header('Location: ' . $back);
    exit;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TravelEase: itinerary feedback failed: ' . $e->getMessage());
    http_response_code(500);
    die('Something went wrong. Please try again.');
}
