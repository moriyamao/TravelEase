<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';

require_role(['customer']);

$pdo = get_db_connection();
$showArchived = ($_GET['archived'] ?? null) === '1';
$archiveCondition = $showArchived ? 'IS NOT NULL' : 'IS NULL';
$unreadNotifications = count_unread_notifications($pdo, current_user_id());

// Ownership scoping: only ever fetch trips belonging to the logged-in
// user. There is no code path here that accepts a user_id from the client.
$stmt = $pdo->prepare(
    'SELECT id, name, destination, start_date, end_date, status
     FROM trips
     WHERE user_id = :user_id AND archived_at ' . $archiveCondition . '
     ORDER BY start_date ASC'
);
$stmt->execute(['user_id' => current_user_id()]);
$trips = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT t.id, t.name, t.destination, t.start_date, t.end_date, t.status, m.role
     FROM trip_memberships m
     JOIN trips t ON t.id = m.trip_id
     WHERE m.user_id = :user_id AND m.role <> :owner
       AND t.archived_at ' . $archiveCondition . '
     ORDER BY t.start_date ASC'
);
$stmt->execute(['user_id' => current_user_id(), 'owner' => 'owner']);
$sharedTrips = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT i.id, i.issue_no, i.role, t.name, t.destination
     FROM trip_invitations i JOIN trips t ON t.id = i.trip_id
     WHERE i.invitee_id = :user_id AND i.status = :status AND i.expires_at > UTC_TIMESTAMP()
     ORDER BY i.created_at DESC'
);
$stmt->execute(['user_id' => current_user_id(), 'status' => 'pending']);
$pendingInvitations = $stmt->fetchAll();

// Unread staff replies to this customer's own support requests.
$stmt = $pdo->prepare(
    'SELECT id, message, staff_reply, replied_at
     FROM escalations
     WHERE user_id = :user_id
       AND staff_reply IS NOT NULL
       AND customer_seen_at IS NULL
     ORDER BY replied_at DESC'
);
$stmt->execute(['user_id' => current_user_id()]);
$unreadReplies = $stmt->fetchAll();

$dashboardNotice = $_SESSION['dashboard_notice'] ?? null;
unset($_SESSION['dashboard_notice']);

$name = htmlspecialchars($_SESSION['user_name'] ?? 'Traveler', ENT_QUOTES, 'UTF-8');

$statusLabels = [
    'planning'  => 'Planning',
    'confirmed' => 'Confirmed',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];
$tripCount = count($trips);
$statusCounts = ['planning' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];
$nextTrip = null;
$today = date('Y-m-d');

foreach ($trips as $trip) {
    $statusCounts[$trip['status']] = ($statusCounts[$trip['status']] ?? 0) + 1;
    if (!$showArchived && $nextTrip === null && $trip['start_date'] >= $today && $trip['status'] !== 'cancelled') {
        $nextTrip = $trip;
    }
}

$daysUntilNextTrip = $nextTrip
    ? max(0, (int) ((strtotime($nextTrip['start_date']) - strtotime($today)) / 86400))
    : null;
$h = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
// Presentation helpers only (no data access).
$dateRange = function (string $start, string $end): string {
    $s = strtotime($start);
    $e = strtotime($end);
    if (date('Y-m', $s) === date('Y-m', $e)) {
        return date('M j', $s) . ' – ' . date('j, Y', $e);
    }
    return date('Y', $s) === date('Y', $e) ? date('M j', $s) . ' – ' . date('M j, Y', $e) : date('M j, Y', $s) . ' – ' . date('M j, Y', $e);
};
$tripDays = fn(string $start, string $end): int => (int) round((strtotime($end) - strtotime($start)) / 86400) + 1;
$navCurrent = 'trips';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $showArchived ? 'Archived trips' : 'My trips' ?> — TravelEase</title>
    <link rel="icon" href="/assets/images/travelease-logo-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css?v=20261103-2">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/chat-widget.css">
    <link rel="stylesheet" href="/assets/css/notifications.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/home.css">
