<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

require_role(['customer']);

$name = htmlspecialchars($_SESSION['user_name'] ?? 'Traveler', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/dashboard.css">
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1>Welcome, <?= $name ?></h1>
            <a href="/auth/logout.php" class="logout-link">Sign out</a>
        </header>
        <p>Your customer dashboard is set up. Trip planning, budgeting, and
           booking features will appear here as they're built.</p>
    </main>
</body>
</html>
