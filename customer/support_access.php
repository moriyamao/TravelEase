<?php
/**
 * FR-32: a customer chooses which of their trips support staff may view
 * for their open support request. Only a trip's owner can share it, since
 * it also holds other members' plans. Sharing ends when the customer stops
 * it, support resolves the request, or the trip changes owner.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/trip_membership.php';
require_once __DIR__ . '/../includes/trip_activity.php';
require_once __DIR__ . '/../includes/support_access.php';

require_role(['customer']);

$pdo = get_db_connection();
$userId = (int) current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !csrf_verify($token)) {
        http_response_code(403);
        exit('Your session expired. Reload the page and try again.');
    }
    $action = $_POST['action'] ?? null;
    $tripId = filter_var($_POST['trip_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!in_array($action, ['share', 'revoke'], true) || !$tripId) {
        http_response_code(400);
        exit('Choose a trip and a valid action.');
    }
    try {
        $pdo->beginTransaction();
        // Owner check under the trip lock; members and outsiders get 403/404 here.
        lock_trip_and_require_permission($pdo, $tripId, 'manage_trip');
        $escalationId = customer_open_escalation_id($pdo, $userId);
        if ($escalationId === null) {
            $pdo->rollBack();
            $_SESSION['support_access_error'] = 'You need an open support request to share a trip.';
            header('Location: /customer/support_access.php');
            exit;
        }
        $stmt = $pdo->prepare(
            'SELECT revoked_at FROM support_trip_shares WHERE escalation_id = :escalation AND trip_id = :trip FOR UPDATE'
        );
        $stmt->execute(['escalation' => $escalationId, 'trip' => $tripId]);
        $existing = $stmt->fetch();
        $changed = false;
        if ($action === 'share' && ($existing === false || $existing['revoked_at'] !== null)) {
            $stmt = $pdo->prepare(
                'INSERT INTO support_trip_shares (escalation_id, trip_id, shared_by, shared_at)
                 VALUES (:escalation, :trip, :user, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE shared_by = VALUES(shared_by), shared_at = VALUES(shared_at), revoked_at = NULL'
            );
            $stmt->execute(['escalation' => $escalationId, 'trip' => $tripId, 'user' => $userId]);
            log_trip_activity($pdo, $tripId, 'support_access_shared');
            $changed = true;
        } elseif ($action === 'revoke' && $existing !== false && $existing['revoked_at'] === null) {
            $stmt = $pdo->prepare(
                'UPDATE support_trip_shares SET revoked_at = UTC_TIMESTAMP() WHERE escalation_id = :escalation AND trip_id = :trip'
            );
            $stmt->execute(['escalation' => $escalationId, 'trip' => $tripId]);
            log_trip_activity($pdo, $tripId, 'support_access_revoked');
            $changed = true;
        }
        $pdo->commit();
        $_SESSION['support_access_notice'] = !$changed ? 'Nothing changed.'
            : ($action === 'share' ? 'Support can now view this trip while your request is open.' : 'Support can no longer view this trip.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('TravelEase: support trip sharing failed (database code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
        $_SESSION['support_access_error'] = 'Could not update sharing. Please try again.';
    }
    header('Location: /customer/support_access.php');
    exit;
}

$escalationId = customer_open_escalation_id($pdo, $userId);
$stmt = $pdo->prepare('SELECT id, name, start_date, end_date, status FROM trips WHERE user_id = :user ORDER BY start_date DESC, id DESC');
$stmt->execute(['user' => $userId]);
$ownedTrips = $stmt->fetchAll();
$sharedIds = $escalationId !== null ? array_map('intval', array_column(viewable_support_shares($pdo, $escalationId), 'trip_id')) : [];

$notice = $_SESSION['support_access_notice'] ?? null;
$error = $_SESSION['support_access_error'] ?? null;
unset($_SESSION['support_access_notice'], $_SESSION['support_access_error']);
$e = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Share a trip with support — TravelEase</title>
    <link rel="icon" href="/assets/images/travelease-logo-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/support-access.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/pages.css">
</head>
<body>
    <?php $navCurrent = null; require __DIR__ . '/../includes/partials/app_header.php'; ?>
    <main class="app-page legacy-page" id="main-content" tabindex="-1">
        <div class="page-head">
            <p class="eyebrow">Support</p>
            <h1 class="page-title">Share a trip with support</h1>
        </div>
        <?php if ($notice): ?><p class="form-notice" role="status"><?= $e($notice) ?></p><?php endif; ?>
        <?php if ($error): ?><p class="form-errors" role="alert"><?= $e($error) ?></p><?php endif; ?>

        <section class="support-card" aria-labelledby="what-heading">
            <h2 id="what-heading">What support can see</h2>
            <p>
                Support staff can read your support conversation and see how many trips you are on. They cannot see a trip's
                details unless you share it here.
            </p>
            <p>
                A shared trip shows its name, destination, dates, status, time zone and itinerary activities (date, times, title,
                category, location and notes) as read-only. It does not show comments, votes, expenses, bookings,
                reminders, or other members' names or emails.
            </p>
            <p>
                Support can view a shared trip only while your request is open. You can stop sharing at any time, and sharing also
                ends when support resolves your request or the trip gets a new owner. Other trip members see a note in the trip's
                activity history when you share or stop sharing.
            </p>
        </section>

        <section class="support-card" aria-labelledby="trips-heading">
            <h2 id="trips-heading">Your trips</h2>
            <?php if ($escalationId === null): ?>
                <p class="empty-state">You can share a trip while you have an open support request. Ask for help in the support chat first.</p>
            <?php elseif (!$ownedTrips): ?>
                <p class="empty-state">You don't own any trips. Only a trip's owner can share it with support.</p>
            <?php else: ?>
                <ul class="support-trip-list">
                    <?php foreach ($ownedTrips as $trip): ?>
                        <?php $shared = in_array((int) $trip['id'], $sharedIds, true); ?>
                        <li>
                            <div>
                                <strong><?= $e($trip['name']) ?></strong>
                                <span class="support-trip-meta"><?= $e($trip['start_date']) ?> – <?= $e($trip['end_date']) ?> &middot; <?= $e(ucfirst($trip['status'])) ?></span>
                            </div>
                            <form method="post" action="/customer/support_access.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>">
                                <input type="hidden" name="action" value="<?= $shared ? 'revoke' : 'share' ?>">
                                <?php if ($shared): ?><span class="support-shared">Shared with support</span><?php endif; ?>
                                <button type="submit" class="<?= $shared ? 'support-button-secondary' : 'support-button' ?>"><?= $shared ? 'Stop sharing' : 'Share with support' ?></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="support-note">Trips you were invited to can only be shared by their owner.</p>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
