<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../config/database.php';

require_role(['customer']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Your session expired. Please try again.');
}

$tripId = filter_input(INPUT_POST, 'trip_id', FILTER_VALIDATE_INT);
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$role = (string) ($_POST['role'] ?? '');
if (!$tripId || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['editor', 'commenter', 'viewer'], true)) {
    http_response_code(400);
    die('Invalid invitation details.');
}

$pdo = get_db_connection();
$stmt = $pdo->prepare('SELECT id FROM trips WHERE id = :trip_id AND user_id = :user_id');
$stmt->execute(['trip_id' => $tripId, 'user_id' => current_user_id()]);
if (!$stmt->fetch()) {
    http_response_code(404);
    die('Trip not found.');
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM trip_invitations WHERE trip_id = :trip_id AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR');
$stmt->execute(['trip_id' => $tripId]);
if ((int) $stmt->fetchColumn() >= 10) {
    http_response_code(429);
    die('Invitation limit reached. Please try again later.');
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND email_verified_at IS NOT NULL AND role = :role LIMIT 1');
$stmt->execute(['email' => $email, 'role' => 'customer']);
$inviteeId = $stmt->fetchColumn();
if ($inviteeId === false || (int) $inviteeId === current_user_id()) {
    $_SESSION['trip_invite_error'] = 'No eligible verified customer was found. No invitation was created.';
    header('Location: /customer/trips/view.php?id=' . $tripId);
    exit;
}

$stmt = $pdo->prepare('SELECT 1 FROM trip_memberships WHERE trip_id = :trip_id AND user_id = :user_id');
$stmt->execute(['trip_id' => $tripId, 'user_id' => $inviteeId]);
if ($stmt->fetch()) {
    $_SESSION['trip_invite_error'] = 'This customer is already a trip member. No invitation was created.';
    header('Location: /customer/trips/view.php?id=' . $tripId);
    exit;
}

// One invitation row per (trip, invitee), enforced by a unique key. An
// unexpired pending invitation is left alone; a declined, expired or
// revoked one is reopened with the new role and a fresh 7-day window.
$stmt = $pdo->prepare(
    'SELECT 1 FROM trip_invitations
     WHERE trip_id = :trip_id AND invitee_id = :invitee_id
       AND status = \'pending\' AND expires_at > UTC_TIMESTAMP()'
);
$stmt->execute(['trip_id' => $tripId, 'invitee_id' => $inviteeId]);
if ($stmt->fetch()) {
    $_SESSION['trip_invite_error'] = 'This customer already has a pending invitation to this trip.';
    header('Location: /customer/trips/view.php?id=' . $tripId);
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO trip_invitations (trip_id, invitee_id, role, expires_at)
     VALUES (:trip_id, :invitee_id, :role, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY))
     ON DUPLICATE KEY UPDATE
       role = :reopen_role,
       status = \'pending\',
       expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY),
       responded_at = NULL,
       created_at = UTC_TIMESTAMP()'
);
$stmt->execute([
    'trip_id' => $tripId, 'invitee_id' => $inviteeId,
    'role' => $role, 'reopen_role' => $role,
]);
$_SESSION['trip_invite_notice'] = 'Invitation created. The recipient will see it on their dashboard.';
header('Location: /customer/trips/view.php?id=' . $tripId);
exit;
