<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_validation.php';

require_role(['admin']);

$name = htmlspecialchars($_SESSION['user_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');

$pdo = get_db_connection();

// One user per row, with a trip count via LEFT JOIN so users with
// zero trips still show up (INNER JOIN would drop them). This page
// is the one place in the app that legitimately reads across every
// user -- everywhere else in the codebase scopes to the logged-in
// user's own id.
$stmt = $pdo->query(
    'SELECT u.id, u.name, u.email, u.role, u.created_at,
            COUNT(t.id) AS trip_count
     FROM users u
     LEFT JOIN trips t ON t.user_id = u.id
     GROUP BY u.id, u.name, u.email, u.role, u.created_at
     ORDER BY u.created_at ASC'
);
$users = $stmt->fetchAll();

$adminErrors = $_SESSION['admin_errors'] ?? [];
unset($_SESSION['admin_errors']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1>Admin Dashboard — <?= $name ?></h1>
            <div class="header-actions">
                <a href="/profile.php" class="logout-link">Profile</a>
                <a href="/auth/logout.php" class="logout-link">Sign out</a>
            </div>
        </header>

        <?php if (!empty($adminErrors)): ?>
            <ul class="form-errors" role="alert">
                <?php foreach ($adminErrors as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Trips</th>
                        <th>Joined</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php $isSelf = (int) $user['id'] === current_user_id(); ?>
                        <tr>
                            <td><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?><?= $isSelf ? ' (you)' : '' ?></td>
                            <td><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ($isSelf): ?>
                                    <?= htmlspecialchars(ucfirst($user['role']), ENT_QUOTES, 'UTF-8') ?>
                                <?php else: ?>
                                    <form method="post" action="/admin/update_role.php" class="admin-inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                        <select name="role" onchange="this.form.submit()">
                                            <?php foreach (ADMIN_ASSIGNABLE_ROLES as $roleOption): ?>
                                                <option value="<?= $roleOption ?>" <?= $user['role'] === $roleOption ? 'selected' : '' ?>>
                                                    <?= ucfirst($roleOption) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $user['trip_count'] ?></td>
                            <td><?= htmlspecialchars(substr($user['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if (!$isSelf): ?>
                                    <form method="post" action="/admin/delete_user.php" class="admin-inline-form"
                                          onsubmit="return confirm('Delete this account? This also deletes all of their trips. This cannot be undone.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                        <button type="submit" class="link-danger">Delete</button>
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
