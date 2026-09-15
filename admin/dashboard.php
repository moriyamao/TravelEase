<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

require_role(['admin']);

$name = htmlspecialchars($_SESSION['user_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/dashboard.css">
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1>Admin Dashboard — <?= $name ?></h1>
            <a href="/auth/logout.php" class="logout-link">Sign out</a>
        </header>
        <p>User management and reporting will appear here.</p>
    </main>
</body>
</html>
