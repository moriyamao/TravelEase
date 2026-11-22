<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_recommendations.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/itinerary_validation.php';
require_once __DIR__ . '/../../includes/itinerary_schedule.php';
require_once __DIR__ . '/../../includes/trip_references.php';
require_once __DIR__ . '/../../includes/trip_progress.php';

require_role(['customer']);

$tripId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($tripId === false || $tripId === null) {
    http_response_code(400);
    die('Invalid trip.');
}

$pdo = get_db_connection();
$tripRole = require_trip_permission($pdo, $tripId, 'view');
$canEditItinerary = trip_role_can($tripRole, 'edit_itinerary');
$canManageTrip = trip_role_can($tripRole, 'manage_trip');
$canGiveFeedback = trip_role_can($tripRole, 'feedback');

// FR-26: progress counts only the references this member may see.
$visibleReferences = list_trip_references($pdo, $tripId, $tripRole, (int) $_SESSION['user_id']);
$progress = get_trip_progress($pdo, $tripId, $visibleReferences);

// Access was authorized above through the trip role, so ?id= values
// for trips the user is not a member of never reach this query (§26).
$stmt = $pdo->prepare(
    'SELECT id, name, destination, start_date, end_date, status,
            budget_currency, budget_amount, notes, created_at
     FROM trips
     WHERE id = :id
     LIMIT 1'
);
$stmt->execute([
    'id'      => $tripId,
]);
$trip = $stmt->fetch();

if (!$trip) {
    http_response_code(404);
    die('Trip not found.');
}

$recommendations = [];
$recommendationError = null;
$interests = '';
$pace = 'balanced';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recommend' && $canEditItinerary) {
    $interests = trim((string) ($_POST['interests'] ?? ''));
    $pace = (string) ($_POST['pace'] ?? 'balanced');
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $recommendationError = 'Your session expired. Reload the page and try again.';
    } elseif (mb_strlen($interests) > 120 || !in_array($pace, ['relaxed', 'balanced', 'packed'], true)) {
        $recommendationError = 'Please enter up to 120 characters of interests and choose a valid pace.';
    } elseif (($_SESSION['last_trip_recommendation_at'] ?? 0) > time() - 30) {
        $recommendationError = 'Please wait a moment before requesting more ideas.';
    } else {
        $_SESSION['last_trip_recommendation_at'] = time();
        $result = trip_recommendations($trip, $interests, $pace);
        $recommendations = $result['ideas'];
        $recommendationError = $result['error'];
    }
}

