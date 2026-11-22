<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_validation.php';

require_role(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed.');
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Your session expired. Please go back and try again.');
}

$targetUserId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
$newRole      = is_string($_POST['role'] ?? null) ? trim($_POST['role']) : '';

if ($targetUserId === false || $targetUserId === null) {
    http_response_code(400);
    die('Invalid user.');
}

// An admin changing their own role could lock themselves out of this
// page with no other admin around to undo it -- block it outright
// rather than trying to detect/recover from that state afterward.
if ($targetUserId === current_user_id()) {
    $_SESSION['admin_errors'] = ['You cannot change your own role.'];
    header('Location: /admin/dashboard.php');
    exit;
}

if ($err = validate_user_role($newRole)) {
    $_SESSION['admin_errors'] = [$err];
    header('Location: /admin/dashboard.php');
    exit;
}

$pdo = get_db_connection();

try {
    $pdo->beginTransaction();
    $roles = lock_admin_action_rows($pdo, current_user_id(), $targetUserId);
    if (($roles[current_user_id()] ?? null) !== 'admin') {
        // Demoted or deleted by another admin since signing in.
        $pdo->rollBack();
        http_response_code(403);
        die('Your administrator access has changed. Please sign in again.');
    }
    if (!isset($roles[$targetUserId])) {
        $pdo->rollBack();
        $_SESSION['admin_errors'] = ['That account no longer exists.'];
        header('Location: /admin/dashboard.php');
        exit;
    }
    $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
    $stmt->execute([
        'role' => $newRole,
        'id'   => $targetUserId,
    ]);
    $pdo->commit();

    header('Location: /admin/dashboard.php');
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TravelEase: admin role update failed: ' . $e->getMessage());
    $_SESSION['admin_errors'] = ['Something went wrong while updating the role. Please try again.'];
    header('Location: /admin/dashboard.php');
    exit;
}
