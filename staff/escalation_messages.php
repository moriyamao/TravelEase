<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_role(['staff', 'admin']);
header('Content-Type: application/json');

$escalationId = filter_input(INPUT_GET, 'escalation_id', FILTER_VALIDATE_INT);

if ($escalationId === false || $escalationId === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request.']);
    exit;
}

$pdo = get_db_connection();

$stmt = $pdo->prepare(
    'SELECT sender_type, message, created_at
     FROM escalation_messages
     WHERE escalation_id = :id
     ORDER BY created_at ASC'
);
$stmt->execute(['id' => $escalationId]);

echo json_encode(['messages' => $stmt->fetchAll()]);