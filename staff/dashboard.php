<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

require_role(['staff', 'admin']); // admins can also view staff areas

$name = htmlspecialchars($_SESSION['user_name'] ?? 'Staff', ENT_QUOTES, 'UTF-8');

$pdo = get_db_connection();

// All trips across all customers -- staff isn't scoped to one user_id
// the way customer pages are. Joined with the owner's name/email so
// staff can see whose trip they're looking at. Read-only: staff has
// no mutation power over customer-owned trip data (see project notes
// on why status-editing was deliberately left out).
$stmt = $pdo->query('
    SELECT
        t.id, t.name, t.destination, t.start_date, t.end_date, t.status,
        u.name AS owner_name, u.email AS owner_email
    FROM trips t
    JOIN users u ON u.id = t.user_id
    ORDER BY t.start_date DESC
');
$trips = $stmt->fetchAll();

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
    <title>Staff Dashboard — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1>Staff Dashboard — <?= $name ?></h1>
            <div class="header-actions">
                <a href="/profile.php" class="logout-link">Profile</a>
                <a href="/auth/logout.php" class="logout-link">Sign out</a>
                <a href="/staff/escalations.php" class="logout-link">Support Queue</a>
            </div>
        </header>

        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Trip</th>
                        <th>Customer</th>
                        <th>Destination</th>
                        <th>Dates</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($trips)): ?>
                        <tr><td colspan="5">No trips yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($trips as $trip): ?>
                        <tr>
                            <td><?= htmlspecialchars($trip['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?= htmlspecialchars($trip['owner_name'], ENT_QUOTES, 'UTF-8') ?><br>
                                <small><?= htmlspecialchars($trip['owner_email'], ENT_QUOTES, 'UTF-8') ?></small>
                            </td>
                            <td><?= htmlspecialchars($trip['destination'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($trip['start_date'], ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars($trip['end_date'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <span class="status-badge status-<?= htmlspecialchars($trip['status'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($statusLabels[$trip['status']] ?? $trip['status'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>