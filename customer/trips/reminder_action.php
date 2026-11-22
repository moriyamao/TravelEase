<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_reminders.php';
require_role(['customer']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST'); http_response_code(405); exit('Method not allowed.');
}
$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || !csrf_verify($token)) {
    http_response_code(403); exit('Your session expired. Reload and try again.');
}
$tripId = filter_var($_POST['trip_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = $_POST['action'] ?? null;
if (!$tripId || !in_array($action, ['create', 'acknowledge', 'cancel', 'retry_email'], true)) {
    http_response_code(400); exit('Choose a valid trip and reminder action.');
}
$pdo = get_db_connection();
$userId = (int) current_user_id();
require_trip_permission($pdo, $tripId, 'view');
unset($_SESSION['reminder_error'][$tripId], $_SESSION['reminder_notice'][$tripId]);
try {
    $pdo->beginTransaction();
    lock_trip_and_require_permission($pdo, $tripId, 'view');
    $stmt = $pdo->prepare('SELECT time_zone, status FROM trips WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $tripId]);
    $trip = $stmt->fetch();
    if ($action === 'create') {
        if (!in_array($trip['status'], ['planning', 'confirmed'], true)) {
            throw new InvalidArgumentException('Reminders can be added to planning or confirmed trips.');
        }
        if (($_POST['time_zone'] ?? null) !== $trip['time_zone']) {
            throw new DomainException('The trip time zone changed. Reload before adding a reminder.');
        }
        $title = $_POST['title'] ?? null;
        $local = $_POST['due_local'] ?? null;
        if (!is_string($title) || !is_string($local) || !mb_check_encoding($title, 'UTF-8')
            || trim($title) === '' || mb_strlen(trim($title)) > 150 || preg_match('/[\x00-\x1f\x7f]/', $title)) {
            throw new InvalidArgumentException('Enter a reminder title of 1 to 150 characters and a date/time.');
        }
        $due = reminder_local_to_utc($local, $trip['time_zone']);
        if ($due->getTimestamp() <= time()) {
            throw new InvalidArgumentException('Choose a future reminder time.');
        }
        // Serialize this account's creation quota across different trips.
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $userId]);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM trip_reminders WHERE user_id = :user AND itinerary_item_id IS NULL AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR');
        $stmt->execute(['user' => $userId]);
        if ((int) $stmt->fetchColumn() >= 20) {
            throw new InvalidArgumentException('You can create up to 20 personal reminders per hour.');
        }
        $stmt = $pdo->prepare('INSERT INTO trip_reminders (trip_id, user_id, title, due_at) VALUES (:trip, :user, :title, :due)');
        $stmt->execute(['trip' => $tripId, 'user' => $userId, 'title' => trim($title), 'due' => $due->format('Y-m-d H:i:s')]);
    } else {
        $id = filter_var($_POST['reminder_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $version = filter_var($_POST['version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id || !$version) {
            throw new InvalidArgumentException('Choose a valid reminder and version.');
        }
        $stmt = $pdo->prepare('SELECT * FROM trip_reminders WHERE id = :id AND trip_id = :trip AND user_id = :user FOR UPDATE');
        $stmt->execute(['id' => $id, 'trip' => $tripId, 'user' => $userId]);
        $r = $stmt->fetch();
        if (!$r) { throw new OutOfBoundsException('Reminder not found.'); }
        if ((int) $r['version'] !== $version) { throw new DomainException('This reminder changed. Reload and try again.'); }
        if ($r['cancelled_at'] !== null) { throw new DomainException('This reminder was cancelled.'); }
        if ($action === 'cancel') {
            $change = 'cancelled_at = UTC_TIMESTAMP()';
        } elseif ($action === 'acknowledge') {
            if ($r['due_at'] > gmdate('Y-m-d H:i:s')) {
                throw new InvalidArgumentException('This reminder is not due yet.');
            }
            $change = 'acknowledged_at = COALESCE(acknowledged_at, UTC_TIMESTAMP())';
        } else {
            $stmt = $pdo->prepare('SELECT notify_email FROM users WHERE id = :id LOCK IN SHARE MODE');
            $stmt->execute(['id' => $userId]);
            if (!(int) $stmt->fetchColumn() || $r['acknowledged_at'] !== null
                || !in_array($r['email_status'], ['failed', 'unknown'], true)
                || (int) $r['email_attempts'] >= 3 || $r['email_attempted_at'] > gmdate('Y-m-d H:i:s', time() - 60)) {
                throw new InvalidArgumentException('Email retry is unavailable. Enable reminder emails and wait 60 seconds after the attempt. There are at most three total email attempts.');
            }
            $change = 'email_status = \'pending\'';
        }
        $stmt = $pdo->prepare('UPDATE trip_reminders SET ' . $change . ', version = version + 1 WHERE id = :id AND user_id = :user');
        $stmt->execute(['id' => $id, 'user' => $userId]);
        if ($action === 'acknowledge') {
            $stmt = $pdo->prepare('UPDATE notifications SET read_at = COALESCE(read_at, UTC_TIMESTAMP()) WHERE reminder_id = :id AND user_id = :user');
            $stmt->execute(['id' => $id, 'user' => $userId]);
        }
    }
    $pdo->commit();
    $_SESSION['reminder_notice'][$tripId] = 'Reminder saved.';
} catch (DomainException | OutOfBoundsException $e) {
    $pdo->rollBack(); http_response_code($e instanceof OutOfBoundsException ? 404 : 409); exit($e->getMessage());
} catch (InvalidArgumentException $e) {
    $pdo->rollBack(); $_SESSION['reminder_error'][$tripId] = $e->getMessage();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('TravelEase: reminder write failed (database code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    $_SESSION['reminder_error'][$tripId] = 'Could not save the reminder. Please try again.';
}
header('Location: /customer/trips/reminders.php?id=' . $tripId);
exit;
