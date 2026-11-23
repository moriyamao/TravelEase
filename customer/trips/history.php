<?php
/**
 * FR-18: trip activity history. Visible to every trip member (any role
 * with the 'view' permission); non-members get 404 like the trip page.
 * Kept on its own page so the trip page does not pay an extra query.
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_activity.php';

require_role(['customer']);

$tripId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$tripId) {
    http_response_code(400);
    die('Invalid trip.');
}

$pdo = get_db_connection();
require_trip_permission($pdo, $tripId, 'view');

$stmt = $pdo->prepare(
    'SELECT t.name, l.event_type, l.details, l.created_at,
            l.actor_id, a.name AS actor_name, l.target_user_id, u.name AS target_name
     FROM trips t
     LEFT JOIN trip_activity_log l ON l.trip_id = t.id
     LEFT JOIN users a ON a.id = l.actor_id
     LEFT JOIN users u ON u.id = l.target_user_id
     WHERE t.id = :trip_id
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT 100'
);
$stmt->execute(['trip_id' => $tripId]);
$rows = $stmt->fetchAll();
$tripName = $rows[0]['name'] ?? '';
$events = array_values(array_filter($rows, fn($r) => $r['event_type'] !== null));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity history — <?= htmlspecialchars($tripName, ENT_QUOTES, 'UTF-8') ?> — TravelEase</title>
    <link rel="icon" href="/assets/images/travelease-logo-mark.svg?v=2" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/pages.css">
</head>
<body>
    <?php $navCurrent = null; require __DIR__ . '/../../includes/partials/app_header.php'; ?>
    <main class="app-page legacy-page" id="main-content" tabindex="-1">
        <?php $tripId = (int) $tripId; $tripTab = 'history'; $tripPageTitle = 'Activity history'; $tripPageLead = null; require __DIR__ . '/../../includes/partials/trip_header.php'; ?>

        <section class="trip-history">
            <p class="eyebrow"><?= htmlspecialchars($tripName, ENT_QUOTES, 'UTF-8') ?></p>
            <?php if (!$events): ?>
                <p class="empty-state">No activity recorded yet. Changes made from now on will appear here.</p>
            <?php else: ?>
                <ol class="trip-history-list">
                    <?php foreach ($events as $event): ?>
                        <li>
                            <span class="trip-history-time"><?= htmlspecialchars(gmdate('M j, Y H:i', strtotime($event['created_at'] . ' UTC')), ENT_QUOTES, 'UTF-8') ?> UTC</span>
                            <span class="trip-history-text"><?= htmlspecialchars(trip_activity_sentence($event), ENT_QUOTES, 'UTF-8') ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if (count($events) === 100): ?>
                    <p class="trip-history-note">Showing up to the latest 100 events.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