</head>
<body class="app-body">
    <?php require __DIR__ . '/../includes/partials/app_header.php'; ?>

    <main class="app-page home" id="main-content" tabindex="-1">
        <?php if ($dashboardNotice): ?>
            <p class="notice notice-success" role="status"><?= $h($dashboardNotice) ?></p>
        <?php endif; ?>

        <?php if ($pendingInvitations || $unreadReplies): ?>
            <section class="home-attention" aria-labelledby="attention-heading">
                <h2 id="attention-heading" class="eyebrow">Needs your attention</h2>
                <?php foreach ($pendingInvitations as $invitation): ?>
                    <article class="attention-card" id="invitations">
                        <div>
                            <p class="attention-kind">Trip invitation</p>
                            <p class="attention-title"><strong><?= $h($invitation['name']) ?></strong> <span class="muted">· <?= $h($invitation['destination']) ?></span></p>
                            <p class="attention-detail">You're invited to join as <span class="chip chip-role"><?= $h(ucfirst($invitation['role'])) ?></span></p>
                        </div>
                        <form method="post" action="/customer/trips/respond_invitation.php" class="attention-actions">
                            <?= csrf_field() ?>
                            <input type="hidden" name="invitation_id" value="<?= (int) $invitation['id'] ?>">
                            <input type="hidden" name="issue_no" value="<?= (int) $invitation['issue_no'] ?>">
                            <button name="decision" value="accept" class="btn-primary btn-sm">Accept</button>
                            <button name="decision" value="decline" class="btn-secondary btn-sm">Decline</button>
                        </form>
                    </article>
                <?php endforeach; ?>
                <?php foreach ($unreadReplies as $reply): ?>
                    <article class="attention-card attention-support">
                        <div>
                            <p class="attention-kind">Support reply</p>
                            <p class="attention-title"><strong>Support replied to your request</strong></p>
                            <p class="attention-detail"><?= nl2br($h($reply['staff_reply'])) ?></p>
                        </div>
                        <form method="POST" action="/customer/dismiss_escalation_reply.php" class="attention-actions">
                            <?= csrf_field() ?>
                            <input type="hidden" name="escalation_id" value="<?= (int) $reply['id'] ?>">
                            <button type="submit" class="btn-ghost btn-sm">Dismiss</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <section class="home-welcome" aria-labelledby="welcome-heading">
            <div>
                <p class="eyebrow">Your travel desk</p>
                <h1 id="welcome-heading"><?= $showArchived ? 'Archived trips' : 'Welcome back, ' . $name . '.' ?></h1>
                <p class="section-lead"><?= $showArchived ? 'Trips you filed away. Archiving never deletes a trip, and you can restore it from its page.' : 'Every route, date and plan, kept together in one calm place.' ?></p>
            </div>
            <div class="home-welcome-actions">
                <a href="/customer/trips/create.php" class="btn-primary"><span aria-hidden="true">+</span> Plan a trip</a>
            </div>
        </section>

        <?php if ($nextTrip): ?>
            <section class="home-overview" aria-label="Next trip">
                <article class="next-trip">
                    <div class="next-trip-main">
                        <p class="next-trip-label">Next journey<span class="sr-only">, <?= $daysUntilNextTrip === 0 ? 'starts today' : 'in ' . $daysUntilNextTrip . ($daysUntilNextTrip === 1 ? ' day' : ' days') ?></span></p>
                        <h2><?= $h($nextTrip['name']) ?></h2>
                        <p class="next-trip-destination"><?= $h($nextTrip['destination']) ?></p>
                    </div>
                    <dl class="next-trip-facts">
                        <div><dt>Dates</dt><dd><?= $h($dateRange($nextTrip['start_date'], $nextTrip['end_date'])) ?></dd></div>
                        <div><dt>Length</dt><dd><?= $tripDays($nextTrip['start_date'], $nextTrip['end_date']) ?> days</dd></div>
                        <div><dt>Status</dt><dd><?= $h($statusLabels[$nextTrip['status']] ?? $nextTrip['status']) ?></dd></div>
                    </dl>
                    <div class="next-trip-actions">
                        <a class="btn-light" href="/customer/trips/view.php?id=<?= (int) $nextTrip['id'] ?>">Open trip <span aria-hidden="true">→</span></a>
                        <a class="btn-outline-light" href="/customer/trips/expenses.php?id=<?= (int) $nextTrip['id'] ?>">Expenses</a>
                    </div>
                    <div class="next-trip-countdown" aria-hidden="true"><strong><?= $daysUntilNextTrip ?></strong><span><?= $daysUntilNextTrip === 1 ? 'day to go' : 'days to go' ?></span></div>
                </article>

                <aside class="home-stats" aria-label="Your trips by status">
                    <div class="stat"><strong><?= $statusCounts['planning'] ?></strong><span>Planning</span></div>
                    <div class="stat"><strong><?= $statusCounts['confirmed'] ?></strong><span>Confirmed</span></div>
                    <div class="stat"><strong><?= $statusCounts['completed'] ?></strong><span>Completed</span></div>
                    <div class="stat"><strong><?= $tripCount ?></strong><span>Trips you own</span></div>
                </aside>
            </section>
        <?php endif; ?>

        <section class="home-journeys" aria-labelledby="journeys-heading">
            <div class="journeys-toolbar">
                <div>
                    <h2 id="journeys-heading" class="section-title"><?= $showArchived ? 'Archived journeys' : 'Your journeys' ?></h2>
                    <nav class="segmented" aria-label="Trip archive views">
                        <a href="/customer/dashboard.php"<?= !$showArchived ? ' aria-current="page"' : '' ?>>Active</a>
                        <a href="/customer/dashboard.php?archived=1"<?= $showArchived ? ' aria-current="page"' : '' ?>>Archived</a>
                    </nav>
                </div>
                <?php if ($trips || $sharedTrips): ?>
                    <div class="trip-filter-bar" role="search" aria-label="Filter trips">
                        <label class="sr-only" for="trip-search">Search trips</label>
                        <input type="search" id="trip-search" placeholder="Search trips or places">
                        <label class="sr-only" for="trip-status-filter">Filter by status</label>
                        <select id="trip-status-filter">
                            <option value="">All statuses</option>
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <option value="<?= $h($value) ?>"><?= $h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="sr-only" for="trip-sort">Sort trips</label>
                        <select id="trip-sort">
                            <option value="date-asc">Soonest first</option>
                            <option value="date-desc">Latest first</option>
                            <option value="name-asc">Name A–Z</option>
                        </select>
                    </div>
                <?php endif; ?>
            </div>

            <h3 class="list-heading">Trips you own <span class="list-count"><?= $tripCount ?></span></h3>
            <?php if (empty($trips)): ?>
                <div class="home-empty">
                    <img src="/assets/images/travelease-logo-mark.svg" alt="">
                    <h4><?= $showArchived ? 'No archived trips you own.' : 'Your next journey starts here.' ?></h4>
                    <p><?= $showArchived ? 'Archive a trip from its trip page. Archiving never deletes it.' : 'Create your first trip to organize destinations, dates, activities, and budget.' ?></p>
                    <?php if (!$showArchived): ?><a href="/customer/trips/create.php" class="btn-primary">Plan your first trip</a><?php endif; ?>
                </div>
            <?php else: ?>
                <ul class="journey-list" data-trip-list>
                    <?php foreach ($trips as $trip): ?>
                        <?php $start = strtotime($trip['start_date']); ?>
                        <li class="journey"
                            data-name="<?= $h(mb_strtolower($trip['name'])) ?>"
                            data-destination="<?= $h(mb_strtolower($trip['destination'])) ?>"
                            data-status="<?= $h($trip['status']) ?>"
                            data-start="<?= $h($trip['start_date']) ?>">
                            <div class="journey-date journey-<?= $h($trip['status']) ?>" aria-hidden="true">
                                <span><?= $h(date('M', $start)) ?></span><strong><?= $h(date('j', $start)) ?></strong><small><?= $h(date('Y', $start)) ?></small>
                            </div>
                            <div class="journey-body">
                                <h4><a class="journey-link" href="/customer/trips/view.php?id=<?= (int) $trip['id'] ?>"><?= $h($trip['name']) ?></a></h4>
                                <p class="journey-destination"><?= $h($trip['destination']) ?></p>
                                <p class="journey-meta"><span><?= $h($dateRange($trip['start_date'], $trip['end_date'])) ?></span><span><?= $tripDays($trip['start_date'], $trip['end_date']) ?> days</span></p>
                            </div>
                            <span class="chip chip-status-<?= $h($trip['status']) ?>"><?= $h($statusLabels[$trip['status']] ?? $trip['status']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($sharedTrips): ?>
                <h3 class="list-heading" id="shared-heading"><?= $showArchived ? 'Archived shared trips' : 'Shared with you' ?> <span class="list-count"><?= count($sharedTrips) ?></span></h3>
                <ul class="journey-list" data-trip-list aria-labelledby="shared-heading">
                    <?php foreach ($sharedTrips as $sharedTrip): ?>
                        <?php $start = strtotime($sharedTrip['start_date']); ?>
                        <li class="journey"
                            data-name="<?= $h(mb_strtolower($sharedTrip['name'])) ?>"
                            data-destination="<?= $h(mb_strtolower($sharedTrip['destination'])) ?>"
                            data-status="<?= $h($sharedTrip['status']) ?>"
                            data-start="<?= $h($sharedTrip['start_date']) ?>">
                            <div class="journey-date journey-<?= $h($sharedTrip['status']) ?>" aria-hidden="true">
                                <span><?= $h(date('M', $start)) ?></span><strong><?= $h(date('j', $start)) ?></strong><small><?= $h(date('Y', $start)) ?></small>
                            </div>
                            <div class="journey-body">
                                <h4><a class="journey-link" href="/customer/trips/view.php?id=<?= (int) $sharedTrip['id'] ?>"><?= $h($sharedTrip['name']) ?></a></h4>
                                <p class="journey-destination"><?= $h($sharedTrip['destination']) ?></p>
                                <p class="journey-meta"><span><?= $h($dateRange($sharedTrip['start_date'], $sharedTrip['end_date'])) ?></span><span>Your role: <?= $h(ucfirst($sharedTrip['role'])) ?></span></p>
                            </div>
                            <span class="chip chip-status-<?= $h($sharedTrip['status']) ?>"><?= $h($statusLabels[$sharedTrip['status']] ?? $sharedTrip['status']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p id="trip-no-results" class="home-no-results" role="status" hidden>No trips match your search.</p>
        </section>
    </main>

    <?php require __DIR__ . '/../includes/partials/chat_widget.php'; ?>
    <?php require __DIR__ . '/../includes/partials/support_chathead.php'; ?>
    <?php require __DIR__ . '/../includes/partials/support_launcher.php'; ?>

    <script>
        (function () {
            const lists = Array.from(document.querySelectorAll('[data-trip-list]'));
            const cards = lists.flatMap((list) => Array.from(list.querySelectorAll('.journey')));
            if (!cards.length) return;

            const searchInput = document.getElementById('trip-search');
            const statusFilter = document.getElementById('trip-status-filter');
            const sortSelect = document.getElementById('trip-sort');
            const noResults = document.getElementById('trip-no-results');

            function applyFilters() {
                const query = searchInput.value.trim().toLowerCase();
                const status = statusFilter.value;
                let visibleCount = 0;

                cards.forEach((card) => {
                    const matchesQuery = !query || card.dataset.name.includes(query) || card.dataset.destination.includes(query);
                    const matchesStatus = !status || card.dataset.status === status;
                    const visible = matchesQuery && matchesStatus;
                    card.hidden = !visible;
                    if (visible) visibleCount++;
                });

                noResults.hidden = visibleCount !== 0;
            }

            function applySort() {
                const sortBy = sortSelect.value;
                lists.forEach((list) => {
                    const sorted = Array.from(list.querySelectorAll('.journey')).sort((a, b) => {
                        if (sortBy === 'date-asc') return a.dataset.start.localeCompare(b.dataset.start);
                        if (sortBy === 'date-desc') return b.dataset.start.localeCompare(a.dataset.start);
                        if (sortBy === 'name-asc') return a.dataset.name.localeCompare(b.dataset.name);
                        return 0;
                    });
                    sorted.forEach((card) => list.appendChild(card));
                });
            }

            searchInput.addEventListener('input', applyFilters);
            statusFilter.addEventListener('change', applyFilters);
            sortSelect.addEventListener('change', applySort);
        })();
    </script>
</body>
</html>
