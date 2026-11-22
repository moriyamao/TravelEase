<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/trip_validation.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_activity.php';

require_role(['customer']);

$tripId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($tripId === false || $tripId === null) {
    http_response_code(400);
    die('Invalid trip.');
}

$pdo = get_db_connection();

// Load the trip first, scoped to the owner (§26 -- IDOR prevention).
// Both the GET (show form) and POST (save changes) paths use this
// same ownership-checked fetch before doing anything else.
function load_owned_trip(PDO $pdo, int $tripId, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, destination, start_date, end_date, status,
                budget_currency, budget_amount, notes, time_zone
         FROM trips
         WHERE id = :id AND user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute(['id' => $tripId, 'user_id' => $userId]);
    $trip = $stmt->fetch();
    return $trip ?: null;
}

$trip = load_owned_trip($pdo, $tripId, current_user_id());
if (!$trip) {
    http_response_code(404);
    die('Trip not found.');
}

$errors = [];
$name        = $trip['name'];
$destination = $trip['destination'];
$startDate   = $trip['start_date'];
$endDate     = $trip['end_date'];
$status      = $trip['status'];
$currency    = $trip['budget_currency'];
$budget      = $trip['budget_amount'] !== null ? (string) $trip['budget_amount'] : '';
$notes       = $trip['notes'] ?? '';
$timeZone    = $trip['time_zone'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $name        = trim($_POST['name'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $startDate   = trim($_POST['start_date'] ?? '');
        $endDate     = trim($_POST['end_date'] ?? '');
        $status      = trim($_POST['status'] ?? 'planning');
        $currency    = trim($_POST['budget_currency'] ?? 'PHP');
        $budget      = trim($_POST['budget_amount'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');
        // An older form without the time zone field keeps the current zone (resolved under the lock).
        $postedZone  = array_key_exists('time_zone', $_POST) ? (is_string($_POST['time_zone']) ? trim($_POST['time_zone']) : "") : null;
        if ($postedZone !== null) $timeZone = $postedZone;

        if ($err = validate_trip_name($name))               $errors[] = $err;
        if ($err = validate_trip_destination($destination))  $errors[] = $err;
        if ($err = validate_trip_dates($startDate, $endDate)) $errors[] = $err;
        if ($err = validate_trip_status($status))            $errors[] = $err;
        if ($err = validate_trip_currency($currency))        $errors[] = $err;
        if ($err = validate_money_amount($budget, 'Budget')) $errors[] = $err;
        if ($err = validate_trip_notes($notes))              $errors[] = $err;
        if ($postedZone !== null && ($err = validate_trip_timezone($postedZone))) $errors[] = $err;

        if (empty($errors)) {
            try {
                // FR-18: lock the trip (the same lock member changes take)
                // and re-check ownership, so the update and its history
                // entry commit together.
                $pdo->beginTransaction();
                lock_trip_and_require_permission($pdo, $tripId, 'manage_trip');
                // Compare against the row as it is now (under the lock),
                // not as it was when the form loaded.
                $current = load_owned_trip($pdo, $tripId, current_user_id());
                if (!$current) {
                    $pdo->rollBack();
                    http_response_code(404);
                    die('Trip not found.');
                }
                $timeZone = $postedZone ?? $current['time_zone'];

                if ($startDate !== $current['start_date'] || $endDate !== $current['end_date']) {
                    // A locking read sees activities committed while this
                    // request waited for the trip lock, not an older snapshot.
                    $stmt = $pdo->prepare(
                        'SELECT id FROM itinerary_items
                         WHERE trip_id = :trip_id AND (item_date < :start_date OR item_date > :end_date)
                         LIMIT 1 FOR UPDATE'
                    );
                    $stmt->execute(['trip_id' => $tripId, 'start_date' => $startDate, 'end_date' => $endDate]);
                    if ($stmt->fetchColumn() !== false) {
                        throw new DomainException('Trip dates must include all itinerary activities. Move or delete activities outside the new dates first.');
                    }
                }

                // UPDATE also scoped by user_id -- belt-and-suspenders
                // with the load above. Even if something upstream were
                // ever refactored carelessly, this query alone still
                // cannot modify another user's row.
                $stmt = $pdo->prepare(
                    'UPDATE trips
                     SET name = :name, destination = :destination,
                         start_date = :start_date, end_date = :end_date,
                         status = :status, budget_currency = :budget_currency,
                         budget_amount = :budget_amount, notes = :notes,
                         time_zone = :time_zone
                     WHERE id = :id AND user_id = :user_id'
                );
                $stmt->execute([
                    'name'            => $name,
                    'destination'     => $destination,
                    'start_date'      => $startDate,
                    'end_date'        => $endDate,
                    'status'          => $status,
                    'budget_currency' => $currency,
                    'budget_amount'   => $budget !== '' ? $budget : null,
                    'notes'           => $notes !== '' ? $notes : null,
                    'time_zone'       => $timeZone,
                    'id'              => $tripId,
                    'user_id'         => current_user_id(),
                ]);

                // Changed fields for the history entry. Line breaks are
                // compared without CR, since browsers submit CRLF.
                $lf = fn($text) => str_replace("\r\n", "\n", (string) $text);
                $changed = [];
                $comparisons = [
                    [$current['name'], $name, 'name'],
                    [$current['destination'], $destination, 'destination'],
                    [$current['start_date'] . '/' . $current['end_date'], $startDate . '/' . $endDate, 'dates'],
                    [$current['status'], $status, 'status'],
                    [$current['budget_currency'], $currency, 'currency'],
                    [$current['time_zone'], $timeZone, 'time zone'],
                    [$lf($current['notes'] ?? ''), $lf($notes), 'notes'],
                ];
                foreach ($comparisons as [$old, $new, $label]) {
                    if ((string) $old !== (string) $new) {
                        $changed[] = $label;
                    }
                }
                $oldBudget = $current['budget_amount'] === null ? null : (float) $current['budget_amount'];
                $newBudget = $budget === '' ? null : (float) $budget;
                if ($oldBudget !== $newBudget) {
                    $changed[] = 'budget';
                }
                if ($changed) {
                    log_trip_activity($pdo, $tripId, 'trip_updated', null, implode(', ', $changed));
                }
                $pdo->commit();

                header('Location: /customer/trips/view.php?id=' . $tripId);
                exit;

            } catch (DomainException $e) {
                $pdo->rollBack();
                $errors[] = $e->getMessage();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('TravelEase: trip update failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong while saving your changes. Please try again.';
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
    <title>Edit Trip — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1>Edit Trip</h1>
        </header>

        <div class="form-card">
            <a href="/customer/trips/view.php?id=<?= (int) $tripId ?>" class="logout-link" style="display: inline-block; margin-bottom: 1.25rem;">&larr; Cancel</a>

            <?php if (!empty($errors)): ?>
                <ul class="form-errors" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <form method="post" action="/customer/trips/edit.php?id=<?= (int) $tripId ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $tripId ?>">

                <label for="name">Trip name</label>
                <input type="text" id="name" name="name" required maxlength="150"
                       value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">

                <label for="destination">Destination</label>
                <input type="text" id="destination" name="destination" required maxlength="150"
                       value="<?= htmlspecialchars($destination, ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-row">
                    <div>
                        <label for="start_date">Start date</label>
                        <input type="date" id="start_date" name="start_date" required
                               value="<?= htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="end_date">End date</label>
                        <input type="date" id="end_date" name="end_date" required
                               value="<?= htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>

                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="planning"  <?= $status === 'planning'  ? 'selected' : '' ?>>Planning</option>
                    <option value="confirmed" <?= $status === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                    <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>

                <div class="form-row">
                    <div>
                        <label for="budget_currency">Currency</label>
                        <select id="budget_currency" name="budget_currency">
                            <?php foreach (TRIP_ALLOWED_CURRENCIES as $code): ?>
                                <option value="<?= $code ?>" <?= $currency === $code ? 'selected' : '' ?>><?= $code ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="budget_amount">Budget (optional)</label>
                        <input type="text" inputmode="decimal" id="budget_amount" name="budget_amount"
                               placeholder="e.g. 25000.00"
                               value="<?= htmlspecialchars($budget, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>

                <label for="time_zone">Trip time zone</label>
                <select id="time_zone" name="time_zone" aria-describedby="time-zone-help"><?php foreach (DateTimeZone::listIdentifiers() as $zone): ?><option value="<?= htmlspecialchars($zone, ENT_QUOTES, 'UTF-8') ?>" <?= $timeZone === $zone ? 'selected' : '' ?>><?= htmlspecialchars(str_replace('_', ' ', $zone), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
                <small id="time-zone-help">Automatic reminders that have not been sent move with a new zone. Custom reminders keep the moment you saved; only how they are shown changes.</small>

                <label for="notes">Notes (optional)</label>
                <textarea id="notes" name="notes" rows="4" maxlength="5000"><?= htmlspecialchars($notes, ENT_QUOTES, 'UTF-8') ?></textarea>

                <button type="submit">Save Changes</button>
            </form>
        </div>
    </main>
</body>
</html>