<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

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
if ($targetUserId === false || $targetUserId === null) {
    http_response_code(400);
    die('Invalid user.');
}

// An admin cannot delete their own account from this page -- there is
// no "are you sure, really" recovery path if the only admin account
// deletes itself mid-session. If self-deletion is ever genuinely
// needed, it belongs on the account's own settings page, not here.
if ($targetUserId === current_user_id()) {
    $_SESSION['admin_errors'] = ['You cannot delete your own account.'];
    header('Location: /admin/dashboard.php');
    exit;
}

$pdo = get_db_connection();

try {
    // ON DELETE CASCADE on trips.user_id (and itinerary_items.trip_id
    // in turn) takes care of the user's trips and itinerary items --
    // no separate cleanup queries needed here.
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute(['id' => $targetUserId]);

    header('Location: /admin/dashboard.php');
    exit;

} catch (PDOException $e) {
    error_log('TravelEase: admin user delete failed: ' . $e->getMessage());
    $_SESSION['admin_errors'] = ['Something went wrong while deleting the account. Please try again.'];
    header('Location: /admin/dashboard.php');
    exit;
}
