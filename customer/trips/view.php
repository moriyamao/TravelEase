<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

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

// Itinerary items for this trip -- the trip was already confirmed to
// belong to the logged-in user above, so this second query is safe
// to filter by trip_id alone.
$stmt = $pdo->prepare(
    'SELECT id, item_date, item_time, title, location, notes
     FROM itinerary_items
     WHERE trip_id = :trip_id
     ORDER BY item_date ASC, item_time ASC'
);
$stmt->execute(['trip_id' => $tripId]);
$items = $stmt->fetchAll();

// Group items by date for the day-by-day display.
$itemsByDate = [];
foreach ($items as $item) {
    $itemsByDate[$item['item_date']][] = $item;
}

$itineraryErrors = $_SESSION['itinerary_errors'] ?? [];
unset($_SESSION['itinerary_errors']);

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
    <link rel="stylesheet" href="/assets/css/auth.css">
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
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $trip['id'] ?>">
                    <button type="submit" class="btn-danger">Delete</button>
                </form>
            </div>
        </div>

        <section class="itinerary-section">
            <h2>Itinerary</h2>

            <?php if (!empty($itineraryErrors)): ?>
                <ul class="form-errors" role="alert">
                    <?php foreach ($itineraryErrors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (empty($itemsByDate)): ?>
                <p class="empty-state">No itinerary items yet. Add your first stop below.</p>
            <?php else: ?>
                <?php foreach ($itemsByDate as $date => $dayItems): ?>
                    <div class="itinerary-day">
                        <h3 class="itinerary-day-heading"><?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?></h3>
                        <ul class="itinerary-list">
                            <?php foreach ($dayItems as $item): ?>
                                <li class="itinerary-item">
                                    <div class="itinerary-item-main">
                                        <?php if (!empty($item['item_time'])): ?>
                                            <span class="itinerary-time"><?= htmlspecialchars(substr($item['item_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                        <span class="itinerary-title"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (!empty($item['location'])): ?>
                                            <span class="itinerary-location">— <?= htmlspecialchars($item['location'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($item['notes'])): ?>
                                        <p class="itinerary-notes"><?= nl2br(htmlspecialchars($item['notes'], ENT_QUOTES, 'UTF-8')) ?></p>
                                    <?php endif; ?>
                                    <div class="itinerary-item-actions">
                                        <a href="/customer/trips/itinerary_edit.php?id=<?= (int) $item['id'] ?>">Edit</a>
                                        <form method="post" action="/customer/trips/delete_itinerary.php"
                                              onsubmit="return confirm('Delete this item?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                            <button type="submit" class="link-danger">Delete</button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

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
                            <label for="item_time">Time (optional)</label>
                            <input type="time" id="item_time" name="item_time">
                        </div>
                    </div>

                    <label for="location">Location (optional)</label>
                    <input type="text" id="location" name="location" maxlength="150">

                    <label for="notes">Notes (optional)</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="2000"></textarea>

                    <button type="submit">Add Item</button>
                </form>
            </details>
        </section>
    </main>
</body>
</html>
