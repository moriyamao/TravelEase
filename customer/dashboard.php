<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

require_role(['customer']);

$pdo = get_db_connection();

// Ownership scoping: only ever fetch trips belonging to the logged-in
// user. There is no code path here that accepts a user_id from the
// client (§26 -- IDOR prevention).
$stmt = $pdo->prepare(
    'SELECT id, name, destination, start_date, end_date, status
     FROM trips
     WHERE user_id = :user_id
     ORDER BY start_date ASC'
);
$stmt->execute(['user_id' => current_user_id()]);
$trips = $stmt->fetchAll();

// Unread staff replies to this customer's own support requests --
// same ownership scoping as everything else (user_id = current user).
// Only shown once: customer_seen_at is set the moment they dismiss it.
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Trips — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/chat-widget.css">
    <style>
        .trip-filter-bar { display: flex; gap: 0.75rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
        .trip-filter-bar input[type="text"] { flex: 1; min-width: 180px; padding: 0.5rem 0.75rem; border: 1px solid var(--line); border-radius: 6px; font-size: 0.9rem; }
        .trip-filter-bar select { padding: 0.5rem 0.75rem; border: 1px solid var(--line); border-radius: 6px; font-size: 0.9rem; background: var(--paper-white); }
        .trip-card[hidden] { display: none; }
        #trip-no-results { display: none; color: var(--ink-muted); padding: 2rem 0; text-align: center; }
    </style>
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1>My Trips</h1>
            <div class="header-actions">
                <span class="welcome-text">Welcome, <?= $name ?></span>
                <a href="/profile.php" class="logout-link">Profile</a>
                <a href="/auth/logout.php" class="logout-link">Sign out</a>
            </div>
        </header>

        <?php foreach ($unreadReplies as $reply): ?>
            <div class="form-errors" style="background: var(--paper-white); border-color: var(--route); color: var(--ink);">
                <strong>Support replied to your request:</strong>
                <p style="margin: 0.4rem 0;"><?= nl2br(htmlspecialchars($reply['staff_reply'], ENT_QUOTES, 'UTF-8')) ?></p>
                <form method="POST" action="/customer/dismiss_escalation_reply.php" style="margin: 0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="escalation_id" value="<?= (int) $reply['id'] ?>">
                    <button type="submit" class="link-danger" style="color: var(--route);">Dismiss</button>
                </form>
            </div>
        <?php endforeach; ?>

        <section class="trip-overview" aria-labelledby="journey-heading">
            <div>
                <p class="eyebrow">Your travel desk</p>
                <h2 id="journey-heading">Every good journey starts with a thoughtful plan.</h2>
                <p class="trip-overview-copy">Keep your routes, days, and travel budget in one calm, useful place.</p>
            </div>
            <span class="trip-count"><?= $tripCount ?> <?= $tripCount === 1 ? 'journey' : 'journeys' ?></span>
        </section>

        <div class="trips-toolbar">
            <p class="section-label">Your journeys</p>
            <a href="/customer/trips/create.php" class="btn-primary">+ Plan a trip</a>
        </div>

        <?php if (empty($trips)): ?>
            <p class="empty-state">
                You haven't planned any trips yet. Click "New Trip" to get started.
            </p>
        <?php else: ?>
            <div class="trip-filter-bar">
                <input type="text" id="trip-search" placeholder="Search by name or destination...">
                <select id="trip-status-filter">
                    <option value="">All statuses</option>
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="trip-sort">
                    <option value="date-asc">Date (soonest first)</option>
                    <option value="date-desc">Date (latest first)</option>
                    <option value="name-asc">Name (A–Z)</option>
                </select>
            </div>

            <div class="trip-grid" id="trip-grid">
                <?php foreach ($trips as $trip): ?>
                    <a class="trip-card"
                       href="/customer/trips/view.php?id=<?= (int) $trip['id'] ?>"
                       data-name="<?= htmlspecialchars(mb_strtolower($trip['name']), ENT_QUOTES, 'UTF-8') ?>"
                       data-destination="<?= htmlspecialchars(mb_strtolower($trip['destination']), ENT_QUOTES, 'UTF-8') ?>"
                       data-status="<?= htmlspecialchars($trip['status'], ENT_QUOTES, 'UTF-8') ?>"
                       data-start="<?= htmlspecialchars($trip['start_date'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="trip-card-header">
                            <h2><?= htmlspecialchars($trip['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <span class="status-badge status-<?= htmlspecialchars($trip['status'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($statusLabels[$trip['status']] ?? $trip['status'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                        <div class="trip-card-body">
                            <p class="trip-destination"><?= htmlspecialchars($trip['destination'], ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="trip-dates">
                                <?= htmlspecialchars(date('M j, Y', strtotime($trip['start_date'])), ENT_QUOTES, 'UTF-8') ?>
                                &mdash;
                                <?= htmlspecialchars(date('M j, Y', strtotime($trip['end_date'])), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            <p id="trip-no-results">No trips match your search.</p>
        <?php endif; ?>
    </main>

    <?php require __DIR__ . '/../includes/partials/chat_widget.php'; ?>
    <?php require __DIR__ . '/../includes/partials/support_chathead.php'; ?>

    <script>
        (function () {
            const grid = document.getElementById('trip-grid');
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
                    const matchesQuery = !query ||
                        card.dataset.name.includes(query) ||
                        card.dataset.destination.includes(query);
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