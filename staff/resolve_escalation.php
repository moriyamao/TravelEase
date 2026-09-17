<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

require_role(['staff', 'admin']);

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
    // This updates the escalation ticket's own state -- not customer
    // trip data -- so it doesn't carry the same "silently overwriting
    // customer-owned data" concern the trip status field did.
    $stmt = $pdo->prepare(
        "UPDATE escalations SET status = 'resolved', resolved_at = NOW() WHERE id = :id"
    );
    $stmt->execute(['id' => $escalationId]);

    header('Location: /staff/escalations.php');
    exit;

} catch (PDOException $e) {
    error_log('TravelEase: resolve escalation failed: ' . $e->getMessage());
    header('Location: /staff/escalations.php');
    exit;
}