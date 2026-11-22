<?php
/**
 * FR-32: read-only view of a trip its owner shared with support.
 * Allowed only while the share is active, the support request is open and
 * the sharer still owns the trip. Shows trip details and itinerary only:
 * no comments, votes, expenses, booking references, reminders or member
 * identities.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/itinerary_validation.php';
require_once __DIR__ . '/../includes/support_access.php';

require_role(['staff', 'admin']);

$escalationId = filter_input(INPUT_GET, 'escalation_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$tripId = filter_input(INPUT_GET, 'trip_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$escalationId || !$tripId) {
    http_response_code(400);
    exit('Invalid request.');
}

$pdo = get_db_connection();
if (!staff_can_view_shared_trip($pdo, $escalationId, $tripId)) {
    http_response_code(404);
    exit('This trip is not shared with support for this request.');
}

$stmt = $pdo->prepare('SELECT name, destination, start_date, end_date, status, time_zone FROM trips WHERE id = :id');
$stmt->execute(['id' => $tripId]);
$trip = $stmt->fetch();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM trip_memberships WHERE trip_id = :trip');
$stmt->execute(['trip' => $tripId]);
$memberCount = max(1, (int) $stmt->fetchColumn());

$stmt = $pdo->prepare(
    'SELECT item_date, item_time, end_time, title, category, location, notes, completed_at
     FROM itinerary_items WHERE trip_id = :trip
     ORDER BY item_date ASC, ' . ITINERARY_DAY_ORDER_SQL
);
$stmt->execute(['trip' => $tripId]);
$itemsByDate = [];
foreach ($stmt->fetchAll() as $item) {
    $itemsByDate[$item['item_date']][] = $item;
}

$e = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$time = fn(?string $t) => $t === null ? null : substr($t, 0, 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shared trip — Support — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/support-access.css">
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1>Shared trip</h1>
            <a href="/staff/escalations.php" class="logout-link">Back to Support Queue</a>
        </header>
        <p class="support-note">Read-only. Shared by the trip owner for support request #<?= (int) $escalationId ?>. Access ends when they stop sharing or the request is resolved.</p>

        <section class="support-card" aria-labelledby="trip-heading">
            <h2 id="trip-heading"><?= $e($trip['name']) ?></h2>
            <dl class="support-details">
                <dt>Destination</dt><dd><?= $e($trip['destination']) ?></dd>
                <dt>Dates</dt><dd><?= $e($trip['start_date']) ?> – <?= $e($trip['end_date']) ?></dd>
                <dt>Status</dt><dd><?= $e(ucfirst($trip['status'])) ?></dd>
                <dt>Time zone</dt><dd><?= $e(str_replace('_', ' ', $trip['time_zone'])) ?></dd>
                <dt>Travelers</dt><dd><?= $memberCount ?></dd>
            </dl>
        </section>

        <section class="support-card" aria-labelledby="itinerary-heading">
            <h2 id="itinerary-heading">Itinerary</h2>
            <?php if (!$itemsByDate): ?>
                <p class="empty-state">No itinerary activities.</p>
            <?php else: ?>
                <?php foreach ($itemsByDate as $date => $items): ?>
                    <h3 class="support-day"><?= $e(date('D, M j, Y', strtotime($date))) ?></h3>
                    <ul class="support-itinerary">
                        <?php foreach ($items as $item): ?>
                            <li>
                                <span class="support-time"><?= $item['item_time'] !== null ? $e($time($item['item_time']) . ($item['end_time'] !== null ? '–' . $time($item['end_time']) : '')) : 'Any time' ?></span>
                                <div>
                                    <strong><?= $e($item['title']) ?></strong><?= $item['completed_at'] !== null ? ' <span class="support-shared">Done</span>' : '' ?>
                                    <p class="support-trip-meta"><?= $e(ITINERARY_CATEGORIES[$item['category'] ?? ''] ?? 'Uncategorized') ?><?= $item['location'] !== null && $item['location'] !== '' ? ' &middot; ' . $e($item['location']) : '' ?></p>
                                    <?php if ($item['notes'] !== null && $item['notes'] !== ''): ?><p class="support-notes"><?= nl2br($e($item['notes'])) ?></p><?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
