<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

require_role(['customer']);

$pdo = get_db_connection();

// Ownership scoping: only ever fetch trips belonging to the logged-in
// user. There is no code path here that accepts a user_id from the client.
$stmt = $pdo->prepare(
    'SELECT id, name, destination, start_date, end_date, status
     FROM trips
     WHERE user_id = :user_id
     ORDER BY start_date ASC'
);
$stmt->execute(['user_id' => current_user_id()]);
$trips = $stmt->fetchAll();

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
    if ($nextTrip === null && $trip['start_date'] >= $today && $trip['status'] !== 'cancelled') {
        $nextTrip = $trip;
    }
}

$daysUntilNextTrip = $nextTrip
    ? max(0, (int) ((strtotime($nextTrip['start_date']) - strtotime($today)) / 86400))
    : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Trips — TravelEase</title>
    <link rel="icon" href="/assets/images/travelease-logo-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/chat-widget.css">
</head>
<body>
    <main class="dashboard customer-home">
        <header class="dashboard-header customer-header">
            <a class="site-brand" href="/customer/dashboard.php" aria-label="TravelEase home">
                <img src="/assets/images/travelease-logo-mark.svg" alt="">
                <span><strong>TravelEase</strong><small>Personal travel desk</small></span>
            </a>
            <div class="header-actions">
                <span class="welcome-text">Welcome, <?= $name ?></span>
                <a href="/profile.php" class="logout-link">Profile</a>
                <a href="/auth/logout.php" class="logout-link">Sign out</a>
            </div>
        </header>

        <?php foreach ($unreadReplies as $reply): ?>
            <div class="support-reply-alert">
                <div>
                    <strong>Support replied to your request</strong>
                    <p><?= nl2br(htmlspecialchars($reply['staff_reply'], ENT_QUOTES, 'UTF-8')) ?></p>
                </div>
                <form method="POST" action="/customer/dismiss_escalation_reply.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="escalation_id" value="<?= (int) $reply['id'] ?>">
                    <button type="submit" class="link-danger">Dismiss</button>
                </form>
            </div>
        <?php endforeach; ?>

        <section class="customer-welcome" aria-labelledby="welcome-heading">
            <div>
                <p class="eyebrow">Your travel desk</p>
                <h1 id="welcome-heading">Welcome back, <?= $name ?>.</h1>
                <p>Keep every route, date, and travel detail together in one calm place.</p>
            </div>
            <a href="/customer/trips/create.php" class="btn-primary">+ Plan a trip</a>
        </section>

        <?php if ($nextTrip): ?>
            <section class="travel-overview" aria-label="Upcoming trip overview">
                <article class="featured-trip">
                    <div class="featured-trip-content">
                        <div>
                            <p class="featured-label">Next journey</p>
                            <h2><?= htmlspecialchars($nextTrip['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <p class="featured-destination">↗ <?= htmlspecialchars($nextTrip['destination'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <div class="featured-trip-footer">
                            <div>
                                <span>Travel dates</span>
                                <strong>
                                    <?= htmlspecialchars(date('M j', strtotime($nextTrip['start_date'])), ENT_QUOTES, 'UTF-8') ?>
                                    —
                                    <?= htmlspecialchars(date('M j, Y', strtotime($nextTrip['end_date'])), ENT_QUOTES, 'UTF-8') ?>
                                </strong>
                            </div>
                            <a href="/customer/trips/view.php?id=<?= (int) $nextTrip['id'] ?>">Open trip →</a>
                        </div>
                    </div>
                </article>

                <div class="overview-side">
                    <article class="countdown-card">
                        <span>Departure countdown</span>
                        <div><strong><?= $daysUntilNextTrip ?></strong><small><?= $daysUntilNextTrip === 1 ? 'day to go' : 'days to go' ?></small></div>
                        <p>Your trip is currently <?= htmlspecialchars(strtolower($statusLabels[$nextTrip['status']] ?? $nextTrip['status']), ENT_QUOTES, 'UTF-8') ?>.</p>
                    </article>
                    <div class="trip-stats" aria-label="Trip statistics">
                        <div class="trip-stat"><strong><?= $statusCounts['planning'] ?></strong><span>Planning</span></div>
                        <div class="trip-stat"><strong><?= $statusCounts['confirmed'] ?></strong><span>Confirmed</span></div>
                        <div class="trip-stat"><strong><?= $statusCounts['completed'] ?></strong><span>Completed</span></div>
                        <div class="trip-stat"><strong><?= $tripCount ?></strong><span>Total</span></div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="journeys-section" aria-labelledby="journeys-heading">
            <div class="journeys-heading-row">
                <div>
                    <h2 id="journeys-heading">Your journeys</h2>
                    <p>One place for every route, date, and plan.</p>
                </div>
                <?php if (!empty($trips)): ?>
                    <div class="trip-filter-bar">
                        <label class="sr-only" for="trip-search">Search trips</label>
                        <input type="search" id="trip-search" placeholder="Search trips or places">
                        <label class="sr-only" for="trip-status-filter">Filter by status</label>
                        <select id="trip-status-filter">
                            <option value="">All statuses</option>
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
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

            <?php if (empty($trips)): ?>
                <div class="empty-state">
                    <img src="/assets/images/travelease-logo-mark.svg" alt="">
                    <h2>Your next journey starts here.</h2>
                    <p>Create your first trip to organize destinations, dates, activities, and budget.</p>
                    <a href="/customer/trips/create.php" class="btn-primary">Plan your first trip</a>
                </div>
            <?php else: ?>
                <div class="journeys-layout">
                    <div class="trip-grid" id="trip-grid">
                        <?php foreach ($trips as $trip): ?>
                            <a class="trip-card"
                               href="/customer/trips/view.php?id=<?= (int) $trip['id'] ?>"
                               data-name="<?= htmlspecialchars(mb_strtolower($trip['name']), ENT_QUOTES, 'UTF-8') ?>"
                               data-destination="<?= htmlspecialchars(mb_strtolower($trip['destination']), ENT_QUOTES, 'UTF-8') ?>"
                               data-status="<?= htmlspecialchars($trip['status'], ENT_QUOTES, 'UTF-8') ?>"
                               data-start="<?= htmlspecialchars($trip['start_date'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="trip-card-visual" aria-hidden="true"><span></span></div>
                                <div class="trip-card-content">
                                    <span class="status-badge status-<?= htmlspecialchars($trip['status'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($statusLabels[$trip['status']] ?? $trip['status'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <h2><?= htmlspecialchars($trip['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                                    <p class="trip-destination"><?= htmlspecialchars($trip['destination'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <dl class="trip-card-dates">
                                        <div><dt>Starts</dt><dd><?= htmlspecialchars(date('M j, Y', strtotime($trip['start_date'])), ENT_QUOTES, 'UTF-8') ?></dd></div>
                                        <div><dt>Ends</dt><dd><?= htmlspecialchars(date('M j, Y', strtotime($trip['end_date'])), ENT_QUOTES, 'UTF-8') ?></dd></div>
                                    </dl>
                                </div>
                                <span class="trip-card-arrow" aria-hidden="true">↗</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <aside class="quick-actions">
                        <h3>Continue planning</h3>
                        <p>Jump back into the tools already available in TravelEase.</p>
                        <?php if ($nextTrip): ?>
                            <a href="/customer/trips/view.php?id=<?= (int) $nextTrip['id'] ?>">Review itinerary <span>→</span></a>
                            <a href="/customer/trips/view.php?id=<?= (int) $nextTrip['id'] ?>">Check travel budget <span>→</span></a>
                        <?php endif; ?>
                        <button type="button" id="dashboard-open-chat">Ask TravelEase support <span>→</span></button>
                    </aside>
                </div>
                <p id="trip-no-results">No trips match your search.</p>
            <?php endif; ?>
        </section>
    </main>

    <?php require __DIR__ . '/../includes/partials/chat_widget.php'; ?>
    <?php require __DIR__ . '/../includes/partials/support_chathead.php'; ?>

    <script>
        (function () {
            const grid = document.getElementById('trip-grid');
            const dashboardChatButton = document.getElementById('dashboard-open-chat');

            if (dashboardChatButton) {
                dashboardChatButton.addEventListener('click', function () {
                    document.getElementById('te-chat-toggle')?.click();
                });
            }

            if (!grid) return;

            const searchInput = document.getElementById('trip-search');
            const statusFilter = document.getElementById('trip-status-filter');
            const sortSelect = document.getElementById('trip-sort');
            const noResults = document.getElementById('trip-no-results');
            const cards = Array.from(grid.querySelectorAll('.trip-card'));

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

                noResults.style.display = visibleCount === 0 ? 'block' : 'none';
            }

            function applySort() {
                const sortBy = sortSelect.value;
                const sorted = [...cards].sort((a, b) => {
                    if (sortBy === 'date-asc') return a.dataset.start.localeCompare(b.dataset.start);
                    if (sortBy === 'date-desc') return b.dataset.start.localeCompare(a.dataset.start);
                    if (sortBy === 'name-asc') return a.dataset.name.localeCompare(b.dataset.name);
                    return 0;
                });
                sorted.forEach((card) => grid.appendChild(card));
            }

            searchInput.addEventListener('input', applyFilters);
            statusFilter.addEventListener('change', applyFilters);
            sortSelect.addEventListener('change', applySort);
        })();
    </script>
</body>
</html>
