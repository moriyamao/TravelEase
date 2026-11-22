<?php
/**
 * FR-30 / FR-04: a customer's in-app notifications and their preference.
 *
 * Rows come from list_user_notifications(), which already hides rows the
 * user may no longer see. Sentences are generic (notification_sentence) and
 * never contain event details. Changes go to notification_action.php.
 * Email is used only for due reminders, and only when the user turns it on.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';

require_role(['customer']);

$pdo = get_db_connection();
$userId = current_user_id();

$filters = ['unread' => 'Unread', 'all' => 'All', 'dismissed' => 'Dismissed'];
$filter = $_GET['filter'] ?? 'unread';
if (!is_string($filter) || !isset($filters[$filter])) {
    $filter = 'unread';
}

$notifications = list_user_notifications($pdo, $userId, $filter);
$unreadCount = count_unread_notifications($pdo, $userId);

$stmt = $pdo->prepare('SELECT notify_in_app, notify_email FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$prefs = $stmt->fetch();
$notifyInApp = (int) $prefs['notify_in_app'] === 1;
$notifyEmail = (int) $prefs['notify_email'] === 1;

$notice = $_SESSION['notification_notice'] ?? null;
$error = $_SESSION['notification_error'] ?? null;
unset($_SESSION['notification_notice'], $_SESSION['notification_error']);

$e = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$emptyText = [
    'unread' => 'You have no unread notifications.',
    'all' => 'No notifications yet.',
    'dismissed' => 'No dismissed notifications.',
];

/** One row-action form (read / unread / dismiss / restore). */
$rowAction = function (int $id, string $action, string $label) use ($filter): void {
    ?>
    <form method="post" action="/customer/notification_action.php" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="notification_id" value="<?= $id ?>">
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="link-button"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></button>
    </form>
    <?php
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — TravelEase</title>
    <link rel="icon" href="/assets/images/travelease-logo-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css?v=20261103-2">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/notifications.css">
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1>Notifications</h1>
            <a href="/customer/dashboard.php" class="logout-link">Back to My Trips</a>
        </header>

        <?php if ($notice): ?><p class="form-notice" role="status"><?= $e($notice) ?></p><?php endif; ?>
        <?php if ($error): ?><p class="form-errors" role="alert"><?= $e($error) ?></p><?php endif; ?>

        <div class="notification-layout">
            <section class="notification-card" aria-labelledby="inbox-heading">
                <h2 id="inbox-heading">Inbox<?= $unreadCount > 0 ? ' <span class="notification-badge">' . $unreadCount . ' unread</span>' : '' ?></h2>
                <nav class="notification-filters" aria-label="Filter notifications">
                    <?php foreach ($filters as $value => $label): ?>
                        <a href="/customer/notifications.php?filter=<?= $e($value) ?>"<?= $value === $filter ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a>
                    <?php endforeach; ?>
                </nav>

                <?php if (!$notifications): ?>
                    <p class="empty-state"><?= $e($emptyText[$filter]) ?></p>
                <?php else: ?>
                    <ul class="notification-list">
                        <?php foreach ($notifications as $n): ?>
                            <?php
                            $id = (int) $n['id'];
                            $isRead = $n['read_at'] !== null;
                            $isDismissed = $n['dismissed_at'] !== null;
                            ?>
                            <li class="notification-item<?= $isRead ? '' : ' is-unread' ?>" id="notification-<?= $id ?>">
                                <p class="notification-text">
                                    <?php if (!$isRead): ?><span class="visually-hidden">Unread: </span><?php endif; ?>
                                    <?= $e(notification_sentence($n['event_type'])) ?>
                                </p>
                                <p class="notification-meta">
                                    <?php if ((int) $n['can_open_trip'] === 1 && $n['trip_name'] !== null && ($n['reminder_id'] ?? null) !== null): ?>
                                        <a href="/customer/trips/reminders.php?id=<?= (int) $n['trip_id'] ?>">Open reminders</a> &middot; <?= $e($n['trip_name']) ?> &middot;
                                    <?php elseif ((int) $n['can_open_trip'] === 1 && $n['trip_name'] !== null): ?>
                                        <a href="/customer/trips/view.php?id=<?= (int) $n['trip_id'] ?>"><?= $e($n['trip_name']) ?></a> &middot;
                                    <?php elseif ((int) $n['invitation_pending'] === 1): ?>
                                        <a href="/customer/dashboard.php#invitations">Respond to invitation</a> &middot;
                                    <?php endif; ?>
                                    <time datetime="<?= $e($n['created_at']) ?>"><?= $e(date('M j, Y g:i A', strtotime($n['created_at'] . ' UTC'))) ?> UTC</time>
                                </p>
                                <div class="notification-actions">
                                    <?php if ($isDismissed): ?>
                                        <?php $rowAction($id, 'restore', 'Restore'); ?>
                                    <?php else: ?>
                                        <?php $rowAction($id, $isRead ? 'unread' : 'read', $isRead ? 'Mark unread' : 'Mark read'); ?>
                                        <?php $rowAction($id, 'dismiss', 'Dismiss'); ?>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="notification-note">Showing up to 100 of your newest notifications.</p>
                <?php endif; ?>
            </section>

            <section class="notification-card" id="preferences" aria-labelledby="preferences-heading">
                <h2 id="preferences-heading">Preferences</h2>
                <form method="post" action="/customer/notification_action.php" class="notification-preferences">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="preferences">
                    <input type="hidden" name="enabled" value="0">
                    <label class="notification-toggle">
                        <input type="checkbox" name="enabled" value="1"<?= $notifyInApp ? ' checked' : '' ?>>
                        Show in-app notifications
                    </label>
                    <p class="notification-note">
                        When this is off, new trip updates, invitations and due reminders are not added here. Notifications you already have stay in your inbox.
                    </p>
                    <input type="hidden" name="email_enabled" value="0">
                    <label class="notification-toggle">
                        <input type="checkbox" name="email_enabled" value="1"<?= $notifyEmail ? ' checked' : '' ?>>
                        Email me when a reminder is due
                    </label>
                    <p class="notification-note">
                        Off by default. Only due reminders are emailed, to your account address; other trip updates stay in-app.
                        "Sent" means the email provider accepted the message, not that it reached your inbox. Reminders can arrive a few minutes late.
                    </p>
                    <button type="submit">Save preferences</button>
                </form>
            </section>
        </div>
    </main>
</body>
</html>
