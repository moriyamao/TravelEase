<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/trip_validation.php';
require_once __DIR__ . '/../../includes/itinerary_validation.php';

require_role(['customer']);

$itemId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($itemId === false || $itemId === null) {
    http_response_code(400);
    die('Invalid item.');
}

$pdo = get_db_connection();

/**
 * Loads an itinerary item joined through its trip, checking the
 * trip's ownership in the same query. If the item exists but belongs
 * to another user's trip, this returns null -- indistinguishable from
 * "doesn't exist" to the requester, which is the correct behavior.
 */
function load_owned_item(PDO $pdo, int $itemId, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT i.id, i.trip_id, i.item_date, i.item_time, i.title, i.location, i.notes,
                i.estimated_cost, i.actual_cost,
                t.start_date AS trip_start, t.end_date AS trip_end, t.name AS trip_name,
                t.budget_currency
         FROM itinerary_items i
         INNER JOIN trips t ON t.id = i.trip_id
         WHERE i.id = :id AND t.user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute(['id' => $itemId, 'user_id' => $userId]);
    $item = $stmt->fetch();
    return $item ?: null;
}

$item = load_owned_item($pdo, $itemId, current_user_id());
if (!$item) {
    http_response_code(404);
    die('Item not found.');
}

$errors   = [];
$itemDate = $item['item_date'];
$itemTime = $item['item_time'] ?? '';
$title     = $item['title'];
$location  = $item['location'] ?? '';
$notes     = $item['notes'] ?? '';
$estimated = $item['estimated_cost'] !== null ? (string) $item['estimated_cost'] : '';
$actual    = $item['actual_cost'] !== null ? (string) $item['actual_cost'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $itemDate  = trim($_POST['item_date'] ?? '');
        $itemTime  = trim($_POST['item_time'] ?? '');
        $title     = trim($_POST['title'] ?? '');
        $location  = trim($_POST['location'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');
        $estimated = trim($_POST['estimated_cost'] ?? '');
        $actual    = trim($_POST['actual_cost'] ?? '');

        if ($err = validate_item_date($itemDate, $item['trip_start'], $item['trip_end'])) $errors[] = $err;
        if ($err = validate_item_time($itemTime))     $errors[] = $err;
        if ($err = validate_item_title($title))       $errors[] = $err;
        if ($err = validate_item_location($location)) $errors[] = $err;
        if ($err = validate_item_notes($notes))       $errors[] = $err;
        if ($err = validate_money_amount($estimated, 'Estimated cost')) $errors[] = $err;
        if ($err = validate_money_amount($actual, 'Actual cost'))       $errors[] = $err;

        if (empty($errors)) {
            try {
                // WHERE joins through trips again on the UPDATE itself --
                // not relying solely on the earlier SELECT for ownership.
                $stmt = $pdo->prepare(
                    'UPDATE itinerary_items i
                     INNER JOIN trips t ON t.id = i.trip_id
                     SET i.item_date = :item_date, i.item_time = :item_time,
                         i.title = :title, i.location = :location, i.notes = :notes,
                         i.estimated_cost = :estimated_cost, i.actual_cost = :actual_cost
                     WHERE i.id = :id AND t.user_id = :user_id'
                );
                $stmt->execute([
                    'item_date'      => $itemDate,
                    'item_time'      => $itemTime !== '' ? $itemTime : null,
                    'title'          => $title,
                    'location'       => $location !== '' ? $location : null,
                    'notes'          => $notes !== '' ? $notes : null,
                    'estimated_cost' => $estimated !== '' ? $estimated : null,
                    'actual_cost'    => $actual !== '' ? $actual : null,
                    'id'             => $itemId,
                    'user_id'        => current_user_id(),
                ]);

                header('Location: /customer/trips/view.php?id=' . $item['trip_id']);
                exit;

            } catch (PDOException $e) {
                error_log('TravelEase: itinerary item update failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong while saving. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Itinerary Item — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1>Edit Item</h1>
            <a href="/customer/trips/view.php?id=<?= (int) $item['trip_id'] ?>" class="logout-link">Cancel</a>
        </header>

        <div class="form-card">
            <?php if (!empty($errors)): ?>
                <ul class="form-errors" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <form method="post" action="/customer/trips/itinerary_edit.php?id=<?= (int) $itemId ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $itemId ?>">

                <label for="title">Title</label>
                <input type="text" id="title" name="title" required maxlength="150"
                       value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-row">
                    <div>
                        <label for="item_date">Date</label>
                        <input type="date" id="item_date" name="item_date" required
                               min="<?= htmlspecialchars($item['trip_start'], ENT_QUOTES, 'UTF-8') ?>"
                               max="<?= htmlspecialchars($item['trip_end'], ENT_QUOTES, 'UTF-8') ?>"
                               value="<?= htmlspecialchars($itemDate, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="item_time">Time (optional)</label>
                        <input type="time" id="item_time" name="item_time"
                               value="<?= htmlspecialchars($itemTime, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>

                <label for="location">Location (optional)</label>
                <input type="text" id="location" name="location" maxlength="150"
                       value="<?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-row">
                    <div>
                        <label for="estimated_cost">Estimated cost in <?= htmlspecialchars($item['budget_currency'], ENT_QUOTES, 'UTF-8') ?> (optional)</label>
                        <input type="text" inputmode="decimal" id="estimated_cost" name="estimated_cost"
                               placeholder="e.g. 1500.00"
                               value="<?= htmlspecialchars($estimated, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="actual_cost">Actual cost in <?= htmlspecialchars($item['budget_currency'], ENT_QUOTES, 'UTF-8') ?> (optional)</label>
                        <input type="text" inputmode="decimal" id="actual_cost" name="actual_cost"
                               placeholder="e.g. 1620.00"
                               value="<?= htmlspecialchars($actual, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>

                <label for="notes">Notes (optional)</label>
                <textarea id="notes" name="notes" rows="3" maxlength="2000"><?= htmlspecialchars($notes, ENT_QUOTES, 'UTF-8') ?></textarea>

                <button type="submit">Save Changes</button>
            </form>
        </div>
    </main>
</body>
</html>
