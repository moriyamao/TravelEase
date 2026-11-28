<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/trip_validation.php';
require_once __DIR__ . '/../../includes/itinerary_validation.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_activity.php';

require_role(['customer']);

$itemId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($itemId === false || $itemId === null) {
    http_response_code(400);
    die('Invalid item.');
}

$pdo = get_db_connection();

$stmt = $pdo->prepare(
    'SELECT i.id, i.trip_id, i.item_date, i.item_time, i.end_time, i.title, i.location, i.notes,
            i.estimated_cost, i.actual_cost, i.category, i.assignee_id, i.version,
            t.start_date AS trip_start, t.end_date AS trip_end, t.name AS trip_name,
            t.budget_currency
     FROM itinerary_items i
     INNER JOIN trips t ON t.id = i.trip_id
     WHERE i.id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $itemId]);
$item = $stmt->fetch();
if (!$item) {
    http_response_code(404);
    die('Item not found.');
}

// Owners and editors only; anyone else gets the same 404 as a missing
// trip, so item IDs on other people's trips reveal nothing (§26).
require_trip_permission($pdo, (int) $item['trip_id'], 'edit_itinerary');

$errors   = [];
$itemDate = $item['item_date'];
$itemTime = $item['item_time'] ?? '';
$title     = $item['title'];
$location  = $item['location'] ?? '';
$notes     = $item['notes'] ?? '';
$estimated = $item['estimated_cost'] !== null ? (string) $item['estimated_cost'] : '';
$actual    = $item['actual_cost'] !== null ? (string) $item['actual_cost'] : '';
$endTime   = $item['end_time'] !== null ? substr($item['end_time'], 0, 5) : '';
$category  = $item['category'] ?? '';
$assigneeRaw = $item['assignee_id'] !== null ? (string) $item['assignee_id'] : '';
$formVersion = (string) $item['version'];
$submittedVersion = (int) $item['version'];
$staleConflict = false;

