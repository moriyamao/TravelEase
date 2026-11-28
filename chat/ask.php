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
if (!is_array($input)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid request.']));
}

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

$message = is_string($input['message'] ?? null) ? trim($input['message']) : '';

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

$tripSnapshot = null;
if (array_key_exists('trip_id', $input)) {
    $rawTripId = $input['trip_id'];
    $tripId = (is_int($rawTripId) || is_string($rawTripId))
        ? filter_var($rawTripId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]])
        : false;
    if ($tripId === false) {
        http_response_code(400);
        exit(json_encode(['error' => 'Invalid trip.']));
    }
    try {
        // Authorize and read only the allowlisted facts in one current query.
        $stmt = get_db_connection()->prepare(
            'SELECT u.role, t.status, t.start_date, t.end_date, t.time_zone,
                    t.archived_at IS NOT NULL AS archived,
                    (SELECT COUNT(*) FROM itinerary_items i WHERE i.trip_id=t.id) AS activity_total,
                    (SELECT COUNT(*) FROM itinerary_items i WHERE i.trip_id=t.id AND i.completed_at IS NOT NULL) AS activity_done
             FROM users u LEFT JOIN trips t ON t.id=:trip AND
                 (t.user_id=u.id OR EXISTS (SELECT 1 FROM trip_memberships m
                  WHERE m.trip_id=t.id AND m.user_id=u.id AND m.role IN (\'editor\',\'commenter\',\'viewer\')))
             WHERE u.id=:user'
        );
        $stmt->execute(['trip' => $tripId, 'user' => current_user_id()]);
        $snapshot = $stmt->fetch();
        if (!$snapshot || $snapshot['role'] !== 'customer') {
            http_response_code(403);
            exit(json_encode(['error' => 'Trip context is available to customer trip members only.']));
        }
        if ($snapshot['status'] === null) {
            http_response_code(404);
            exit(json_encode(['error' => 'Trip not found.']));
        }
        unset($snapshot['role']);
        $tripSnapshot = $snapshot;
    } catch (PDOException $e) {
        error_log('TravelEase: chat trip snapshot unavailable (code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
        http_response_code(503);
        exit(json_encode(['error' => 'Trip information is unavailable right now. Please try again later.']));
    }
}
$result = ask_ai($message, $tripSnapshot);

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
        error_log('TravelEase: failed to record escalation (code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
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
