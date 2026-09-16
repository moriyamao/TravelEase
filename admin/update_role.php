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
$newRole      = trim($_POST['role'] ?? '');

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
    $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
    $stmt->execute([
        'role' => $newRole,
        'id'   => $targetUserId,
    ]);

    header('Location: /admin/dashboard.php');
    exit;

} catch (PDOException $e) {
    error_log('TravelEase: admin role update failed: ' . $e->getMessage());
    $_SESSION['admin_errors'] = ['Something went wrong while updating the role. Please try again.'];
    header('Location: /admin/dashboard.php');
    exit;
}
