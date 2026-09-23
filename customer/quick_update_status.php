<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

require_role(['customer']);
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

$tripId = filter_var($input['trip_id'] ?? null, FILTER_VALIDATE_INT);
$status = trim($input['status'] ?? '');

$validStatuses = ['planning', 'confirmed', 'completed', 'cancelled'];

if ($tripId === false || $tripId === null || !in_array($status, $validStatuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request.']);
    exit;
}

$pdo = get_db_connection();

// Ownership check in the WHERE clause itself -- same IDOR-prevention
// pattern used everywhere else. A customer can only ever update their
// own trips, no matter what trip_id is sent.
$stmt = $pdo->prepare(
    'UPDATE trips SET status = :status WHERE id = :id AND user_id = :user_id'
);
$stmt->execute([
    'status'  => $status,
    'id'      => $tripId,
    'user_id' => current_user_id(),
]);

echo json_encode(['ok' => true]);