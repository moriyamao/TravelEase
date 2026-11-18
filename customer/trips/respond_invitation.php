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
$invitationId = filter_input(INPUT_POST, 'invitation_id', FILTER_VALIDATE_INT);
$decision = (string) ($_POST['decision'] ?? '');
if (!$invitationId || !in_array($decision, ['accept', 'decline'], true)) {
    http_response_code(400);
    die('Invalid invitation response.');
}

$pdo = get_db_connection();
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'SELECT trip_id, role, status, expires_at > UTC_TIMESTAMP() AS is_active
         FROM trip_invitations WHERE id = :id AND invitee_id = :user_id FOR UPDATE'
    );
    $stmt->execute(['id' => $invitationId, 'user_id' => current_user_id()]);
    $invitation = $stmt->fetch();
    if ($invitation && $invitation['status'] === 'pending' && !(int) $invitation['is_active']) {
        // Record the Expired state instead of leaving a dead 'pending' row.
        $stmt = $pdo->prepare('UPDATE trip_invitations SET status = \'expired\' WHERE id = :id');
        $stmt->execute(['id' => $invitationId]);
        $pdo->commit();
        http_response_code(410);
        die('This invitation has expired. Ask the trip owner to invite you again.');
    }
    if (!$invitation || $invitation['status'] !== 'pending') {
        $pdo->rollBack();
        http_response_code(404);
        die('Invitation is no longer available.');
    }
    if ($decision === 'accept') {
        $stmt = $pdo->prepare('INSERT INTO trip_memberships (trip_id, user_id, role) VALUES (:trip_id, :user_id, :role)');
        $stmt->execute(['trip_id' => $invitation['trip_id'], 'user_id' => current_user_id(), 'role' => $invitation['role']]);
    }
    $stmt = $pdo->prepare('UPDATE trip_invitations SET status = :status, responded_at = UTC_TIMESTAMP() WHERE id = :id');
    $stmt->execute(['status' => $decision === 'accept' ? 'accepted' : 'declined', 'id' => $invitationId]);
    $pdo->commit();
    header('Location: ' . ($decision === 'accept'
        ? '/customer/trips/view.php?id=' . (int) $invitation['trip_id']
        : '/customer/dashboard.php'));
    exit;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('TravelEase: invitation response failed: ' . $e->getMessage());
    http_response_code(500);
    die('Could not respond to the invitation. Please try again.');
}
