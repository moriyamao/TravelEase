<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/trip_activity.php';

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
$issueNo = filter_input(INPUT_POST, 'issue_no', FILTER_VALIDATE_INT);
$decision = (string) ($_POST['decision'] ?? '');
if (!$invitationId || !$issueNo || !in_array($decision, ['accept', 'decline'], true)) {
    http_response_code(400);
    die('Invalid invitation response.');
}

$pdo = get_db_connection();
try {
    // Find the trip without locking, then lock in the same order as every
    // other collaboration write (trip row first, then the invitation), so
    // a simultaneous revoke or member change cannot deadlock with this.
    $stmt = $pdo->prepare('SELECT trip_id FROM trip_invitations WHERE id = :id AND invitee_id = :user_id');
    $stmt->execute(['id' => $invitationId, 'user_id' => current_user_id()]);
    $tripId = $stmt->fetchColumn();
    if ($tripId === false) {
        http_response_code(404);
        die('Invitation is no longer available.');
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id FROM trips WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $tripId]);

    // issue_no must match the invitation the form was rendered for: a
    // reopened invitation gets a new issue_no, so an old page cannot
    // accept a newer invitation with a different role.
    $stmt = $pdo->prepare(
        'SELECT trip_id, role, status, expires_at > UTC_TIMESTAMP() AS is_active
         FROM trip_invitations
         WHERE id = :id AND invitee_id = :user_id AND issue_no = :issue_no FOR UPDATE'
    );
    $stmt->execute(['id' => $invitationId, 'user_id' => current_user_id(), 'issue_no' => $issueNo]);
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
    log_trip_activity(
        $pdo,
        (int) $invitation['trip_id'],
        $decision === 'accept' ? 'invitation_accepted' : 'invitation_declined',
        null,
        TRIP_ROLE_LABELS[$invitation['role']] ?? $invitation['role'],
        $decision === 'decline'
    );
    $pdo->commit();
    header('Location: ' . ($decision === 'accept'
        ? '/customer/trips/view.php?id=' . (int) $invitation['trip_id']
        : '/customer/dashboard.php'));
    exit;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('TravelEase: invitation response failed (database code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    http_response_code(500);
    die('Could not respond to the invitation. Please try again.');
}
