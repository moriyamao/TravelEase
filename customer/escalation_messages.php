<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_role(['customer']);

header('Content-Type: application/json');

$pdo = get_db_connection();

// "Current" conversation = the customer's most recent open escalation.
// Ownership scoped by user_id, same pattern as every other query in
// this app.
$stmt = $pdo->prepare(
    'SELECT id FROM escalations
     WHERE user_id = :user_id AND status = "open"
     ORDER BY created_at DESC
     LIMIT 1'
);
$stmt->execute(['user_id' => current_user_id()]);
$escalation = $stmt->fetch();

if (!$escalation) {
    echo json_encode(['escalation_id' => null, 'messages' => []]);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT sender_type, message, created_at
     FROM escalation_messages
     WHERE escalation_id = :id
     ORDER BY created_at ASC'
);
$stmt->execute(['id' => $escalation['id']]);
$messages = $stmt->fetchAll();

echo json_encode([
    'escalation_id' => (int) $escalation['id'],
    'messages'      => $messages,
]);