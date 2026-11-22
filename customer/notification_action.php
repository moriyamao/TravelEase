<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_role(['customer']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}
$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || !csrf_verify($token)) {
    http_response_code(403);
    exit('Your session expired. Reload the page and try again.');
}
$action = $_POST['action'] ?? null;
if (!in_array($action, ['read', 'unread', 'dismiss', 'restore', 'preferences'], true)) {
    http_response_code(400);
    exit('Choose a valid notification action.');
}
$pdo = get_db_connection();
$userId = (int) current_user_id();
unset($_SESSION['notification_notice'], $_SESSION['notification_error']);
try {
    if ($action === 'preferences') {
        $enabled = $_POST['enabled'] ?? null;
        if (!in_array($enabled, ['0', '1'], true)) {
            http_response_code(400);
            exit('Choose whether to receive in-app notifications.');
        }
        $stmt = $pdo->prepare('UPDATE users SET notify_in_app = :enabled WHERE id = :id');
        $stmt->execute(['enabled' => (int) $enabled, 'id' => $userId]);
        $_SESSION['notification_notice'] = 'Notification preferences saved. This applies to future notifications.';
    } else {
        $id = $_POST['notification_id'] ?? null;
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,18}$/D', $id) || strlen($id) > strlen((string) PHP_INT_MAX)
            || (strlen($id) === strlen((string) PHP_INT_MAX) && strcmp($id, (string) PHP_INT_MAX) > 0)) {
            http_response_code(400);
            exit('Choose a valid notification.');
        }
        // Lock only the recipient's own row. Replay is harmless, including
        // an already read/dismissed row. No trip write or lock is needed.
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id FROM notifications WHERE id = :id AND user_id = :user_id FOR UPDATE');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        if ($stmt->fetchColumn() === false) {
            $pdo->rollBack();
            http_response_code(404);
            exit('Notification not found.');
        }
        $change = match ($action) {
            'read' => 'read_at = COALESCE(read_at, UTC_TIMESTAMP())',
            'unread' => 'read_at = NULL',
            'dismiss' => 'dismissed_at = COALESCE(dismissed_at, UTC_TIMESTAMP())',
            'restore' => 'dismissed_at = NULL',
        };
        $stmt = $pdo->prepare('UPDATE notifications SET ' . $change . ' WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $pdo->commit();
        $_SESSION['notification_notice'] = 'Notification updated.';
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TravelEase: notification action failed (database code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    $_SESSION['notification_error'] = 'Could not update notifications. Please try again.';
}
$filter = $_POST['filter'] ?? 'unread';
if (!in_array($filter, ['all', 'unread', 'dismissed'], true)) {
    $filter = 'unread';
}
header('Location: /customer/notifications.php?filter=' . $filter);
exit;
