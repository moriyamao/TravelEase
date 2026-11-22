<?php
/**
 * FR-25: a trip member's own reminders for one trip.
 *
 * Every current trip member sees and manages only the reminders they
 * created or that were generated for them (list_trip_reminders). Nobody,
 * including the owner, sees another member's reminders. Changes go to
 * reminder_action.php. Times are shown in the trip's time zone.
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_reminders.php';

require_role(['customer']);

$tripId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$tripId) {
    http_response_code(400);
    die('Invalid trip.');
}

$pdo = get_db_connection();
require_trip_permission($pdo, $tripId, 'view');
$userId = (int) current_user_id();

$stmt = $pdo->prepare('SELECT name, destination, status, time_zone FROM trips WHERE id = :id');
$stmt->execute(['id' => $tripId]);
$trip = $stmt->fetch();

$stmt = $pdo->prepare('SELECT notify_email FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$emailEnabled = (int) $stmt->fetchColumn() === 1;

$reminders = list_trip_reminders($pdo, $tripId, $userId);

$notice = $_SESSION['reminder_notice'][$tripId] ?? null;
$error = $_SESSION['reminder_error'][$tripId] ?? null;
unset($_SESSION['reminder_notice'][$tripId], $_SESSION['reminder_error'][$tripId]);

$zone = new DateTimeZone($trip['time_zone']);
$utc = new DateTimeZone('UTC');
$now = new DateTimeImmutable('now', $utc);
$e = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$local = fn(?string $utcValue) => $utcValue === null ? null
    : (new DateTimeImmutable($utcValue, $utc))->setTimezone($zone)->format('D, M j, Y g:i A T');
$minLocal = $now->setTimezone($zone)->format('Y-m-d\TH:i');
$tripClosed = in_array($trip['status'], ['cancelled', 'completed'], true);

$emailLabels = [
    'pending' => 'Email waiting to send',
    'sent'    => 'Email accepted by the email provider',
    'failed'  => 'Email failed',
    'unknown' => 'Email result unknown',
    'skipped' => 'No email',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reminders — <?= $e($trip['name']) ?> — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/reminders.css">
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1>Reminders</h1>
            <a href="/customer/trips/view.php?id=<?= (int) $tripId ?>" class="logout-link">Back to trip</a>
        </header>

        <p class="eyebrow"><?= $e($trip['name']) ?> &middot; <?= $e($trip['destination']) ?> &middot; Times in <?= $e(str_replace('_', ' ', $trip['time_zone'])) ?></p>
        <?php if ($notice): ?><p class="form-notice" role="status"><?= $e($notice) ?></p><?php endif; ?>
        <?php if ($error): ?><p class="form-errors" role="alert"><?= $e($error) ?></p><?php endif; ?>

        <p class="reminder-note">
            Only you can see your reminders. TravelEase adds one automatically 30 minutes before each timed activity in the next 48 hours, and you can add your own.
            Reminders are checked about every 5 minutes, so they can arrive a few minutes late.
            <?php if ($tripClosed): ?><strong>This trip is <?= $e($trip['status']) ?>, so no new reminders will be sent.</strong><?php endif; ?>
        </p>
        <p class="reminder-note">
            <?php if ($emailEnabled): ?>
                Email reminders are on. "Sent" means the email provider accepted the message; it can still be delayed or filtered.
            <?php else: ?>
                Email reminders are off, so reminders appear only in your notifications. <a href="/customer/notifications.php#preferences">Change this in notification settings.</a>
            <?php endif; ?>
        </p>

        <section class="reminder-card" aria-labelledby="add-heading">
            <h2 id="add-heading">Add a reminder</h2>
            <?php if ($tripClosed): ?>
                <p class="empty-state">Reminders can be added only while the trip is planning or confirmed.</p>
            <?php else: ?>
            <form method="post" action="/customer/trips/reminder_action.php" class="reminder-form">
                <?= csrf_field() ?>
                <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="time_zone" value="<?= $e($trip['time_zone']) ?>">
                <div class="reminder-form-grid">
                    <div>
                        <label for="reminder-title">Remind me to</label>
                        <input id="reminder-title" name="title" required maxlength="150" placeholder="e.g. Check in online">
                    </div>
                    <div>
                        <label for="reminder-due">When (<?= $e(str_replace('_', ' ', $trip['time_zone'])) ?>)</label>
                        <input id="reminder-due" name="due_local" type="datetime-local" required min="<?= $e($minLocal) ?>" aria-describedby="reminder-due-help">
                    </div>
                </div>
                <p class="reminder-note" id="reminder-due-help">
                    Enter a future time in the trip's time zone (up to 20 new reminders an hour). If clocks move forward and that time does not exist, choose another time.
                    If clocks move back and the time happens twice, the earlier one is used. The saved moment does not change if the trip's zone changes later.
                </p>
                <button type="submit">Add reminder</button>
            </form>
            <?php endif; ?>
        </section>

        <section class="reminder-card" aria-labelledby="list-heading">
            <h2 id="list-heading">Your reminders</h2>
            <?php if (!$reminders): ?>
                <p class="empty-state">You have no reminders for this trip yet.</p>
            <?php else: ?>
                <ul class="reminder-list">
                    <?php foreach ($reminders as $r): ?>
                        <?php
                        $rid = (int) $r['id'];
                        $due = new DateTimeImmutable($r['due_at'], $utc);
                        $cancelled = $r['cancelled_at'] !== null;
                        $acknowledged = $r['acknowledged_at'] !== null;
                        $isDue = $due <= $now;
                        $state = $cancelled ? 'Cancelled' : ($acknowledged ? 'Acknowledged' : ($isDue ? 'Due' : 'Upcoming'));
                        $lastAttempt = $r['email_attempted_at'] !== null ? new DateTimeImmutable($r['email_attempted_at'], $utc) : null;
                        $canRetry = !$cancelled && !$acknowledged && $emailEnabled && in_array($r['email_status'], ['failed', 'unknown'], true)
                            && (int) $r['email_attempts'] < 3
                            && ($lastAttempt === null || $now->getTimestamp() - $lastAttempt->getTimestamp() >= 60);
                        ?>
                        <li class="reminder-item reminder-<?= strtolower($state) ?>" id="reminder-<?= $rid ?>">
                            <div class="reminder-head">
                                <strong><?= $e($r['title']) ?></strong>
                                <span class="reminder-state"><?= $e($state) ?></span>
                            </div>
                            <p class="reminder-meta">
                                <time datetime="<?= $e($due->format(DATE_ATOM)) ?>"><?= $e($local($r['due_at'])) ?></time>
                                &middot; <?= $r['itinerary_item_id'] !== null ? 'Automatic, 30 minutes before the activity' : 'Custom' ?>
                                <?php if (!$cancelled && ($r['email_status'] !== 'skipped' || $emailEnabled)): ?>
                                    &middot; <?= $e($emailLabels[$r['email_status']] ?? $r['email_status']) ?>
                                <?php endif; ?>
                            </p>
                            <?php if (!$cancelled): ?>
                            <div class="reminder-actions">
                                <?php if ($isDue && !$acknowledged): ?>
                                    <form method="post" action="/customer/trips/reminder_action.php" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                                        <input type="hidden" name="action" value="acknowledge">
                                        <input type="hidden" name="reminder_id" value="<?= $rid ?>">
                                        <input type="hidden" name="version" value="<?= (int) $r['version'] ?>">
                                        <button type="submit" class="link-button">Acknowledge</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!$acknowledged): ?>
                                    <form method="post" action="/customer/trips/reminder_action.php" class="inline-form"
                                          onsubmit="return confirm('Cancel this reminder? It will not be sent.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="reminder_id" value="<?= $rid ?>">
                                        <input type="hidden" name="version" value="<?= (int) $r['version'] ?>">
                                        <button type="submit" class="link-button link-danger">Cancel reminder</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canRetry): ?>
                                    <form method="post" action="/customer/trips/reminder_action.php" class="inline-form"
                                          <?php if ($r['email_status'] === 'unknown'): ?>onsubmit="return confirm('The first email may still arrive late, so retrying can send a duplicate. Retry anyway?');"<?php endif; ?>>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                                        <input type="hidden" name="action" value="retry_email">
                                        <input type="hidden" name="reminder_id" value="<?= $rid ?>">
                                        <input type="hidden" name="version" value="<?= (int) $r['version'] ?>">
                                        <button type="submit" class="link-button">Retry email</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <?php if ($r['email_status'] === 'unknown'): ?>
                                <p class="reminder-note">We could not confirm whether the email was accepted. Retrying may send a duplicate if the first one arrives late.</p>
                            <?php elseif ($r['email_status'] === 'failed' && (int) $r['email_attempts'] >= 3): ?>
                                <p class="reminder-note">Email failed after 3 attempts. The reminder is still in your notifications.</p>
                            <?php endif; ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="reminder-note">Showing up to 100 of your reminders for this trip.</p>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
