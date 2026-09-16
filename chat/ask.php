<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chat_ai.php';

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

// Simple per-session rate limit: 10 messages per 5 minutes. Matches
// the existing per-session (not IP-based) approach already used for
// login attempts elsewhere in this app -- consistent, no new infra.
$_SESSION['chat_requests'] = $_SESSION['chat_requests'] ?? [];
$_SESSION['chat_requests'] = array_values(array_filter(
    $_SESSION['chat_requests'],
    fn($ts) => $ts > time() - 300
));

if (count($_SESSION['chat_requests']) >= 10) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['error' => 'Too many messages. Please wait a moment before trying again.']);
    exit;
}

$_SESSION['chat_requests'][] = time();

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

echo json_encode([
    'reply'    => $result['reply'],
    'escalate' => $result['escalate'],
]);