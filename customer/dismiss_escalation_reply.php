<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

require_role(['customer']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed.');
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Your session expired. Please go back and try again.');
}

$escalationId = filter_input(INPUT_POST, 'escalation_id', FILTER_VALIDATE_INT);

if ($escalationId === false || $escalationId === null) {
    http_response_code(400);
    die('Invalid request.');
}

$pdo = get_db_connection();

try {
    // Ownership check in the WHERE clause itself -- same IDOR-prevention
    // pattern used everywhere else in this app. A customer cannot
    // dismiss another customer's escalation reply by changing the ID.
    $stmt = $pdo->prepare(
        'UPDATE escalations SET customer_seen_at = NOW() WHERE id = :id AND user_id = :user_id'
    );
    $stmt->execute([
        'id'      => $escalationId,
        'user_id' => current_user_id(),
    ]);

    header('Location: /customer/dashboard.php');
    exit;

} catch (PDOException $e) {
    error_log('TravelEase: dismiss escalation reply failed: ' . $e->getMessage());
    header('Location: /customer/dashboard.php');
    exit;
}