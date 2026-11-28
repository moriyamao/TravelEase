<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_recommendations.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/itinerary_validation.php';
require_once __DIR__ . '/../../includes/itinerary_schedule.php';
require_once __DIR__ . '/../../includes/travel_time.php';
require_once __DIR__ . '/../../includes/trip_references.php';
require_once __DIR__ . '/../../includes/trip_progress.php';
require_once __DIR__ . '/../../includes/plan_review.php';
require_once __DIR__ . '/../../includes/notifications.php';

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
$progress = get_trip_progress($pdo, $tripId, $visibleReferences, (int) $_SESSION['user_id']);

// Access was authorized above through the trip role, so ?id= values
// for trips the user is not a member of never reach this query (§26).
$stmt = $pdo->prepare(
    'SELECT id, name, destination, start_date, end_date, status,
            budget_currency, budget_amount, notes, created_at, time_zone, archived_at
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
        'SELECT ic.itinerary_item_id, ic.body, ic.created_at,
                COALESCE(u.name, ic.author_name, \'Deleted user\') AS name
         FROM itinerary_item_comments ic
         JOIN itinerary_items i ON i.id = ic.itinerary_item_id
         LEFT JOIN users u ON u.id = ic.user_id
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
// FR-20 travel time: cached driving estimates only; no provider call on page load.
$travelPairs = itinerary_travel_pairs($items);
$travelEstimates = cached_travel_estimates($pdo, $travelPairs);
foreach (itinerary_travel_warnings($travelPairs, $travelEstimates) as $warnedId => $travelWarnings) {
    $scheduleWarnings[$warnedId] = array_merge($scheduleWarnings[$warnedId] ?? [], $travelWarnings);
}
$uncheckedTravel = count(unchecked_travel_pairs($travelPairs, $travelEstimates));
$travelNotice = $_SESSION['travel_notice'][$tripId] ?? null;
unset($_SESSION['travel_notice'][$tripId]);
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
// ---- Presentation helpers (redesign). No data access beyond the header's unread count. ----
$h = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = fn($amount) => $h($trip['budget_currency']) . ' ' . number_format((float) $amount, 2);
$startTs = strtotime($trip['start_date']);
$endTs = strtotime($trip['end_date']);
$tripLength = (int) round(($endTs - $startTs) / 86400) + 1;
$tripDates = date('Y-m', $startTs) === date('Y-m', $endTs)
    ? date('M j', $startTs) . ' – ' . date('j, Y', $endTs)
    : (date('Y', $startTs) === date('Y', $endTs) ? date('M j', $startTs) . ' – ' . date('M j, Y', $endTs) : date('M j, Y', $startTs) . ' – ' . date('M j, Y', $endTs));
$activityDone = (int) $progress['activities']['done'];
$activityTotal = (int) $progress['activities']['total'];
$budgetAmount = $trip['budget_amount'] !== null ? (float) $trip['budget_amount'] : null;
$budgetUsed = $budgetAmount ? min(100, (int) round($totalActual / $budgetAmount * 100)) : null;
$unreadNotifications = count_unread_notifications($pdo, (int) current_user_id());
$navCurrent = 'trips';
$roleLabel = ucfirst($tripRole);
$roleHelp = [
    'owner' => 'Can edit everything and manage travelers.',
    'editor' => 'Can edit the itinerary.',
    'commenter' => 'Can vote and comment.',
    'viewer' => 'View only.',
][$tripRole] ?? '';
$planOpen = $recommendations || $recommendationError;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $h($trip['name']) ?> — TravelEase</title>
    <link rel="icon" href="/assets/images/travelease-logo-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/references.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
    <link rel="stylesheet" href="/assets/css/chat-widget.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/trip.css">
</head>
<body class="app-body">
    <?php require __DIR__ . '/../../includes/partials/app_header.php'; ?>

    <main class="app-page trip-page" id="main-content" tabindex="-1">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="/customer/dashboard.php<?= $trip['archived_at'] !== null ? '?archived=1' : '' ?>"><?= $trip['archived_at'] !== null ? 'Archived trips' : 'My trips' ?></a>
            <span aria-hidden="true">/</span>
            <span aria-current="page"><?= $h($trip['name']) ?></span>
        </nav>

        <?php if ($trip['archived_at'] !== null): ?>
            <p class="notice notice-warning" role="status">This trip is archived. Its status, permissions and reminders are unchanged. The owner can restore it from Trip settings.</p>
        <?php endif; ?>

        <section class="trip-hero" aria-labelledby="trip-title">
            <div class="trip-hero-main">
                <p class="trip-hero-tags">
                    <span class="chip chip-status-<?= $h($trip['status']) ?>"><?= $h($statusLabels[$trip['status']] ?? $trip['status']) ?></span>
                    <span class="chip chip-dark">You're <?= $tripRole === 'owner' ? 'the owner' : 'a ' . $h(strtolower($roleLabel)) ?></span>
                </p>
                <h1 id="trip-title"><?= $h($trip['name']) ?></h1>
                <p class="trip-hero-destination"><?= $h($trip['destination']) ?></p>
            </div>
            <dl class="trip-hero-facts">
                <div><dt>Dates</dt><dd><?= $h($tripDates) ?></dd></div>
                <div><dt>Length</dt><dd><?= $tripLength ?> <?= $tripLength === 1 ? 'day' : 'days' ?></dd></div>
                <div><dt>Times shown in</dt><dd><?= $h(str_replace('_', ' ', $trip['time_zone'])) ?></dd></div>
            </dl>
            <?php if ($canManageTrip): ?>
                <a href="/customer/trips/edit.php?id=<?= (int) $trip['id'] ?>" class="btn-light trip-hero-edit">Edit trip</a>
            <?php endif; ?>
        </section>

        <nav class="trip-tabs" aria-label="Trip sections">
            <a href="#itinerary" aria-current="page">Itinerary</a>
            <a href="/customer/trips/places.php?id=<?= (int) $tripId ?>">Places &amp; map</a>
            <a href="/customer/trips/expenses.php?id=<?= (int) $tripId ?>">Expenses</a>
            <a href="/customer/trips/references.php?id=<?= (int) $tripId ?>">Bookings</a>
            <a href="/customer/trips/reminders.php?id=<?= (int) $tripId ?>">Reminders</a>
            <a href="/customer/trips/history.php?id=<?= (int) $tripId ?>">History</a>
            <?php if ($canManageTrip): ?>
                <a href="/customer/trips/share_links.php?id=<?= (int) $tripId ?>">Share links</a>
            <?php endif; ?>
        </nav>

        <section class="trip-summary" aria-label="Trip at a glance">
            <div class="summary-tile">
                <span class="summary-label">Activities done</span>
                <strong><?= $activityDone ?><small> / <?= $activityTotal ?></small></strong>
                <?php if ($activityTotal > 0): ?><div class="meter" aria-hidden="true"><span style="width: <?= (int) round($activityDone / $activityTotal * 100) ?>%"></span></div><?php endif; ?>
            </div>
            <div class="summary-tile">
                <span class="summary-label">Itinerary spending</span>
                <strong><?= $money($totalActual) ?></strong>
                <?php if ($budgetUsed !== null): ?>
                    <div class="meter<?= $totalActual > $budgetAmount ? ' is-over' : '' ?>" aria-hidden="true"><span style="width: <?= $budgetUsed ?>%"></span></div>
                    <span class="summary-sub">of <?= $money($budgetAmount) ?> budget</span>
                <?php else: ?>
                    <span class="summary-sub">No total budget set</span>
                <?php endif; ?>
            </div>
            <div class="summary-tile">
                <span class="summary-label">Travelers</span>
                <strong><?= count($members) ?></strong>
                <span class="summary-sub"><a href="#members">See who's coming</a></span>
            </div>
            <div class="summary-tile<?= $scheduleWarnings ? ' summary-warn' : '' ?>">
                <span class="summary-label">Schedule checks</span>
                <strong><?= count($scheduleWarnings) ?></strong>
                <span class="summary-sub"><?= $scheduleWarnings ? (count($scheduleWarnings) === 1 ? 'activity needs a look' : 'activities need a look') : 'No warnings' ?></span>
            </div>
        </section>

        <div class="trip-layout">
            <div class="trip-main">
                <section class="itinerary-panel" id="itinerary" aria-labelledby="itinerary-title" tabindex="-1">
                    <div class="panel-head">
                        <div>
                            <h2 id="itinerary-title" class="section-title">Itinerary</h2>
                            <p class="section-lead"><?= count($items) === 1 ? '1 activity' : count($items) . ' activities' ?> across <?= count($itemsByDate) === 1 ? '1 day' : count($itemsByDate) . ' days' ?></p>
                        </div>
                        <?php if ($canEditItinerary): ?>
                            <a class="btn-primary" href="#add-activity" data-open-details="add-activity">+ Add activity</a>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($itineraryErrors)): ?>
                        <ul class="form-errors" role="alert">
                            <?php foreach ($itineraryErrors as $error): ?>
                                <li><?= $h($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($travelNotice): ?>
                        <p class="notice notice-info" role="status"><?= $h($travelNotice) ?></p>
                    <?php endif; ?>
                    <?php if ($scheduleWarnings): ?>
                        <p class="notice notice-warning" role="status"><?= count($scheduleWarnings) === 1 ? '1 activity has a schedule warning.' : count($scheduleWarnings) . ' activities have schedule warnings.' ?> Warnings do not block saving.</p>
                    <?php endif; ?>
                    <?php if ($uncheckedTravel > 0): ?>
                        <form method="post" action="/customer/trips/travel_check.php" class="notice notice-info travel-check-row">
                            <?= csrf_field() ?>
                            <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                            <span><?= $uncheckedTravel === 1 ? '1 pair of back-to-back activities at different places has' : $uncheckedTravel . ' pairs of back-to-back activities at different places have' ?> not been checked for travel time.</span>
                            <button type="submit" class="btn-secondary btn-sm">Check travel times</button>
                        </form>
                    <?php endif; ?>

                    <?php if (empty($itemsByDate)): ?>
                        <div class="itinerary-empty">
                            <p><strong>No activities yet.</strong></p>
                            <p class="muted"><?= $canEditItinerary ? 'Add your first stop to start building the plan.' : 'The trip owner or an editor can add activities.' ?></p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($itemsByDate as $date => $dayItems): ?>
                            <?php
                                $dayTs = strtotime($date);
                                $dayNumber = (int) round(($dayTs - $startTs) / 86400) + 1;
                                $inTrip = $dayNumber >= 1 && $dayNumber <= $tripLength;
                            ?>
                            <section class="day" aria-labelledby="day-<?= $h($date) ?>">
                                <h3 class="day-heading" id="day-<?= $h($date) ?>">
                                    <span class="day-number"><?= $inTrip ? 'Day ' . $dayNumber : 'Outside trip' ?></span>
                                    <span class="day-date"><?= $h(date('l, M j', $dayTs)) ?></span>
                                    <span class="day-count"><?= count($dayItems) === 1 ? '1 activity' : count($dayItems) . ' activities' ?></span>
                                </h3>
                                <ol class="activity-list">
                                    <?php foreach ($dayItems as $dayIndex => $item): ?>
                                        <?php
                                            $itemId = (int) $item['id'];
                                            $myVote = $item['my_vote'] === null ? 0 : (int) $item['my_vote'];
                                            $itemComments = $commentsByItem[$itemId] ?? [];
                                            $hasWarnings = !empty($scheduleWarnings[$itemId]);
                                        ?>
                                        <li class="activity<?= $item['completed_at'] !== null ? ' is-done' : '' ?><?= $hasWarnings ? ' has-warning' : '' ?>" id="item-<?= $itemId ?>">
                                            <div class="activity-time">
                                                <?php if (!empty($item['item_time'])): ?>
                                                    <strong><?= $h(substr($item['item_time'], 0, 5)) ?></strong>
                                                    <?php if (!empty($item['end_time'])): ?><span>to <?= $h(substr($item['end_time'], 0, 5)) ?></span><?php endif; ?>
                                                <?php else: ?>
                                                    <span>Any time</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="activity-body">
                                                <div class="activity-head">
                                                    <h4 class="activity-title"><?= $h($item['title']) ?></h4>
                                                    <span class="chip"><?= $h(ITINERARY_CATEGORIES[$item['category'] ?? ''] ?? 'Uncategorized') ?></span>
                                                    <?php if ($item['completed_at'] !== null): ?>
                                                        <span class="chip chip-done">Done<?= $item['completed_by_name'] !== null ? ' · by ' . $h($item['completed_by_name']) : '' ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($item['location']) || $item['place_name'] !== null || $item['assignee_id'] !== null): ?>
                                                    <ul class="activity-facts">
                                                        <?php if (!empty($item['location'])): ?>
                                                            <li><span class="fact-label">Where</span> <?= $h($item['location']) ?></li>
                                                        <?php endif; ?>
                                                        <?php if ($item['place_name'] !== null): ?>
                                                            <li><span class="fact-label">Place</span> <a href="/customer/trips/places.php?id=<?= (int) $tripId ?>#activity-<?= $itemId ?>"><?= $h($item['place_name']) ?></a><?php if ($item['place_lat'] !== null && $item['place_lon'] !== null): ?> · <a href="https://www.openstreetmap.org/directions?route=%3B<?= rawurlencode($item['place_lat'] . ',' . $item['place_lon']) ?>" target="_blank" rel="noopener noreferrer">Directions<span class="sr-only"> (opens OpenStreetMap in a new tab)</span></a><?php endif; ?></li>
                                                        <?php endif; ?>
                                                        <?php if ($item['assignee_id'] !== null): ?>
                                                            <li><span class="fact-label">Who</span> <?= $h($item['assignee_name'] ?? 'a deleted user') ?><?= in_array((int) $item['assignee_id'], $memberIds, true) ? '' : ' (no longer on this trip)' ?></li>
                                                        <?php endif; ?>
                                                    </ul>
                                                <?php endif; ?>
                                                <?php if ($hasWarnings): ?>
                                                    <ul class="activity-warnings">
                                                        <?php foreach ($scheduleWarnings[$itemId] as $warning): ?>
                                                            <?php if ($warning['type'] === 'outside_trip'): ?>
                                                                <li>Outside the trip dates. Move it to a day within the trip.</li>
                                                            <?php elseif ($warning['type'] === 'overlap'): ?>
                                                                <li>Overlaps with &ldquo;<?= $h((string) ($itemTitles[$warning['other_id']] ?? 'another activity')) ?>&rdquo; (<?= $h($warning['other_start'] . '–' . $warning['other_end']) ?>)</li>
                                                            <?php elseif ($warning['type'] === 'travel'): ?>
                                                                <li>Driving from &ldquo;<?= $h((string) ($itemTitles[$warning['other_id']] ?? 'the previous activity')) ?>&rdquo; takes about <?= (int) $warning['travel_minutes'] ?> min, but there <?= (int) $warning['gap_minutes'] === 1 ? 'is only 1 minute' : 'are only ' . (int) $warning['gap_minutes'] . ' min' ?> between them. Estimate only; actual travel may differ.</li>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                                <?php if ($item['estimated_cost'] !== null || $item['actual_cost'] !== null): ?>
                                                    <p class="activity-cost">
                                                        <?php if ($item['estimated_cost'] !== null): ?><span>Est. <?= $money($item['estimated_cost']) ?></span><?php endif; ?>
                                                        <?php if ($item['actual_cost'] !== null): ?><span>Actual <?= $money($item['actual_cost']) ?></span><?php endif; ?>
                                                    </p>
                                                <?php endif; ?>
                                                <?php if (!empty($item['notes'])): ?>
                                                    <p class="activity-notes"><?= nl2br($h($item['notes'])) ?></p>
                                                <?php endif; ?>

                                                <div class="activity-footer">
                                                    <div class="activity-feedback">
                                                        <?php if ($canGiveFeedback): ?>
                                                            <?php foreach (['up' => 1, 'down' => -1] as $choice => $value): ?>
                                                                <form method="post" action="/customer/trips/item_feedback.php">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                                                    <input type="hidden" name="item_id" value="<?= $itemId ?>">
                                                                    <input type="hidden" name="action" value="vote">
                                                                    <input type="hidden" name="vote" value="<?= $choice ?>">
                                                                    <button type="submit" class="vote-button" aria-pressed="<?= $myVote === $value ? 'true' : 'false' ?>">
                                                                        <span aria-hidden="true"><?= $choice === 'up' ? '▲' : '▼' ?></span>
                                                                        <?= $choice === 'up' ? 'Vote up' : 'Vote down' ?>
                                                                        <span class="vote-count"><?= (int) ($choice === 'up' ? $item['up_votes'] : $item['down_votes']) ?></span>
                                                                    </button>
                                                                </form>
                                                            <?php endforeach; ?>
                                                            <?php if ($myVote !== 0): ?>
                                                                <form method="post" action="/customer/trips/item_feedback.php">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                                                    <input type="hidden" name="item_id" value="<?= $itemId ?>">
                                                                    <input type="hidden" name="action" value="clear_vote">
                                                                    <button type="submit" class="text-button">Clear my vote</button>
                                                                </form>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="vote-summary"><?= (int) $item['up_votes'] ?> up · <?= (int) $item['down_votes'] ?> down</span>
                                                        <?php endif; ?>
                                                        <?php if ((int) $item['edited_after_feedback'] === 1): ?>
                                                            <span class="chip">Edited after feedback</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($canEditItinerary): ?>
                                                        <div class="activity-actions">
                                                            <a href="/customer/trips/itinerary_edit.php?id=<?= $itemId ?>" class="btn-secondary btn-sm">Edit<span class="sr-only"> <?= $h($item['title']) ?></span></a>
                                                            <form method="post" action="/customer/trips/itinerary_action.php">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="item_id" value="<?= $itemId ?>">
                                                                <input type="hidden" name="action" value="<?= $item['completed_at'] === null ? 'complete' : 'reopen' ?>">
                                                                <button type="submit" class="btn-secondary btn-sm"><?= $item['completed_at'] === null ? 'Mark done' : 'Reopen' ?></button>
                                                            </form>
                                                            <?php if ($dayIndex > 0): ?>
                                                                <form method="post" action="/customer/trips/itinerary_action.php">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="item_id" value="<?= $itemId ?>">
                                                                    <input type="hidden" name="action" value="move_up">
                                                                    <button type="submit" class="btn-ghost btn-sm btn-icon" title="Move up" aria-label="Move up: <?= $h($item['title']) ?>">↑</button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <?php if ($dayIndex < count($dayItems) - 1): ?>
                                                                <form method="post" action="/customer/trips/itinerary_action.php">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="item_id" value="<?= $itemId ?>">
                                                                    <input type="hidden" name="action" value="move_down">
                                                                    <button type="submit" class="btn-ghost btn-sm btn-icon" title="Move down" aria-label="Move down: <?= $h($item['title']) ?>">↓</button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <form method="post" action="/customer/trips/delete_itinerary.php"
                                                                  onsubmit="return confirm('Delete this item?');">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="id" value="<?= $itemId ?>">
                                                                <button type="submit" class="btn-quiet-danger btn-sm">Delete<span class="sr-only"> <?= $h($item['title']) ?></span></button>
                                                            </form>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if ($itemComments || $canGiveFeedback): ?>
                                                    <details class="activity-comments disclosure">
                                                        <summary><?= $itemComments ? (count($itemComments) === 1 ? '1 comment' : count($itemComments) . ' comments') : 'Comment' ?><span class="sr-only"> on <?= $h($item['title']) ?></span></summary>
                                                        <?php if ($itemComments): ?>
                                                            <ul class="comment-list">
                                                                <?php foreach ($itemComments as $comment): ?>
                                                                    <li>
                                                                        <p class="comment-meta"><strong><?= $h($comment['name']) ?></strong> · <?= $h(gmdate('M j, Y H:i', strtotime($comment['created_at'] . ' UTC'))) ?> UTC</p>
                                                                        <p class="comment-body"><?= $h($comment['body']) ?></p>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        <?php endif; ?>
                                                        <?php if ($canGiveFeedback): ?>
                                                            <form class="comment-form" method="post" action="/customer/trips/item_feedback.php">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                                                <input type="hidden" name="item_id" value="<?= $itemId ?>">
                                                                <input type="hidden" name="action" value="comment">
                                                                <label for="comment-<?= $itemId ?>">Add a comment</label>
                                                                <textarea id="comment-<?= $itemId ?>" name="body" rows="2" maxlength="1000" required></textarea>
                                                                <button type="submit" class="btn-secondary btn-sm">Post comment</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </details>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            </section>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($canEditItinerary): ?>
                        <details class="add-activity disclosure" id="add-activity"<?= !empty($itineraryErrors) ? ' open' : '' ?>>
                            <summary>Add an activity</summary>
                            <form method="post" action="/customer/trips/itinerary_add.php" novalidate>
                                <?= csrf_field() ?>
                                <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">

                                <label for="title">Title</label>
                                <input type="text" id="title" name="title" required maxlength="150" placeholder="e.g. Visit the museum">

                                <div class="form-grid form-grid-3">
                                    <div>
                                        <label for="item_date">Date</label>
                                        <input type="date" id="item_date" name="item_date" required
                                               min="<?= $h($trip['start_date']) ?>"
                                               max="<?= $h($trip['end_date']) ?>">
                                    </div>
                                    <div>
                                        <label for="item_time">Start time <span class="optional">(optional)</span></label>
                                        <input type="time" id="item_time" name="item_time">
                                    </div>
                                    <div>
                                        <label for="end_time">End time <span class="optional">(optional)</span></label>
                                        <input type="time" id="end_time" name="end_time">
                                    </div>
                                </div>

                                <label for="location">Location <span class="optional">(optional)</span></label>
                                <input type="text" id="location" name="location" maxlength="150">

                                <div class="form-grid">
                                    <div>
                                        <label for="category">Category <span class="optional">(optional)</span></label>
                                        <select id="category" name="category">
                                            <option value="">Uncategorized</option>
                                            <?php foreach (ITINERARY_CATEGORIES as $value => $label): ?>
                                                <option value="<?= $value ?>"><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="assignee_id">Assigned to <span class="optional">(optional)</span></label>
                                        <select id="assignee_id" name="assignee_id">
                                            <option value="">Unassigned</option>
                                            <?php foreach ($assignableMembers as $person): ?>
                                                <option value="<?= (int) $person['id'] ?>"><?= $h($person['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-grid">
                                    <div>
                                        <label for="estimated_cost">Estimated cost in <?= $h($trip['budget_currency']) ?> <span class="optional">(optional)</span></label>
                                        <input type="text" inputmode="decimal" id="estimated_cost" name="estimated_cost" placeholder="e.g. 1500.00">
                                    </div>
                                    <div>
                                        <label for="actual_cost">Actual cost in <?= $h($trip['budget_currency']) ?> <span class="optional">(optional)</span></label>
                                        <input type="text" inputmode="decimal" id="actual_cost" name="actual_cost" placeholder="e.g. 1620.00">
                                    </div>
                                </div>

                                <label for="notes">Notes <span class="optional">(optional)</span></label>
                                <textarea id="notes" name="notes" rows="3" maxlength="2000"></textarea>

                                <button type="submit" class="btn-primary">Add activity</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </section>

                <?php if ($canEditItinerary): ?>
                    <?php
                    $planReview = $_SESSION['plan_review'][$tripId] ?? null;
                    if (is_array($planReview) && ($planReview['created'] <= time() - PLAN_REVIEW_TTL || !is_string($planReview['id'] ?? null))) {
                        unset($_SESSION['plan_review'][$tripId]);
                        $planReview = null;
                    }
                    $planNotice = $_SESSION['plan_review_notice'][$tripId] ?? null;
                    $planError = $_SESSION['plan_review_error'][$tripId] ?? null;
                    unset($_SESSION['plan_review_notice'][$tripId], $_SESSION['plan_review_error'][$tripId]);
                    $planTime = fn(array $slot) => date('D, M j', strtotime($slot['date'])) . ($slot['time'] !== '' ? ' ' . $slot['time'] . ($slot['end'] !== '' ? '–' . $slot['end'] : '') : ', any time');
                    $reviewOpen = $planReview || $planNotice || $planError;
                    ?>
                    <section class="assist-panel" aria-labelledby="assist-title">
                        <div class="panel-head">
                            <div>
                                <p class="eyebrow">Planning assistant</p>
                                <h2 id="assist-title" class="section-title">Ideas and plan check</h2>
                                <p class="section-lead">Optional AI help. Nothing changes until you add or apply a suggestion.</p>
                            </div>
                        </div>

                        <details class="assist-tool disclosure" id="plan-review"<?= $reviewOpen ? ' open' : '' ?>>
                            <summary>
                                <span><span class="assist-tool-title">Review my plan</span><span class="assist-tool-sub">Budget tips, schedule fixes and ideas</span></span>
                            </summary>
                            <div class="assist-tool-body">
                                <p class="assist-privacy">Sent to the AI service: destination, dates, budget, and each activity's title, date, times, category, place name and estimated cost. Notes, comments, expenses, bookings, reminders and member details are not sent. Up to <?= PLAN_REVIEW_LIMIT_PER_HOUR ?> reviews an hour.</p>
                                <form method="post" action="/customer/trips/plan_review.php" class="plan-review-request">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                                    <input type="hidden" name="action" value="review">
                                    <button type="submit" class="btn-primary">Review my plan</button>
                                </form>
                                <?php if ($planNotice): ?><p class="notice notice-success" role="status"><?= $h($planNotice) ?></p><?php endif; ?>
                                <?php if ($planError): ?><p class="notice notice-error" role="alert"><?= $h($planError) ?></p><?php endif; ?>
                                <?php if ($planReview): ?>
                                    <?php $r = $planReview['review']; ?>
                                    <?php if ($r['schedule']): ?>
                                        <h3 class="assist-subhead">Schedule</h3>
                                        <ul class="suggestion-list">
                                            <?php foreach ($r['schedule'] as $n => $s): ?>
                                                <li class="suggestion">
                                                    <div>
                                                        <p class="suggestion-title"><strong><?= $h($s['title']) ?></strong></p>
                                                        <p class="suggestion-change"><span class="muted"><?= $h($planTime($s['from'])) ?></span> <span aria-hidden="true">→</span><span class="sr-only"> to </span> <strong><?= $h($planTime($s['to'])) ?></strong></p>
                                                        <p class="suggestion-reason"><?= $h($s['reason']) ?></p>
                                                    </div>
                                                    <form method="post" action="/customer/trips/plan_apply.php">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                                                        <input type="hidden" name="suggestion" value="<?= (int) $n ?>">
                                                        <input type="hidden" name="review_id" value="<?= $h($planReview['id']) ?>">
                                                        <button type="submit" class="btn-secondary btn-sm">Apply</button>
                                                    </form>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <?php foreach (['budget' => 'Budget', 'ideas' => 'Ideas'] as $planKey => $planHeading): ?>
                                        <?php if ($r[$planKey]): ?>
                                            <h3 class="assist-subhead"><?= $planHeading ?></h3>
                                            <ul class="tip-list">
                                                <?php foreach ($r[$planKey] as $tip): ?><li><?= $h($tip) ?></li><?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <p class="assist-privacy">AI suggestions can be wrong. Check times, prices and opening hours yourself. Suggestions disappear after 30 minutes.</p>
                                    <form method="post" action="/customer/trips/plan_review.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                                        <input type="hidden" name="action" value="clear">
                                        <input type="hidden" name="review_id" value="<?= $h($planReview['id']) ?>">
                                        <button type="submit" class="text-button">Clear suggestions</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </details>

                        <details class="assist-tool disclosure" id="trip-ideas"<?= $planOpen ? ' open' : '' ?>>
                            <summary>
                                <span><span class="assist-tool-title">Ideas for <?= $h($trip['destination']) ?></span><span class="assist-tool-sub">Suggested activities you can edit, then add</span></span>
                            </summary>
                            <div class="assist-tool-body">
                                <p class="assist-privacy">Place details may be inaccurate; check them before you go. Your destination, dates, budget, interests, and pace are sent to the AI service for this request.</p>
                                <form class="ideas-request" method="post" action="/customer/trips/view.php?id=<?= (int) $trip['id'] ?>#trip-ideas">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="recommend">
                                    <div class="form-grid">
                                        <div>
                                            <label for="trip-interests">What do you enjoy?</label>
                                            <input id="trip-interests" name="interests" maxlength="120" placeholder="Art, food, nature..." value="<?= $h($interests) ?>">
                                        </div>
                                        <div>
                                            <label for="trip-pace">Travel pace</label>
                                            <select id="trip-pace" name="pace">
                                                <option value="relaxed" <?= $pace === 'relaxed' ? 'selected' : '' ?>>Relaxed</option>
                                                <option value="balanced" <?= $pace === 'balanced' ? 'selected' : '' ?>>Balanced</option>
                                                <option value="packed" <?= $pace === 'packed' ? 'selected' : '' ?>>Packed</option>
                                            </select>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn-primary">Suggest activities</button>
                                </form>
                                <?php if ($recommendationError): ?>
                                    <p class="notice notice-error" role="alert"><?= $h($recommendationError) ?></p>
                                <?php endif; ?>
                                <?php if ($recommendations): ?>
                                    <div class="idea-grid">
                                        <?php foreach ($recommendations as $index => $idea): ?>
                                            <form class="idea-card" method="post" action="/customer/trips/itinerary_add.php">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                                <p class="eyebrow">AI suggestion <?= $index + 1 ?></p>
                                                <label for="idea-title-<?= $index ?>">Activity</label>
                                                <input id="idea-title-<?= $index ?>" name="title" maxlength="150" required value="<?= $h($idea['title']) ?>">
                                                <label for="idea-date-<?= $index ?>">Day</label>
                                                <input id="idea-date-<?= $index ?>" type="date" name="item_date" min="<?= $h($trip['start_date']) ?>" max="<?= $h($trip['end_date']) ?>" required value="<?= $h($idea['date']) ?>">
                                                <label for="idea-location-<?= $index ?>">Place</label>
                                                <input id="idea-location-<?= $index ?>" name="location" maxlength="150" value="<?= $h($idea['location']) ?>">
                                                <label for="idea-notes-<?= $index ?>">Why this place?</label>
                                                <textarea id="idea-notes-<?= $index ?>" name="notes" rows="2" maxlength="2000"><?= $h($idea['notes']) ?></textarea>
                                                <button type="submit" class="btn-secondary btn-sm">Add to itinerary</button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </details>
                    </section>
                <?php endif; ?>
            </div>

            <aside class="trip-side" aria-label="Trip details">
                <section class="card" aria-labelledby="budget-title">
                    <h2 id="budget-title" class="card-title">Budget</h2>
                    <dl class="kv-list">
                        <div><dt>Planned across itinerary</dt><dd><?= $money($totalEstimated) ?></dd></div>
                        <div><dt>Actual spend</dt><dd><?= $money($totalActual) ?></dd></div>
                        <?php if ($trip['budget_amount'] !== null): ?>
                            <?php $remaining = (float) $trip['budget_amount'] - $totalActual; ?>
                            <div><dt>Total budget</dt><dd><?= $money($trip['budget_amount']) ?></dd></div>
                            <div class="kv-total<?= $remaining >= 0 ? '' : ' is-over' ?>"><dt><?= $remaining >= 0 ? 'Remaining' : 'Over budget' ?></dt><dd><?= $money(abs($remaining)) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                    <a class="card-link" href="/customer/trips/expenses.php?id=<?= (int) $tripId ?>">Shared expenses <span aria-hidden="true">→</span></a>
                </section>

                <section class="card" aria-labelledby="progress-title">
                    <h2 id="progress-title" class="card-title">Progress</h2>
                    <dl class="progress-list">
                        <?php foreach ([
                            'references' => ['Bookings confirmed', '/customer/trips/references.php?id=' . (int) $tripId, 'No bookings yet'],
                            'expenses'   => ['Expense shares settled', '/customer/trips/expenses.php?id=' . (int) $tripId, 'No shares owed yet'],
                            'reminders'  => ['Your due reminders acknowledged', '/customer/trips/reminders.php?id=' . (int) $tripId, 'None of your reminders are due yet'],
                        ] as $key => [$label, $href, $emptyText]): ?>
                            <?php $done = (int) $progress[$key]['done']; $total = (int) $progress[$key]['total']; ?>
                            <div class="progress-row">
                                <dt><a href="<?= $h($href) ?>"><?= $h($label) ?></a></dt>
                                <?php if ($total === 0): ?>
                                    <dd class="progress-empty"><?= $h($emptyText) ?></dd>
                                <?php else: ?>
                                    <dd><strong><?= $done ?> of <?= $total ?></strong>
                                        <progress max="<?= $total ?>" value="<?= $done ?>" aria-label="<?= $h($label) ?>: <?= $done ?> of <?= $total ?>"></progress></dd>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                    <details class="progress-help">
                        <summary>How progress is counted</summary>
                        <p>Activity completion is shown at the top of the page. Bookings count only the ones you can see. Expense shares count money owed to someone else (a payer&#039;s own share is not counted). Reminders count only your own reminders that are due and not cancelled. There is no overall percentage.</p>
                    </details>
                </section>

                <section class="card" id="members" aria-labelledby="members-title">
                    <div class="card-head">
                        <h2 id="members-title" class="card-title">Travelers</h2>
                        <span class="chip chip-role"><?= $h($roleLabel) ?></span>
                    </div>
                    <p class="card-note">Your role: <strong><?= $h($roleLabel) ?></strong>. <?= $h($roleHelp) ?></p>
                    <?php if ($memberNotice): ?><p class="notice notice-success" role="status"><?= $h($memberNotice) ?></p><?php endif; ?>
                    <?php if ($memberError): ?><p class="notice notice-error" role="alert"><?= $h($memberError) ?></p><?php endif; ?>
                    <ul class="member-list">
                        <?php foreach ($members as $member): ?>
                            <li class="member">
                                <div class="member-row">
                                    <span class="member-avatar" aria-hidden="true"><?= $h(mb_strtoupper(mb_substr($member['name'], 0, 1))) ?></span>
                                    <span class="member-name"><?= $h($member['name']) ?><?= (int) $member['id'] === (int) current_user_id() ? ' <span class="muted">(you)</span>' : '' ?></span>
                                    <span class="chip<?= $member['role'] === 'owner' ? ' chip-role' : '' ?>"><?= $h(ucfirst($member['role'])) ?></span>
                                </div>
                                <?php if ($canManageTrip && $member['role'] !== 'owner'): ?>
                                    <details class="member-manage">
                                        <summary>Manage<span class="sr-only"> <?= $h($member['name']) ?></span></summary>
                                        <div class="member-manage-body">
                                            <form method="post" action="/customer/trips/member_action.php" class="member-role-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                                <input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">
                                                <input type="hidden" name="action" value="change_role">
                                                <label for="member-role-<?= (int) $member['id'] ?>">Role for <?= $h($member['name']) ?></label>
                                                <div class="inline-fields">
                                                    <select id="member-role-<?= (int) $member['id'] ?>" name="role">
                                                        <?php foreach (['editor', 'commenter', 'viewer'] as $roleOption): ?>
                                                            <option value="<?= $roleOption ?>" <?= $member['role'] === $roleOption ? 'selected' : '' ?>><?= ucfirst($roleOption) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="btn-secondary btn-sm">Change role</button>
                                                </div>
                                            </form>
                                            <div class="member-manage-actions">
                                                <?php if ($member['system_role'] === 'customer'): ?>
                                                    <form method="post" action="/customer/trips/member_action.php"
                                                          onsubmit="return confirm('Make this member the owner? You will become an editor.');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                                        <input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">
                                                        <input type="hidden" name="action" value="transfer">
                                                        <button type="submit" class="btn-ghost btn-sm">Make owner</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="post" action="/customer/trips/member_action.php"
                                                      onsubmit="return confirm('Remove this member? Their access ends immediately.');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                                    <input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">
                                                    <input type="hidden" name="action" value="remove">
                                                    <button type="submit" class="btn-quiet-danger btn-sm">Remove</button>
                                                </form>
                                            </div>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($canManageTrip): ?>
                        <?php if ($inviteNotice): ?><p class="notice notice-success" role="status"><?= $h($inviteNotice) ?></p><?php endif; ?>
                        <?php if ($inviteError): ?><p class="notice notice-error" role="alert"><?= $h($inviteError) ?></p><?php endif; ?>
                        <details class="invite disclosure"<?= $inviteError ? ' open' : '' ?>>
                            <summary>Invite a traveler</summary>
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
                                <button type="submit" class="btn-primary btn-block">Send invitation</button>
                            </form>
                        </details>
                        <?php if ($pendingInvites): ?>
                            <h3 class="card-subhead">Pending invitations</h3>
                            <ul class="invite-list">
                                <?php foreach ($pendingInvites as $invite): ?>
                                    <li>
                                        <div>
                                            <span class="invite-email"><?= $h($invite['email']) ?></span>
                                            <span class="muted"><?= $h(ucfirst($invite['role'])) ?> · expires <?= $h(date('M j, Y', strtotime($invite['expires_at']))) ?></span>
                                        </div>
                                        <form method="post" action="/customer/trips/member_action.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                            <input type="hidden" name="action" value="revoke_invite">
                                            <input type="hidden" name="invitation_id" value="<?= (int) $invite['id'] ?>">
                                            <input type="hidden" name="issue_no" value="<?= (int) $invite['issue_no'] ?>">
                                            <button type="submit" class="btn-quiet-danger btn-sm">Revoke<span class="sr-only"> invitation for <?= $h($invite['email']) ?></span></button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($tripRole !== 'owner'): ?>
                        <form method="post" action="/customer/trips/member_action.php" class="leave-form"
                              onsubmit="return confirm('Leave this trip? The trip and other members are not affected.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                            <input type="hidden" name="action" value="leave">
                            <button type="submit" class="btn-quiet-danger btn-sm">Leave trip</button>
                        </form>
                    <?php endif; ?>
                </section>

                <section class="card" aria-labelledby="details-title">
                    <h2 id="details-title" class="card-title">Trip details</h2>
                    <dl class="kv-list kv-stacked">
                        <div><dt>Currency</dt><dd><?= $h($trip['budget_currency']) ?></dd></div>
                        <?php if (!empty($trip['notes'])): ?>
                            <div><dt>Notes</dt><dd class="trip-notes"><?= nl2br($h($trip['notes'])) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                </section>

                <?php if ($canManageTrip): ?>
                    <section class="card card-settings" aria-labelledby="settings-title">
                        <h2 id="settings-title" class="card-title">Trip settings</h2>
                        <div class="settings-actions">
                            <form method="post" action="/customer/trips/archive.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                <input type="hidden" name="action" value="<?= $trip['archived_at'] !== null ? 'restore' : 'archive' ?>">
                                <button type="submit" class="btn-secondary btn-sm"><?= $trip['archived_at'] !== null ? 'Restore trip' : 'Archive trip' ?></button>
                            </form>
                        </div>
                        <p class="card-note">Archiving files the trip away without deleting it. Deleting removes the trip for everyone and cannot be undone.</p>
                        <form method="post" action="/customer/trips/delete.php" onsubmit="return confirm('Delete this trip? This cannot be undone.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $trip['id'] ?>">
                            <button type="submit" class="btn-quiet-danger btn-sm">Delete trip</button>
                        </form>
                    </section>
                <?php endif; ?>
            </aside>
        </div>
    </main>

    <?php
    // Lets the assistant answer status questions about this trip. Only the id
    // is sent; the server re-checks access on every message.
    $__chatTripId = (int) $tripId;
    require __DIR__ . '/../../includes/partials/chat_widget.php';
    ?>
    <?php require __DIR__ . '/../../includes/partials/support_chathead.php'; ?>
    <?php require __DIR__ . '/../../includes/partials/support_launcher.php'; ?>
    <script src="/assets/js/trip-page.js" defer></script>
</body>
</html>
