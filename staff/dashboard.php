<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

require_role(['staff', 'admin']); // admins can also view staff areas

$name = htmlspecialchars($_SESSION['user_name'] ?? 'Staff', ENT_QUOTES, 'UTF-8');

$pdo = get_db_connection();

// FR-32: staff no longer get a list of every customer's trips. They work
// from support requests; a trip's details are visible only when its owner
// shares it from an open request (see includes/support_access.php).
$stmt = $pdo->query(
    'SELECT
        SUM(status = \'open\') AS open_count,
        (SELECT COUNT(DISTINCT s.escalation_id) FROM support_trip_shares s
         JOIN escalations e2 ON e2.id = s.escalation_id AND e2.status = \'open\'
         WHERE s.revoked_at IS NULL) AS shared_count
     FROM escalations'
);
$counts = $stmt->fetch();
$openCount = (int) ($counts['open_count'] ?? 0);
$sharedCount = (int) ($counts['shared_count'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard — TravelEase</title>
    <link rel="icon" href="/assets/images/travelease-logo-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/support-access.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/pages.css">
</head>
<body>
    <?php $navCurrent = 'staff-home'; require __DIR__ . '/../includes/partials/app_header.php'; ?>
    <main class="app-page legacy-page" id="main-content" tabindex="-1">
        <div class="page-head">
            <p class="eyebrow">Signed in as <?= $name ?></p>
            <h1 class="page-title">Staff dashboard</h1>
        </div>

        <section class="support-card" aria-labelledby="queue-heading">
            <h2 id="queue-heading">Support queue</h2>
            <p><strong><?= $openCount ?></strong> open request<?= $openCount === 1 ? '' : 's' ?>, <?= $sharedCount ?> with a trip shared by the customer.</p>
            <p><a class="support-button" href="/staff/escalations.php">Open the support queue</a></p>
        </section>

        <section class="support-card" aria-labelledby="access-heading">
            <h2 id="access-heading">What staff can see</h2>
            <p>
                You can read support requests and see how many trips a customer is on. A trip's details and itinerary are
                visible only when its owner shares it from an open support request, and only until they stop sharing or the
                request is resolved. Comments, expenses, booking references, reminders and other members' identities are never shown.
            </p>
        </section>
    </main>
</body>
</html>
