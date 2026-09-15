<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/trip_validation.php';

require_role(['customer']);

$errors = [];
$name        = '';
$destination = '';
$startDate   = '';
$endDate     = '';
$status      = 'planning';
$notes       = '';

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
            $pdo = get_db_connection();

            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO trips (user_id, name, destination, start_date, end_date, status, notes)
                     VALUES (:user_id, :name, :destination, :start_date, :end_date, :status, :notes)'
                );
                $stmt->execute([
                    'user_id'     => current_user_id(), // owner is always the logged-in user, never client-supplied
                    'name'        => $name,
                    'destination' => $destination,
                    'start_date'  => $startDate,
                    'end_date'    => $endDate,
                    'status'      => $status,
                    'notes'       => $notes !== '' ? $notes : null,
                ]);

                header('Location: /customer/dashboard.php');
                exit;

            } catch (PDOException $e) {
                error_log('TravelEase: trip creation failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong while saving your trip. Please try again.';
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
    <title>New Trip — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1>New Trip</h1>
            <a href="/customer/dashboard.php" class="logout-link">Back to My Trips</a>
        </header>

        <div class="form-card">
            <?php if (!empty($errors)): ?>
                <ul class="form-errors" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <form method="post" action="/customer/trips/create.php" novalidate>
                <?= csrf_field() ?>

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

                <button type="submit">Create Trip</button>
            </form>
        </div>
    </main>
</body>
</html>
