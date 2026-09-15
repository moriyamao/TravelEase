<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

require_role(['customer']);

$tripId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($tripId === false || $tripId === null) {
    http_response_code(400);
    die('Invalid trip.');
}

$pdo = get_db_connection();

// Ownership check is part of the WHERE clause itself, not a separate
// "is this mine?" check after the fact -- a trip belonging to another
// user simply does not match this query, full stop (§26 -- IDOR
// prevention). Changing ?id= in the URL cannot expose another user's
// trip.
$stmt = $pdo->prepare(
    'SELECT id, name, destination, start_date, end_date, status, notes, created_at
     FROM trips
     WHERE id = :id AND user_id = :user_id
     LIMIT 1'
);
$stmt->execute([
    'id'      => $tripId,
    'user_id' => current_user_id(),
]);
$trip = $stmt->fetch();

if (!$trip) {
    http_response_code(404);
    die('Trip not found.');
}

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
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1><?= htmlspecialchars($trip['name'], ENT_QUOTES, 'UTF-8') ?></h1>
            <a href="/customer/dashboard.php" class="logout-link">Back to My Trips</a>
        </header>

        <div class="trip-detail-card">
            <span class="status-badge status-<?= htmlspecialchars($trip['status'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($statusLabels[$trip['status']] ?? $trip['status'], ENT_QUOTES, 'UTF-8') ?>
            </span>

            <dl class="trip-detail-list">
                <dt>Destination</dt>
                <dd><?= htmlspecialchars($trip['destination'], ENT_QUOTES, 'UTF-8') ?></dd>

                <dt>Dates</dt>
                <dd><?= htmlspecialchars($trip['start_date'], ENT_QUOTES, 'UTF-8') ?> &ndash; <?= htmlspecialchars($trip['end_date'], ENT_QUOTES, 'UTF-8') ?></dd>

                <?php if (!empty($trip['notes'])): ?>
                    <dt>Notes</dt>
                    <dd class="trip-notes"><?= nl2br(htmlspecialchars($trip['notes'], ENT_QUOTES, 'UTF-8')) ?></dd>
                <?php endif; ?>
            </dl>

            <div class="trip-detail-actions">
                <a href="/customer/trips/edit.php?id=<?= (int) $trip['id'] ?>" class="btn-primary">Edit</a>
                <form method="post" action="/customer/trips/delete.php"
                      onsubmit="return confirm('Delete this trip? This cannot be undone.');">
                    <?php require_once __DIR__ . '/../../includes/csrf.php'; ?>
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $trip['id'] ?>">
                    <button type="submit" class="btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </main>
</body>
</html>