// Assignee choices: current customer members, plus the current assignee
// if they have since left (kept as a historical assignment).
$stmt = $pdo->prepare(
    'SELECT u.id, u.name FROM trips t JOIN users u ON u.id = t.user_id
     WHERE t.id = :owner_trip_id AND u.role = \'customer\'
     UNION
     SELECT u.id, u.name FROM trip_memberships m JOIN users u ON u.id = m.user_id
     WHERE m.trip_id = :member_trip_id AND u.role = \'customer\'
       AND m.role IN (\'editor\', \'commenter\', \'viewer\')
     ORDER BY name'
);
$stmt->execute(['owner_trip_id' => $item['trip_id'], 'member_trip_id' => $item['trip_id']]);
$assignable = $stmt->fetchAll();
$historicalAssignee = null;
if ($item['assignee_id'] !== null && !in_array((int) $item['assignee_id'], array_map('intval', array_column($assignable, 'id')), true)) {
    $stmt = $pdo->prepare('SELECT name FROM users WHERE id = :id');
    $stmt->execute(['id' => $item['assignee_id']]);
    $historicalAssignee = ['id' => (int) $item['assignee_id'], 'name' => (string) $stmt->fetchColumn()];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Preserve exactly what this form submitted on ordinary error re-renders.
    // Only the explicit 409 path below advances it to the current version.
    $formVersion = is_string($_POST['version'] ?? null) ? trim($_POST['version']) : '';
    $parsedVersion = filter_var($formVersion, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    $submittedVersion = $parsedVersion === false ? 0 : (int) $parsedVersion;

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        foreach (['item_date', 'item_time', 'title', 'location', 'notes',
                  'estimated_cost', 'actual_cost'] as $field) {
            if (isset($_POST[$field]) && !is_string($_POST[$field])) {
                http_response_code(400);
                exit('Invalid form input. Reload the page and try again.');
            }
        }
        $itemDate  = trim($_POST['item_date'] ?? '');
        $itemTime  = trim($_POST['item_time'] ?? '');
        $title     = trim($_POST['title'] ?? '');
        $location  = trim($_POST['location'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');
        $estimated = trim($_POST['estimated_cost'] ?? '');
        $actual    = trim($_POST['actual_cost'] ?? '');
        // FR-19 fields. A form that does not send them (older page) keeps
        // the activity's current end time, category and assignee.
        $postString = fn(string $key, string $current): string =>
            array_key_exists($key, $_POST) ? (is_string($_POST[$key]) ? trim($_POST[$key]) : "\0") : $current;
        $endTime     = $postString('end_time', $endTime);
        $category    = $postString('category', $category);
        $assigneeRaw = $postString('assignee_id', $assigneeRaw);
        $assigneeId  = parse_item_assignee($assigneeRaw);
        // An older page has no end-time field: if its new start no longer
        // fits the stored end time, drop the end time instead of failing
        // on a field the user cannot see.
        if (!array_key_exists('end_time', $_POST) && validate_item_end_time($itemTime, $endTime) !== null) {
            $endTime = '';
        }

        if ($err = validate_item_date($itemDate, $item['trip_start'], $item['trip_end'])) $errors[] = $err;
        if ($err = validate_item_time($itemTime))     $errors[] = $err;
        if ($err = validate_item_title($title))       $errors[] = $err;
        if ($err = validate_item_location($location)) $errors[] = $err;
        if ($err = validate_item_notes($notes))       $errors[] = $err;
        if ($err = validate_money_amount($estimated, 'Estimated cost')) $errors[] = $err;
        if ($err = validate_money_amount($actual, 'Actual cost'))       $errors[] = $err;
        if ($err = validate_item_end_time($itemTime, $endTime)) $errors[] = $err;
        if ($err = validate_item_category($category))  $errors[] = $err;
        if ($assigneeId === false) $errors[] = 'Choose a current trip member to assign.';

        if (empty($errors)) {
            try {
                // Re-check the role under the trip lock, then write.
                $pdo->beginTransaction();
                lock_trip_and_require_permission($pdo, (int) $item['trip_id'], 'edit_itinerary');

                // Revalidate against the current trip dates after acquiring
                // the lock shared with trip-date changes.
                $stmt = $pdo->prepare('SELECT start_date, end_date FROM trips WHERE id = :id FOR UPDATE');
                $stmt->execute(['id' => $item['trip_id']]);
                $currentTrip = $stmt->fetch();
                if ($err = validate_item_date($itemDate, $currentTrip['start_date'], $currentTrip['end_date'])) {
                    throw new DomainException($err);
                }

                // Re-read the activity under the lock (it may have been
                // deleted or changed since this form was opened).
                $stmt = $pdo->prepare(
                    'SELECT item_date, assignee_id, sort_order, version FROM itinerary_items
                     WHERE id = :id AND trip_id = :trip_id FOR UPDATE'
                );
                $stmt->execute(['id' => $itemId, 'trip_id' => $item['trip_id']]);
                $current = $stmt->fetch();
                if (!$current) {
                    $pdo->rollBack();
                    http_response_code(404);
                    die('Item not found.');
                }

                if ($submittedVersion !== (int) $current['version']) {
                    $formVersion = (string) $current['version'];
                    $staleConflict = true;
                    http_response_code(409);
                    throw new DomainException(
                        'This activity changed after you opened it. Your changes were not saved. '
                        . 'Save again to replace the current version, or load the latest version.'
                    );
                }

                // A new assignee must be a current customer member. Keeping
                // the existing assignee is allowed even if they have left.
                $keepsAssignee = $assigneeId !== null && $current['assignee_id'] !== null
                    && $assigneeId === (int) $current['assignee_id'];
                if ($assigneeId !== null && !$keepsAssignee
                    && !trip_member_is_assignable($pdo, (int) $item['trip_id'], $assigneeId)) {
                    $pdo->rollBack();
                    $errors[] = 'Choose a current trip member to assign.';
                } else {
                    // Moving to another day places it by that day's order.
                    $sortOrder = $itemDate === $current['item_date']
                        ? (int) $current['sort_order']
                        : itinerary_next_sort_order($pdo, (int) $item['trip_id'], $itemDate, $itemId);
                    $stmt = $pdo->prepare(
                        'UPDATE itinerary_items
                         SET item_date = :item_date, item_time = :item_time, end_time = :end_time,
                             title = :title, location = :location, category = :category,
                             assignee_id = :assignee_id, sort_order = :sort_order, notes = :notes,
                             estimated_cost = :estimated_cost, actual_cost = :actual_cost
                         WHERE id = :id AND trip_id = :trip_id AND version = :version'
                    );
                    $stmt->execute([
                        'item_date'      => $itemDate,
                        'item_time'      => $itemTime !== '' ? $itemTime : null,
                        'end_time'       => $endTime !== '' ? $endTime : null,
                        'title'          => $title,
                        'location'       => $location !== '' ? $location : null,
                        'category'       => $category !== '' ? $category : null,
                        'assignee_id'    => $assigneeId,
                        'sort_order'     => $sortOrder,
                        'notes'          => $notes !== '' ? $notes : null,
                        'estimated_cost' => $estimated !== '' ? $estimated : null,
                        'actual_cost'    => $actual !== '' ? $actual : null,
                        'id'             => $itemId,
                        'trip_id'        => $item['trip_id'],
                        'version'        => $submittedVersion,
                    ]);
                    // Log only a real change (0 rows = nothing changed).
                    if ($stmt->rowCount() > 0) {
                        $bump = $pdo->prepare(
                            'UPDATE itinerary_items SET version = version + 1, updated_at = updated_at
                             WHERE id = :id AND trip_id = :trip_id'
                        );
                        $bump->execute(['id' => $itemId, 'trip_id' => $item['trip_id']]);
                        if ($bump->rowCount() !== 1) {
                            throw new RuntimeException('Itinerary version bump did not update exactly one row.');
                        }
                        log_trip_activity($pdo, (int) $item['trip_id'], 'activity_updated', null, $title);
                    }
                    $pdo->commit();

                    header('Location: /customer/trips/view.php?id=' . $item['trip_id']);
                    exit;
                }

            } catch (DomainException $e) {
                $pdo->rollBack();
                $errors[] = $e->getMessage();
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('TravelEase: itinerary item update failed (code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
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
    <link rel="icon" href="/assets/images/travelease-logo-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/pages.css">
</head>
<body>
    <?php $navCurrent = null; require __DIR__ . '/../../includes/partials/app_header.php'; ?>
    <main class="app-page legacy-page" id="main-content" tabindex="-1">
        <?php $tripId = (int) $item['trip_id']; $tripTab = 'itinerary'; $tripPageTitle = 'Edit activity'; $tripPageLead = null; require __DIR__ . '/../../includes/partials/trip_header.php'; ?>

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
                <input type="hidden" name="version" value="<?= htmlspecialchars($formVersion, ENT_QUOTES, 'UTF-8') ?>">

                <?php if ($staleConflict): ?>
                    <p><a href="/customer/trips/itinerary_edit.php?id=<?= (int) $itemId ?>">Load the latest version instead</a></p>
                <?php endif; ?>

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
                        <label for="item_time">Start time (optional)</label>
                        <input type="time" id="item_time" name="item_time"
                               value="<?= htmlspecialchars(substr($itemTime, 0, 5), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="end_time">End time (optional)</label>
                        <input type="time" id="end_time" name="end_time"
                               value="<?= htmlspecialchars($endTime, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>

                <label for="location">Location (optional)</label>
                <input type="text" id="location" name="location" maxlength="150"
                       value="<?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-row">
                    <div>
                        <label for="category">Category (optional)</label>
                        <select id="category" name="category">
                            <option value="">Uncategorized</option>
                            <?php foreach (ITINERARY_CATEGORIES as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $category === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="assignee_id">Assigned to (optional)</label>
                        <select id="assignee_id" name="assignee_id">
                            <option value="">Unassigned</option>
                            <?php foreach ($assignable as $person): ?>
                                <option value="<?= (int) $person['id'] ?>" <?= $assigneeRaw === (string) $person['id'] ? 'selected' : '' ?>><?= htmlspecialchars($person['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                            <?php if ($historicalAssignee): ?>
                                <option value="<?= $historicalAssignee['id'] ?>" <?= $assigneeRaw === (string) $historicalAssignee['id'] ? 'selected' : '' ?>><?= htmlspecialchars($historicalAssignee['name'], ENT_QUOTES, 'UTF-8') ?> (no longer on this trip)</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

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

                <button type="submit"><?= $staleConflict ? 'Save changes again' : 'Save Changes' ?></button>
            </form>
        </div>
    </main>
</body>
</html>
