<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chat_ai.php';
require_once __DIR__ . '/../includes/rate_limit.php';

header('Content-Type: application/json');

require_login(); // any logged-in role can use the chatbot

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

// 10 messages per 5 minutes per account, counted in the database so signing
// out and back in (a new session) does not reset it. Fails closed.
try {
    $chatWait = rate_limit_reserve(get_db_connection(), rate_limit_key('chat_user', (string) current_user_id()), 10, 300);
} catch (PDOException $e) {
    error_log('TravelEase: chat rate limit unavailable (code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    http_response_code(503);
    echo json_encode(['error' => 'The assistant is unavailable right now. Please try again later.']);
    exit;
}
if ($chatWait > 0) {
    http_response_code(429);
    header('Retry-After: ' . $chatWait);
    echo json_encode(['error' => 'Too many messages. Please wait a moment before trying again.']);
    exit;
}

$message = trim((string) ($input['message'] ?? ''));

if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a message.']);
    exit;
}

if (mb_strlen($message) > 1000) {
    http_response_code(400);
    echo json_encode(['error' => 'Message is too long (1000 characters max).']);
    exit;
}

$result = ask_ai($message);

// Commit the support conversation before acknowledging the handoff.
// The client may also send an optional EmailJS notification after success.
//
// If the customer already has an open escalation, fold this message
// into that existing thread rather than opening a second, parallel
// ticket -- a customer shouldn't end up mid-conversation with support
// in two places at once just because the AI separately decided to
// escalate something.
if ($result['escalate']) {
    $pdo = null;
    try {
        $pdo = get_db_connection();
        $pdo->beginTransaction();

        // Serialize this account's escalations even when no open thread exists.
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :user_id FOR UPDATE');
        $stmt->execute(['user_id' => current_user_id()]);
        $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT id FROM escalations WHERE user_id = :user_id AND status = \'open\' ORDER BY created_at DESC LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['user_id' => current_user_id()]);
        $existing = $stmt->fetch();

        if ($existing) {
            $escalationId = $existing['id'];
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO escalations (user_id, message) VALUES (:user_id, :message)'
            );
            $stmt->execute([
                'user_id' => current_user_id(),
                'message' => $message,
            ]);
            $escalationId = $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare(
            'INSERT INTO escalation_messages (escalation_id, sender_type, sender_id, message)
             VALUES (:id, \'customer\', :sender_id, :message)'
        );
        $stmt->execute([
            'id'        => $escalationId,
            'sender_id' => current_user_id(),
            'message'   => $message,
        ]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo !== null && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('TravelEase: failed to record escalation: ' . $e->getMessage());
        http_response_code(503);
        header('Retry-After: 5');
        echo json_encode(['error' => 'Your support message could not be saved. Please try again in a moment.']);
        exit;
    }
}

echo json_encode([
    'reply'    => $result['reply'],
    'escalate' => $result['escalate'],
]);
