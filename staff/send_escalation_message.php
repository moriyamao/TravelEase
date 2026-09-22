<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

require_role(['staff', 'admin']);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (!csrf_verify($input['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['error' => 'Your session expired. Please refresh the page.']);
    exit;
}

$escalationId = filter_var($input['escalation_id'] ?? null, FILTER_VALIDATE_INT);
$message      = trim((string) ($input['message'] ?? ''));

if ($escalationId === false || $escalationId === null || $message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request.']);
    exit;
}

if (mb_strlen($message) > 2000) {
    http_response_code(400);
    echo json_encode(['error' => 'Message is too long.']);
    exit;
}

$pdo = get_db_connection();

$stmt = $pdo->prepare(
    'INSERT INTO escalation_messages (escalation_id, sender_type, sender_id, message)
     VALUES (:id, "staff", :sender_id, :message)'
);
$stmt->execute([
    'id'        => $escalationId,
    'sender_id' => current_user_id(),
    'message'   => $message,
]);

echo json_encode(['ok' => true]);