<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/trip_validation.php';

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
        'SELECT id, name, destination, start_date, end_date, status, notes
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
$notes       = $trip['notes'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $name        = trim($_POST['name'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $startDate   = trim($_POST['start_date'] ?? '');
        $endDate     = trim($_POST['end_date'] ?? '');
        $status      = trim($_POST['status'] ?? 'planning');
        $notes       = trim($_POST['notes'] ?? '');

        if ($err = validate_trip_name($name))               $errors[] = $err;
        if ($err = validate_trip_destination($destination))  $errors[] = $err;
        if ($err = validate_trip_dates($startDate, $endDate)) $errors[] = $err;
        if ($err = validate_trip_status($status))            $errors[] = $err;
        if ($err = validate_trip_notes($notes))              $errors[] = $err;

        if (empty($errors)) {
            try {
                // UPDATE also scoped by user_id -- belt-and-suspenders
                // with the load above. Even if something upstream were
                // ever refactored carelessly, this query alone still
                // cannot modify another user's row.
                $stmt = $pdo->prepare(
                    'UPDATE trips
                     SET name = :name, destination = :destination,
                         start_date = :start_date, end_date = :end_date,
                         status = :status, notes = :notes
                     WHERE id = :id AND user_id = :user_id'
                );
                $stmt->execute([
                    'name'        => $name,
                    'destination' => $destination,
                    'start_date'  => $startDate,
                    'end_date'    => $endDate,
                    'status'      => $status,
                    'notes'       => $notes !== '' ? $notes : null,
                    'id'          => $tripId,
                    'user_id'     => current_user_id(),
                ]);

                header('Location: /customer/trips/view.php?id=' . $tripId);
                exit;

            } catch (PDOException $e) {
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
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1>Edit Trip</h1>
            <a href="/customer/trips/view.php?id=<?= (int) $tripId ?>" class="logout-link">Cancel</a>
        </header>

        <div class="form-card">
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

                <label for="notes">Notes (optional)</label>
                <textarea id="notes" name="notes" rows="4" maxlength="5000"><?= htmlspecialchars($notes, ENT_QUOTES, 'UTF-8') ?></textarea>

                <button type="submit">Save Changes</button>
            </form>
        </div>
    </main>
</body>
</html>
