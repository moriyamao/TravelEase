<?php
/**
 * FR-34: a customer deletes their own account.
 *
 * Requires the current password (or, for Google-only accounts, a sign-in
 * within the last 10 minutes) and typing DELETE. Refused while the customer
 * owns a trip that has other members: they must transfer or delete it
 * first. Solo trips are deleted with the account; memberships, reminders,
 * notifications, invitations and support requests go too. Comments keep
 * the author's name and expense entries keep their name snapshots.
 * Staff and admin accounts are managed by an administrator.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/account_privacy.php';
require_once __DIR__ . '/../includes/rate_limit.php';

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

// Re-check limit (shared with Google linking): every attempt reserves a slot
// before the password check; only a wrong password keeps it. The refund runs
// in $fail, after any rollback, so it is never undone with the transaction.
$pdo = null;
$reauthKey = $reauthWindow = null;
$passwordFailed = false;
$fail = function (string $message) use (&$pdo, &$reauthKey, &$reauthWindow, &$passwordFailed): void {
    if ($pdo !== null && !$passwordFailed) {
        try {
            rate_limit_refund($pdo, $reauthKey, $reauthWindow);
        } catch (PDOException $e) {
            error_log('TravelEase: account re-check refund unavailable (code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
        }
    }
    $_SESSION['account_error'] = $message;
    header('Location: /profile.php#delete-account');
    exit;
};

$confirm = $_POST['confirm'] ?? '';
$password = $_POST['password'] ?? '';
if (!is_string($confirm) || trim($confirm) !== 'DELETE' || !is_string($password)) {
    $fail('Type DELETE in capital letters to confirm.');
}

$pdo = get_db_connection();
$userId = (int) current_user_id();
$picture = null;
$reauthKey = rate_limit_key('reauth_user', (string) $userId);
try {
    $reauthWait = rate_limit_reserve($pdo, $reauthKey, REAUTH_MAX_FAILURES, REAUTH_WINDOW_SECONDS, $reauthWindow);
} catch (PDOException $e) {
    $reauthKey = $reauthWindow = null;
    error_log('TravelEase: account re-check unavailable (code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    $fail('Could not delete your account. Nothing was changed. Please try again.');
}
if ($reauthWait > 0) {
    $fail('Too many password attempts. Please wait about ' . max(1, (int) ceil($reauthWait / 60)) . ' minutes and try again.');
}
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT role, password_hash, profile_picture FROM users WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if (!$user || $user['role'] !== 'customer') {
        $pdo->rollBack();
        http_response_code(403);
        exit('Only customer accounts can be deleted here.');
    }
    if ($user['password_hash'] !== null) {
        if ($password === '' || !password_verify($password, $user['password_hash'])) {
            $pdo->rollBack();
            $passwordFailed = $password !== '';   // an empty field is not a guess
            $fail('That password is not correct.');
        }
    } elseif ((int) ($_SESSION['authenticated_at'] ?? 0) < time() - 600) {
        $pdo->rollBack();
        $fail('For your security, sign out and sign in with Google again, then delete your account within 10 minutes.');
    }

    // Lock every trip this customer owns, so nobody can join one between the
    // check and the delete (accepting an invitation takes the same trip lock).
    $stmt = $pdo->prepare('SELECT id FROM trips WHERE user_id = :id FOR UPDATE');
    $stmt->execute(['id' => $userId]);
    $stmt->fetchAll();
    $blockers = account_deletion_blockers($pdo, $userId);
    if ($blockers) {
        $pdo->rollBack();
        $fail('Transfer or delete these shared trips first: ' . implode(', ', $blockers) . '.');
    }

    $stmt = $pdo->prepare('UPDATE itinerary_item_comments c JOIN users u ON u.id = c.user_id
                           SET c.author_name = u.name WHERE c.user_id = :id AND c.author_name IS NULL');
    $stmt->execute(['id' => $userId]);
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id AND role = 'customer'");
    $stmt->execute(['id' => $userId]);
    $picture = $user['profile_picture'];
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TravelEase: account deletion failed (database code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    $fail('Could not delete your account. Nothing was changed. Please try again.');
}

// Remove the uploaded profile picture, only if it is a file in the upload folder.
if (is_string($picture) && preg_match('#^/uploads/profile_pictures/([A-Za-z0-9_.-]+)$#', $picture, $m)) {
    $path = __DIR__ . '/../uploads/profile_pictures/' . $m[1];
    if (is_file($path)) {
        @unlink($path);
    }
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: /auth/login.php?account_deleted=1');
exit;
