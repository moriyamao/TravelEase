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
$reply        = trim($_POST['staff_reply'] ?? '');

if ($escalationId === false || $escalationId === null) {
    http_response_code(400);
    die('Invalid request.');
}

if ($reply === '') {
    $_SESSION['staff_errors'] = ['Reply cannot be empty.'];
    header('Location: /staff/escalations.php');
    exit;
}

if (mb_strlen($reply) > 2000) {
    $_SESSION['staff_errors'] = ['Reply is too long (2000 characters max).'];
    header('Location: /staff/escalations.php');
    exit;
}

$pdo = get_db_connection();

try {
    // Deliberately independent from `status` -- replying does not
    // resolve the ticket. Staff marks it resolved separately, once
    // they consider the matter actually settled.
    $stmt = $pdo->prepare(
        'UPDATE escalations SET staff_reply = :reply, replied_at = NOW() WHERE id = :id'
    );
    $stmt->execute([
        'reply' => $reply,
        'id'    => $escalationId,
    ]);

    header('Location: /staff/escalations.php');
    exit;

} catch (PDOException $e) {
    error_log('TravelEase: escalation reply failed: ' . $e->getMessage());
    $_SESSION['staff_errors'] = ['Something went wrong while saving your reply. Please try again.'];
    header('Location: /staff/escalations.php');
    exit;
}