// Itinerary items for this trip -- membership was already authorized
// above, so this second query is safe to filter by trip_id alone.
// FR-17 feedback rides along in the same query (no extra round trip):
// - votes count only members who can vote now (the owner, editors and
//   commenters), so removed or demoted members' votes drop out;
// - votes and comments are aggregated in separate subqueries, so neither
//   total is multiplied by the other;
// - edited_after_feedback compares the activity's updated_at with the
//   EARLIEST vote or comment creation (feedback never touches the item).
$stmt = $pdo->prepare(
    'SELECT i.id, i.item_date, i.item_time, i.end_time, i.title, i.location, i.notes,
            i.estimated_cost, i.actual_cost, i.category, i.assignee_id, i.completed_at,
            au.name AS assignee_name, cu.name AS completed_by_name,
            tp.name AS place_name, tp.latitude AS place_lat, tp.longitude AS place_lon,
            COALESCE(v.up_votes, 0) AS up_votes,
            COALESCE(v.down_votes, 0) AS down_votes,
            COALESCE(c.comment_count, 0) AS comment_count,
            mv.vote AS my_vote,
            COALESCE(i.updated_at > LEAST(COALESCE(v.first_vote_at, c.first_comment_at),
                                          COALESCE(c.first_comment_at, v.first_vote_at)), 0)
                AS edited_after_feedback
     FROM itinerary_items i
     LEFT JOIN (
         SELECT iv.itinerary_item_id,
                SUM(iv.vote = 1 AND (iv.user_id = vt.user_id OR COALESCE(vm.role IN (\'editor\', \'commenter\'), 0))) AS up_votes,
                SUM(iv.vote = -1 AND (iv.user_id = vt.user_id OR COALESCE(vm.role IN (\'editor\', \'commenter\'), 0))) AS down_votes,
                MIN(iv.created_at) AS first_vote_at
         FROM itinerary_item_votes iv
         JOIN itinerary_items vi ON vi.id = iv.itinerary_item_id
         JOIN trips vt ON vt.id = vi.trip_id
         LEFT JOIN trip_memberships vm ON vm.trip_id = vt.id AND vm.user_id = iv.user_id
         WHERE vi.trip_id = :vote_trip_id
         GROUP BY iv.itinerary_item_id
     ) v ON v.itinerary_item_id = i.id
     LEFT JOIN (
         SELECT ic.itinerary_item_id,
                COUNT(*) AS comment_count,
                MIN(ic.created_at) AS first_comment_at
         FROM itinerary_item_comments ic
         JOIN itinerary_items ci ON ci.id = ic.itinerary_item_id
         WHERE ci.trip_id = :comment_trip_id
         GROUP BY ic.itinerary_item_id
     ) c ON c.itinerary_item_id = i.id
     LEFT JOIN itinerary_item_votes mv ON mv.itinerary_item_id = i.id AND mv.user_id = :me
     LEFT JOIN users au ON au.id = i.assignee_id
     LEFT JOIN users cu ON cu.id = i.completed_by
     LEFT JOIN trip_places tp ON tp.id = i.place_id AND tp.trip_id = i.trip_id
     WHERE i.trip_id = :trip_id
     ORDER BY i.item_date ASC, i.sort_order ASC, i.item_time ASC, i.id ASC'
);
$stmt->execute([
    'vote_trip_id'    => $tripId,
    'comment_trip_id' => $tripId,
    'me'              => (int) current_user_id(),
    'trip_id'         => $tripId,
]);
$items = $stmt->fetchAll();

// Comments for every activity in one query, skipped when there are none.
$commentsByItem = [];
if (array_sum(array_column($items, 'comment_count')) > 0) {
    $stmt = $pdo->prepare(
        'SELECT ic.itinerary_item_id, ic.body, ic.created_at, u.name
         FROM itinerary_item_comments ic
         JOIN itinerary_items i ON i.id = ic.itinerary_item_id
         JOIN users u ON u.id = ic.user_id
         WHERE i.trip_id = :trip_id
         ORDER BY ic.created_at ASC, ic.id ASC'
    );
    $stmt->execute(['trip_id' => $tripId]);
    foreach ($stmt->fetchAll() as $comment) {
        $commentsByItem[(int) $comment['itinerary_item_id']][] = $comment;
    }
}

// FR-20: non-blocking schedule warnings (overlaps, activities outside the
// trip dates), computed from the rows above with no extra query.
$scheduleWarnings = itinerary_schedule_warnings($items, $trip['start_date'], $trip['end_date']);
$itemTitles = array_column($items, 'title', 'id');

// Group items by date for the day-by-day display.
$itemsByDate = [];
$totalEstimated = 0.0;
$totalActual    = 0.0;
foreach ($items as $item) {
    $itemsByDate[$item['item_date']][] = $item;
    if ($item['estimated_cost'] !== null) {
        $totalEstimated += (float) $item['estimated_cost'];
    }
    if ($item['actual_cost'] !== null) {
        $totalActual += (float) $item['actual_cost'];
    }
}

// Members are visible to every member so each knows who can see or
// change the trip. The owner comes from trips.user_id (canonical).
$stmt = $pdo->prepare(
    'SELECT u.id, u.name, \'owner\' AS role, u.role AS system_role
     FROM trips t JOIN users u ON u.id = t.user_id
     WHERE t.id = :owner_trip_id
     UNION ALL
     SELECT u.id, u.name, m.role, u.role AS system_role
     FROM trip_memberships m
     JOIN trips t ON t.id = m.trip_id
     JOIN users u ON u.id = m.user_id
     WHERE m.trip_id = :member_trip_id AND m.user_id <> t.user_id AND m.role <> \'owner\''
);
$stmt->execute(['owner_trip_id' => $tripId, 'member_trip_id' => $tripId]);
$members = $stmt->fetchAll();
// FR-19: current members (for "no longer on this trip" labels) and the
// people who can be newly assigned (customer accounts only).
$memberIds = array_map('intval', array_column($members, 'id'));
$assignableMembers = array_values(array_filter($members, fn($m) => $m['system_role'] === 'customer'));

$pendingInvites = [];
if ($canManageTrip) {
    $stmt = $pdo->prepare(
        'SELECT i.id, i.issue_no, u.email, i.role, i.expires_at
         FROM trip_invitations i JOIN users u ON u.id = i.invitee_id
         WHERE i.trip_id = :trip_id AND i.status = \'pending\' AND i.expires_at > UTC_TIMESTAMP()
         ORDER BY i.created_at DESC'
    );
    $stmt->execute(['trip_id' => $tripId]);
    $pendingInvites = $stmt->fetchAll();
}

$itineraryErrors = $_SESSION['itinerary_errors'] ?? [];
unset($_SESSION['itinerary_errors']);
$inviteNotice = $_SESSION['trip_invite_notice'] ?? null;
unset($_SESSION['trip_invite_notice']);
$inviteError = $_SESSION['trip_invite_error'] ?? null;
unset($_SESSION['trip_invite_error']);
$memberNotice = $_SESSION['trip_member_notice'] ?? null;
unset($_SESSION['trip_member_notice']);
$memberError = $_SESSION['trip_member_error'] ?? null;
unset($_SESSION['trip_member_error']);

$statusLabels = [
    'planning'  => 'Planning',
    'confirmed' => 'Confirmed',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($trip['name'], ENT_QUOTES, 'UTF-8') ?> — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/references.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
    <link rel="stylesheet" href="/assets/css/chat-widget.css">
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1>My Trips</h1>
            <a href="/customer/dashboard.php" class="logout-link">Back to My Trips</a>
            <a href="/customer/trips/places.php?id=<?= (int) $tripId ?>" class="logout-link">Places &amp; map</a>
            <a href="/customer/trips/expenses.php?id=<?= (int) $tripId ?>" class="logout-link">Expenses</a>
            <a href="/customer/trips/references.php?id=<?= (int) $tripId ?>" class="logout-link">Booking references</a>
            <a href="/customer/trips/history.php?id=<?= (int) $tripId ?>" class="logout-link">Activity history</a>
        </header>

        <section class="trip-hero">
            <div>
                <p class="eyebrow">Journey dossier &middot; <span class="status-badge status-<?= htmlspecialchars($trip['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabels[$trip['status']] ?? $trip['status'], ENT_QUOTES, 'UTF-8') ?></span></p>
                <h1><?= htmlspecialchars($trip['name'], ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="trip-hero-destination"><?= htmlspecialchars($trip['destination'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <p class="trip-hero-date"><?= htmlspecialchars(date('M j, Y', strtotime($trip['start_date'])), ENT_QUOTES, 'UTF-8') ?><br>to <?= htmlspecialchars(date('M j, Y', strtotime($trip['end_date'])), ENT_QUOTES, 'UTF-8') ?></p>
        </section>

        <div class="trip-layout">

        <section class="itinerary-section" id="itinerary">
            <h2>Itinerary</h2>

            <?php if ($canEditItinerary): ?>
            <section class="trip-ideas" aria-labelledby="trip-ideas-title">
                <div class="trip-ideas-heading">
                    <div>
                        <p class="eyebrow">Planning inspiration</p>
                        <h3 id="trip-ideas-title">Ideas for <?= htmlspecialchars($trip['destination'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <p>Generate a few AI suggestions, edit one, then add it to your itinerary. Place details may be inaccurate; check them before you go. Your destination, dates, budget, interests, and pace are sent to the AI service for this request.</p>
                    </div>
                    <form class="trip-ideas-request" method="post" action="/customer/trips/view.php?id=<?= (int) $trip['id'] ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="recommend">
                        <label for="trip-interests">What do you enjoy?</label>
                        <input id="trip-interests" name="interests" maxlength="120" placeholder="Art, food, nature..." value="<?= htmlspecialchars($interests, ENT_QUOTES, 'UTF-8') ?>">
                        <label for="trip-pace">Travel pace</label>
                        <select id="trip-pace" name="pace">
                            <option value="relaxed" <?= $pace === 'relaxed' ? 'selected' : '' ?>>Relaxed</option>
                            <option value="balanced" <?= $pace === 'balanced' ? 'selected' : '' ?>>Balanced</option>
                            <option value="packed" <?= $pace === 'packed' ? 'selected' : '' ?>>Packed</option>
                        </select>
                        <button type="submit" class="btn-primary">Suggest activities</button>
                    </form>
                </div>
                <?php if ($recommendationError): ?>
                    <p class="form-errors" role="alert"><?= htmlspecialchars($recommendationError, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <?php if ($recommendations): ?>
                    <div class="trip-ideas-grid">
                        <?php foreach ($recommendations as $index => $idea): ?>
                            <form class="trip-idea-card" method="post" action="/customer/trips/itinerary_add.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                <p class="eyebrow">AI suggestion <?= $index + 1 ?></p>
                                <label for="idea-title-<?= $index ?>">Activity</label>
                                <input id="idea-title-<?= $index ?>" name="title" maxlength="150" required value="<?= htmlspecialchars($idea['title'], ENT_QUOTES, 'UTF-8') ?>">
                                <label for="idea-date-<?= $index ?>">Day</label>
                                <input id="idea-date-<?= $index ?>" type="date" name="item_date" min="<?= htmlspecialchars($trip['start_date'], ENT_QUOTES, 'UTF-8') ?>" max="<?= htmlspecialchars($trip['end_date'], ENT_QUOTES, 'UTF-8') ?>" required value="<?= htmlspecialchars($idea['date'], ENT_QUOTES, 'UTF-8') ?>">
                                <label for="idea-location-<?= $index ?>">Place</label>
                                <input id="idea-location-<?= $index ?>" name="location" maxlength="150" value="<?= htmlspecialchars($idea['location'], ENT_QUOTES, 'UTF-8') ?>">
                                <label for="idea-notes-<?= $index ?>">Why this place?</label>
                                <textarea id="idea-notes-<?= $index ?>" name="notes" rows="2" maxlength="2000"><?= htmlspecialchars($idea['notes'], ENT_QUOTES, 'UTF-8') ?></textarea>
                                <button type="submit">Add to itinerary</button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if (!empty($itineraryErrors)): ?>
                <ul class="form-errors" role="alert">
                    <?php foreach ($itineraryErrors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($scheduleWarnings): ?>
                <p class="schedule-summary" role="status"><?= count($scheduleWarnings) === 1 ? '1 activity has a schedule warning.' : count($scheduleWarnings) . ' activities have schedule warnings.' ?> Warnings do not block saving.</p>
            <?php endif; ?>

            <?php if (empty($itemsByDate)): ?>
                <p class="empty-state">No itinerary items yet.<?= $canEditItinerary ? ' Add your first stop below.' : '' ?></p>
            <?php else: ?>
                <?php foreach ($itemsByDate as $date => $dayItems): ?>
                    <div class="itinerary-day">
                        <h3 class="itinerary-day-heading"><?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?></h3>
                        <ul class="itinerary-list">
                            <?php foreach ($dayItems as $dayIndex => $item): ?>
                                <li class="itinerary-item<?= $item['completed_at'] !== null ? ' is-done' : '' ?>" id="item-<?= (int) $item['id'] ?>">
                                    <div class="itinerary-item-main">
                                        <?php if (!empty($item['item_time'])): ?>
                                            <span class="itinerary-time"><?= htmlspecialchars(substr($item['item_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                        <span class="itinerary-title"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (!empty($item['location'])): ?>
                                            <span class="itinerary-location">— <?= htmlspecialchars($item['location'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="itinerary-meta">
                                        <?php if (!empty($item['item_time']) && !empty($item['end_time'])): ?>
                                            <span><?= htmlspecialchars(substr($item['item_time'], 0, 5) . '–' . substr($item['end_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                        <span class="itinerary-category"><?= htmlspecialchars(ITINERARY_CATEGORIES[$item['category'] ?? ''] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if ($item['place_name'] !== null): ?>
                                            <span>At <a href="/customer/trips/places.php?id=<?= (int) $tripId ?>#activity-<?= (int) $item['id'] ?>"><?= htmlspecialchars($item['place_name'], ENT_QUOTES, 'UTF-8') ?></a><?php if ($item['place_lat'] !== null && $item['place_lon'] !== null): ?> &middot; <a href="https://www.openstreetmap.org/directions?route=%3B<?= rawurlencode($item['place_lat'] . ',' . $item['place_lon']) ?>" target="_blank" rel="noopener noreferrer">Directions</a><?php endif; ?></span>
                                        <?php endif; ?>
                                        <?php if ($item['assignee_id'] !== null): ?>
                                            <span>Assigned to <?= htmlspecialchars($item['assignee_name'] ?? 'a deleted user', ENT_QUOTES, 'UTF-8') ?><?= in_array((int) $item['assignee_id'], $memberIds, true) ? '' : ' (no longer on this trip)' ?></span>
                                        <?php endif; ?>
                                        <?php if ($item['completed_at'] !== null): ?>
                                            <span class="itinerary-done">Done<?= $item['completed_by_name'] !== null ? ' · by ' . htmlspecialchars($item['completed_by_name'], ENT_QUOTES, 'UTF-8') : '' ?></span>
                                        <?php endif; ?>
                                    </p>
                                    <?php if (!empty($scheduleWarnings[(int) $item['id']])): ?>
                                        <ul class="itinerary-warnings">
                                            <?php foreach ($scheduleWarnings[(int) $item['id']] as $warning): ?>
                                                <?php if ($warning['type'] === 'outside_trip'): ?>
                                                    <li>Outside the trip dates. Move it to a day within the trip.</li>
                                                <?php elseif ($warning['type'] === 'overlap'): ?>
                                                    <li>Overlaps with &ldquo;<?= htmlspecialchars((string) ($itemTitles[$warning['other_id']] ?? 'another activity'), ENT_QUOTES, 'UTF-8') ?>&rdquo; (<?= htmlspecialchars($warning['other_start'] . '–' . $warning['other_end'], ENT_QUOTES, 'UTF-8') ?>)</li>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <?php if ($item['estimated_cost'] !== null || $item['actual_cost'] !== null): ?>
                                        <p class="itinerary-cost">
                                            <?php if ($item['estimated_cost'] !== null): ?>
                                                Est. <?= htmlspecialchars($trip['budget_currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $item['estimated_cost'], 2) ?>
                                            <?php endif; ?>
                                            <?php if ($item['actual_cost'] !== null): ?>
                                                <?= $item['estimated_cost'] !== null ? ' · ' : '' ?>Actual <?= htmlspecialchars($trip['budget_currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $item['actual_cost'], 2) ?>
                                            <?php endif; ?>
                                        </p>
                                    <?php endif; ?>
                                    <?php if (!empty($item['notes'])): ?>
                                        <p class="itinerary-notes"><?= nl2br(htmlspecialchars($item['notes'], ENT_QUOTES, 'UTF-8')) ?></p>
                                    <?php endif; ?>
                                    <?php if ($canEditItinerary): ?>
                                    <div class="itinerary-item-actions">
                                        <a href="/customer/trips/itinerary_edit.php?id=<?= (int) $item['id'] ?>">Edit</a>
                                        <form method="post" action="/customer/trips/itinerary_action.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="action" value="<?= $item['completed_at'] === null ? 'complete' : 'reopen' ?>">
                                            <button type="submit"><?= $item['completed_at'] === null ? 'Mark done' : 'Reopen' ?></button>
                                        </form>
                                        <?php if ($dayIndex > 0): ?>
                                        <form method="post" action="/customer/trips/itinerary_action.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="action" value="move_up">
                                            <button type="submit">Move up</button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if ($dayIndex < count($dayItems) - 1): ?>
                                        <form method="post" action="/customer/trips/itinerary_action.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="action" value="move_down">
                                            <button type="submit">Move down</button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="post" action="/customer/trips/delete_itinerary.php"
                                              onsubmit="return confirm('Delete this item?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                            <button type="submit" class="link-danger">Delete</button>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                    <?php
                                        $itemId = (int) $item['id'];
                                        $myVote = $item['my_vote'] === null ? 0 : (int) $item['my_vote'];
                                        $itemComments = $commentsByItem[$itemId] ?? [];
                                    ?>
                                    <div class="itinerary-feedback">
                                        <p class="itinerary-votes">
                                            <?= (int) $item['up_votes'] ?> up &middot; <?= (int) $item['down_votes'] ?> down
                                            <?php if ((int) $item['edited_after_feedback'] === 1): ?>
                                                <span class="feedback-edited">Edited after feedback</span>
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($canGiveFeedback): ?>
                                        <div class="feedback-vote-actions">
                                            <?php foreach (['up' => 1, 'down' => -1] as $choice => $value): ?>
                                                <form method="post" action="/customer/trips/item_feedback.php">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                                    <input type="hidden" name="item_id" value="<?= $itemId ?>">
                                                    <input type="hidden" name="action" value="vote">
                                                    <input type="hidden" name="vote" value="<?= $choice ?>">
                                                    <button type="submit" aria-pressed="<?= $myVote === $value ? 'true' : 'false' ?>"><?= $choice === 'up' ? 'Vote up' : 'Vote down' ?></button>
                                                </form>
                                            <?php endforeach; ?>
                                            <?php if ($myVote !== 0): ?>
                                                <form method="post" action="/customer/trips/item_feedback.php">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                                    <input type="hidden" name="item_id" value="<?= $itemId ?>">
                                                    <input type="hidden" name="action" value="clear_vote">
                                                    <button type="submit">Clear my vote</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($itemComments): ?>
                                            <ul class="feedback-comments">
                                                <?php foreach ($itemComments as $comment): ?>
                                                    <li>
                                                        <p class="feedback-comment-meta"><?= htmlspecialchars($comment['name'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars(gmdate('M j, Y H:i', strtotime($comment['created_at'] . ' UTC')), ENT_QUOTES, 'UTF-8') ?> UTC</p>
                                                        <p class="feedback-comment-body"><?= htmlspecialchars($comment['body'], ENT_QUOTES, 'UTF-8') ?></p>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                        <?php if ($canGiveFeedback): ?>
                                            <form class="feedback-comment-form" method="post" action="/customer/trips/item_feedback.php">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                                    <input type="hidden" name="item_id" value="<?= $itemId ?>">
                                                <input type="hidden" name="action" value="comment">
                                                <label for="comment-<?= $itemId ?>">Add a comment</label>
                                                <textarea id="comment-<?= $itemId ?>" name="body" rows="2" maxlength="1000" required></textarea>
                                                <button type="submit">Post comment</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($canEditItinerary): ?>
            <details class="add-item-form">
                <summary>+ Add itinerary item</summary>
                <form method="post" action="/customer/trips/itinerary_add.php" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">

                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" required maxlength="150" placeholder="e.g. Visit the museum">

                    <div class="form-row">
                        <div>
                            <label for="item_date">Date</label>
                            <input type="date" id="item_date" name="item_date" required
                                   min="<?= htmlspecialchars($trip['start_date'], ENT_QUOTES, 'UTF-8') ?>"
                                   max="<?= htmlspecialchars($trip['end_date'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div>
                            <label for="item_time">Start time (optional)</label>
                            <input type="time" id="item_time" name="item_time">
                        </div>
                        <div>
                            <label for="end_time">End time (optional)</label>
                            <input type="time" id="end_time" name="end_time">
                        </div>
                    </div>

                    <label for="location">Location (optional)</label>
                    <input type="text" id="location" name="location" maxlength="150">

                    <div class="form-row">
                        <div>
                            <label for="category">Category (optional)</label>
                            <select id="category" name="category">
                                <option value="">Uncategorized</option>
                                <?php foreach (ITINERARY_CATEGORIES as $value => $label): ?>
                                    <option value="<?= $value ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="assignee_id">Assigned to (optional)</label>
                            <select id="assignee_id" name="assignee_id">
                                <option value="">Unassigned</option>
                                <?php foreach ($assignableMembers as $person): ?>
                                    <option value="<?= (int) $person['id'] ?>"><?= htmlspecialchars($person['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div>
                            <label for="estimated_cost">Estimated cost in <?= htmlspecialchars($trip['budget_currency'], ENT_QUOTES, 'UTF-8') ?> (optional)</label>
                            <input type="text" inputmode="decimal" id="estimated_cost" name="estimated_cost" placeholder="e.g. 1500.00">
                        </div>
                        <div>
                            <label for="actual_cost">Actual cost in <?= htmlspecialchars($trip['budget_currency'], ENT_QUOTES, 'UTF-8') ?> (optional)</label>
                            <input type="text" inputmode="decimal" id="actual_cost" name="actual_cost" placeholder="e.g. 1620.00">
                        </div>
                    </div>

                    <label for="notes">Notes (optional)</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="2000"></textarea>

                    <button type="submit">Add Item</button>
                </form>
            </details>
            <?php endif; ?>
            </section>

            <aside class="trip-detail-card">
                <?php if ($canManageTrip): ?>
                    <h2>Invite a traveler</h2>
                    <?php if ($inviteNotice): ?><p role="status"><?= htmlspecialchars($inviteNotice, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                    <?php if ($inviteError): ?><p class="form-errors" role="alert"><?= htmlspecialchars($inviteError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                    <form method="post" action="/customer/trips/invite.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                        <label for="invite-email">Existing customer email</label>
                        <input id="invite-email" type="email" name="email" required>
                        <label for="invite-role">Trip role</label>
                        <select id="invite-role" name="role">
                            <option value="editor">Editor</option>
                            <option value="commenter">Commenter</option>
                            <option value="viewer">Viewer</option>
                        </select>
                        <button type="submit">Send invitation</button>
                    </form>
                    <?php if ($pendingInvites): ?>
                        <h3>Pending invitations</h3>
                        <ul class="trip-member-list">
                            <?php foreach ($pendingInvites as $invite): ?>
                                <li><?= htmlspecialchars($invite['email'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars(ucfirst($invite['role']), ENT_QUOTES, 'UTF-8') ?> &middot; expires <?= htmlspecialchars(date('M j, Y', strtotime($invite['expires_at'])), ENT_QUOTES, 'UTF-8') ?>
                                    <form method="post" action="/customer/trips/member_action.php" class="trip-member-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                        <input type="hidden" name="action" value="revoke_invite">
                                        <input type="hidden" name="invitation_id" value="<?= (int) $invite['id'] ?>">
                                        <input type="hidden" name="issue_no" value="<?= (int) $invite['issue_no'] ?>">
                                        <button type="submit" class="link-danger">Revoke</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
                <h2>Trip members</h2>
                <?php if ($memberNotice): ?><p role="status"><?= htmlspecialchars($memberNotice, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                <?php if ($memberError): ?><p class="form-errors" role="alert"><?= htmlspecialchars($memberError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                <p>Your role: <strong><?= htmlspecialchars(ucfirst($tripRole), ENT_QUOTES, 'UTF-8') ?></strong><?= $canEditItinerary ? '' : ' (view only)' ?></p>
                <ul class="trip-member-list">
                    <?php foreach ($members as $member): ?>
                        <li><?= htmlspecialchars($member['name'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars(ucfirst($member['role']), ENT_QUOTES, 'UTF-8') ?>
                            <?php if ($canManageTrip && $member['role'] !== 'owner'): ?>
                                <form method="post" action="/customer/trips/member_action.php" class="trip-member-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                    <input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">
                                    <input type="hidden" name="action" value="change_role">
                                    <label class="sr-only" for="member-role-<?= (int) $member['id'] ?>">Role for <?= htmlspecialchars($member['name'], ENT_QUOTES, 'UTF-8') ?></label>
                                    <select id="member-role-<?= (int) $member['id'] ?>" name="role">
                                        <?php foreach (['editor', 'commenter', 'viewer'] as $roleOption): ?>
                                            <option value="<?= $roleOption ?>" <?= $member['role'] === $roleOption ? 'selected' : '' ?>><?= ucfirst($roleOption) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit">Change role</button>
                                </form>
                                <form method="post" action="/customer/trips/member_action.php" class="trip-member-form"
                                      onsubmit="return confirm('Remove this member? Their access ends immediately.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                    <input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <button type="submit" class="link-danger">Remove</button>
                                </form>
                                <?php if ($member['system_role'] === 'customer'): ?>
                                <form method="post" action="/customer/trips/member_action.php" class="trip-member-form"
                                      onsubmit="return confirm('Make this member the owner? You will become an editor.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                    <input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">
                                    <input type="hidden" name="action" value="transfer">
                                    <button type="submit">Make owner</button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($tripRole !== 'owner'): ?>
                    <form method="post" action="/customer/trips/member_action.php"
                          onsubmit="return confirm('Leave this trip? The trip and other members are not affected.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                        <input type="hidden" name="action" value="leave">
                        <button type="submit" class="link-danger">Leave trip</button>
                    </form>
                <?php endif; ?>
                <dl class="trip-detail-list">
                    <dt>Destination</dt>
                    <dd><?= htmlspecialchars($trip['destination'], ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt>Trip dates</dt>
                    <dd><?= htmlspecialchars(date('M j', strtotime($trip['start_date'])), ENT_QUOTES, 'UTF-8') ?> &mdash; <?= htmlspecialchars(date('M j, Y', strtotime($trip['end_date'])), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt>Currency</dt>
                    <dd><?= htmlspecialchars($trip['budget_currency'], ENT_QUOTES, 'UTF-8') ?></dd>
                    <?php if (!empty($trip['notes'])): ?>
                        <dt>Notes</dt>
                        <dd class="trip-notes"><?= nl2br(htmlspecialchars($trip['notes'], ENT_QUOTES, 'UTF-8')) ?></dd>
                    <?php endif; ?>
                </dl>
                <?php if ($canManageTrip): ?>
                <div class="trip-detail-actions">
                    <a href="/customer/trips/edit.php?id=<?= (int) $trip['id'] ?>" class="btn-primary">Edit trip</a>
                    <form method="post" action="/customer/trips/delete.php" onsubmit="return confirm('Delete this trip? This cannot be undone.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $trip['id'] ?>">
                        <button type="submit" class="btn-danger">Delete</button>
                    </form>
                </div>
                <?php endif; ?>
            </aside>

            <section class="budget-summary">
                <h2>Budget</h2>
                <dl class="trip-detail-list">
                    <dt>Planned across itinerary</dt>
                    <dd><?= htmlspecialchars($trip['budget_currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format($totalEstimated, 2) ?></dd>
                    <dt>Actual spend</dt>
                    <dd><?= htmlspecialchars($trip['budget_currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format($totalActual, 2) ?></dd>
                    <?php if ($trip['budget_amount'] !== null): ?>
                        <?php $remaining = (float) $trip['budget_amount'] - $totalActual; ?>
                        <dt>Total budget</dt>
                        <dd><?= htmlspecialchars($trip['budget_currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $trip['budget_amount'], 2) ?></dd>
                        <dt><?= $remaining >= 0 ? 'Remaining' : 'Over budget' ?></dt>
                        <dd class="<?= $remaining >= 0 ? '' : 'budget-over' ?>"><?= htmlspecialchars($trip['budget_currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format(abs($remaining), 2) ?></dd>
                    <?php endif; ?>
                </dl>
            </section>

            <section class="trip-progress" aria-labelledby="progress-title">
                <h2 id="progress-title">Progress</h2>
                <dl class="progress-list">
                    <?php foreach ([
                        'activities' => ['Activities completed', '/customer/trips/view.php?id=' . (int) $tripId . '#itinerary', 'No activities yet'],
                        'references' => ['Booking references confirmed', '/customer/trips/references.php?id=' . (int) $tripId, 'No booking references yet'],
                        'expenses'   => ['Expense shares settled', '/customer/trips/expenses.php?id=' . (int) $tripId, 'No shares owed yet'],
                    ] as $key => [$label, $href, $emptyText]): ?>
                        <?php $done = (int) $progress[$key]['done']; $total = (int) $progress[$key]['total']; ?>
                        <div class="progress-row">
                            <dt><a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a></dt>
                            <?php if ($total === 0): ?>
                                <dd class="progress-empty"><?= htmlspecialchars($emptyText, ENT_QUOTES, 'UTF-8') ?></dd>
                            <?php else: ?>
                                <dd><strong><?= $done ?> of <?= $total ?></strong>
                                    <progress max="<?= $total ?>" value="<?= $done ?>" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>: <?= $done ?> of <?= $total ?>"></progress></dd>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="progress-row">
                        <dt>Reminders acknowledged</dt>
                        <dd class="progress-empty">Reminders are not available yet</dd>
                    </div>
                </dl>
                <p class="progress-note">Booking references count only the ones you can see. Expense shares count money owed to someone else (a payer&#039;s own share is not counted). There is no overall percentage.</p>
            </section>
        </div>
    </main>

    <?php require __DIR__ . '/../../includes/partials/chat_widget.php'; ?>
    <?php require __DIR__ . '/../../includes/partials/support_chathead.php'; ?>
</body>
</html>
