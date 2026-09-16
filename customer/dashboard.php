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
            <div class="trip-grid">
                <?php foreach ($trips as $trip): ?>
                    <a class="trip-card" href="/customer/trips/view.php?id=<?= (int) $trip['id'] ?>">
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
        <?php endif; ?>
    </main>

    <?php require __DIR__ . '/../includes/partials/chat_widget.php'; ?>
</body>
</html>
