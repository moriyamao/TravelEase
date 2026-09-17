<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';

require_role(['staff', 'admin']);

$name = htmlspecialchars($_SESSION['user_name'] ?? 'Staff', ENT_QUOTES, 'UTF-8');

$pdo = get_db_connection();

$stmt = $pdo->query('
    SELECT
        e.id, e.message, e.status, e.created_at, e.resolved_at,
        u.name AS customer_name, u.email AS customer_email
    FROM escalations e
    JOIN users u ON u.id = e.user_id
    ORDER BY
        (e.status = "open") DESC,
        e.created_at DESC
');
$escalations = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Queue — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1>Support Queue — <?= $name ?></h1>
            <div class="header-actions">
                <a href="/staff/dashboard.php" class="logout-link">Trips</a>
                <a href="/profile.php" class="logout-link">Profile</a>
                <a href="/auth/logout.php" class="logout-link">Sign out</a>
            </div>
        </header>

        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Message</th>
                        <th>Received</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($escalations)): ?>
                        <tr><td colspan="5">No support requests yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($escalations as $item): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($item['customer_name'], ENT_QUOTES, 'UTF-8') ?><br>
                                <small><?= htmlspecialchars($item['customer_email'], ENT_QUOTES, 'UTF-8') ?></small>
                            </td>
                            <td><?= nl2br(htmlspecialchars($item['message'], ENT_QUOTES, 'UTF-8')) ?></td>
                            <td><?= htmlspecialchars(date('M j, Y g:ia', strtotime($item['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <span class="status-badge status-<?= $item['status'] === 'open' ? 'planning' : 'completed' ?>">
                                    <?= $item['status'] === 'open' ? 'Open' : 'Resolved' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($item['status'] === 'open'): ?>
                                    <form method="POST" action="/staff/resolve_escalation.php" class="admin-inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="escalation_id" value="<?= (int) $item['id'] ?>">
                                        <button type="submit" class="link-danger">Mark resolved</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>
