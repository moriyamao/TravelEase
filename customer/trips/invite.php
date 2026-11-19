<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/trip_membership.php';

const TRIP_INVITE_LOOKUPS_PER_HOUR = 20;

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
$me = (int) current_user_id();
$back = '/customer/trips/view.php?id=' . $tripId;

// Fast path: only the owner may invite (re-checked under the lock below).
require_trip_permission($pdo, $tripId, 'manage_trip');

function invite_redirect(PDO $pdo, string $key, string $text, string $back): void
{
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    $_SESSION[$key] = $text;
    header('Location: ' . $back);
    exit;
}

try {
    // 1. Per-account lookup limit. Locking this account's row serializes
    //    concurrent requests from the same account, so the count and the
    //    insert cannot interleave. Every attempt counts, including
    //    unknown addresses, and is recorded before the email lookup.
    //    Only this account's own expired rows are pruned (indexed).
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $me]);
    $stmt = $pdo->prepare('DELETE FROM trip_invite_attempts WHERE user_id = :user_id AND created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    $stmt->execute(['user_id' => $me]);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM trip_invite_attempts WHERE user_id = :user_id AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR');
    $stmt->execute(['user_id' => $me]);
    if ((int) $stmt->fetchColumn() >= TRIP_INVITE_LOOKUPS_PER_HOUR) {
        $pdo->rollBack();
        http_response_code(429);
        header('Retry-After: 3600');
        die('Invitation limit reached. Please try again later.');
    }
    $stmt = $pdo->prepare('INSERT INTO trip_invite_attempts (user_id, created_at) VALUES (:user_id, UTC_TIMESTAMP())');
    $stmt->execute(['user_id' => $me]);
    $pdo->commit();

    // 2. Decide and write under the trip lock, so an ownership transfer
    //    or member change cannot commit between the checks and the write.
    $pdo->beginTransaction();
    lock_trip_and_require_permission($pdo, $tripId, 'manage_trip');

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM trip_invitations WHERE trip_id = :trip_id AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR');
    $stmt->execute(['trip_id' => $tripId]);
    if ((int) $stmt->fetchColumn() >= 10) {
        $pdo->rollBack();
        http_response_code(429);
        header('Retry-After: 3600');
        die('Invitation limit reached. Please try again later.');
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND email_verified_at IS NOT NULL AND role = :role LIMIT 1');
    $stmt->execute(['email' => $email, 'role' => 'customer']);
    $inviteeId = $stmt->fetchColumn();
    if ($inviteeId === false || (int) $inviteeId === $me) {
        invite_redirect($pdo, 'trip_invite_error', 'No eligible verified customer was found. No invitation was created.', $back);
    }

    $stmt = $pdo->prepare('SELECT 1 FROM trip_memberships WHERE trip_id = :trip_id AND user_id = :user_id');
    $stmt->execute(['trip_id' => $tripId, 'user_id' => $inviteeId]);
    if ($stmt->fetch()) {
        invite_redirect($pdo, 'trip_invite_error', 'This customer is already a trip member. No invitation was created.', $back);
    }

    // One invitation row per (trip, invitee), enforced by a unique key. An
    // unexpired pending invitation is left alone; a declined, expired or
    // revoked one is reopened with the new role, a fresh 7-day window and
    // a new issue_no, so forms for the earlier issue no longer match.
    $stmt = $pdo->prepare(
        'SELECT 1 FROM trip_invitations
         WHERE trip_id = :trip_id AND invitee_id = :invitee_id
           AND status = \'pending\' AND expires_at > UTC_TIMESTAMP()'
    );
    $stmt->execute(['trip_id' => $tripId, 'invitee_id' => $inviteeId]);
    if ($stmt->fetch()) {
        invite_redirect($pdo, 'trip_invite_error', 'This customer already has a pending invitation to this trip.', $back);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO trip_invitations (trip_id, invitee_id, role, expires_at)
         VALUES (:trip_id, :invitee_id, :role, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY))
         ON DUPLICATE KEY UPDATE
           role = :reopen_role,
           status = \'pending\',
           issue_no = issue_no + 1,
           expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY),
           responded_at = NULL,
           created_at = UTC_TIMESTAMP()'
    );
    $stmt->execute([
        'trip_id' => $tripId, 'invitee_id' => $inviteeId,
        'role' => $role, 'reopen_role' => $role,
    ]);
    invite_redirect($pdo, 'trip_invite_notice', 'Invitation created. The recipient will see it on their dashboard.', $back);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TravelEase: trip invitation failed: ' . $e->getMessage());
    http_response_code(500);
    die('Could not create the invitation. Please try again.');
}
