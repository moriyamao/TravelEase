<?php
/**
 * Trip member management (FR-13) and leaving a trip (FR-14).
 *
 * Owner-only actions: change_role, remove, transfer, revoke_invite.
 * Member action:      leave (any non-owner member).
 *
 * trips.user_id stays the canonical owner and trip_memberships always
 * holds exactly one matching 'owner' row; every change that touches
 * ownership runs in one transaction with the trip row locked.
 * Permissions are read from the database on every request, so a removed
 * or downgraded member loses access on their very next request.
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_activity.php';

require_role(['customer']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed.');
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Your session expired. Please go back and try again.');
}

$tripId = filter_input(INPUT_POST, 'trip_id', FILTER_VALIDATE_INT);
$action = (string) ($_POST['action'] ?? '');
if (!$tripId || !in_array($action, ['change_role', 'remove', 'transfer', 'revoke_invite', 'leave'], true)) {
    http_response_code(400);
    die('Invalid request.');
}

$pdo = get_db_connection();
$me = (int) current_user_id();
$back = '/customer/trips/view.php?id=' . $tripId;

function member_notice(string $text, bool $error = false): void
{
    $_SESSION[$error ? 'trip_member_error' : 'trip_member_notice'] = $text;
}

// Non-members get 404; members get 403 for owner-only actions.
require_trip_permission($pdo, $tripId, $action === 'leave' ? 'view' : 'manage_trip');

try {
    $pdo->beginTransaction();

    // Lock the trip row so concurrent ownership changes serialize.
    $stmt = $pdo->prepare('SELECT user_id FROM trips WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $tripId]);
    $ownerId = (int) $stmt->fetchColumn();

    if ($action === 'leave') {
        if ($ownerId === $me) {
            $pdo->rollBack();
            member_notice('Owners cannot leave their own trip. Transfer ownership to another member first.', true);
            header('Location: ' . $back);
            exit;
        }
        $stmt = $pdo->prepare('DELETE FROM trip_memberships WHERE trip_id = :trip_id AND user_id = :user_id AND role <> \'owner\'');
        $stmt->execute(['trip_id' => $tripId, 'user_id' => $me]);
        if ($stmt->rowCount() !== 1) {
            // Removed by the owner while this request waited for the lock.
            $pdo->rollBack();
            http_response_code(404);
            die('Trip not found.');
        }
        log_trip_activity($pdo, $tripId, 'member_left');
        $pdo->commit();
        $_SESSION['dashboard_notice'] = 'You left the trip. Its plan and other members were not changed.';
        header('Location: /customer/dashboard.php');
        exit;
    }

    // Re-check ownership inside the lock (it may have just been transferred).
    if ($ownerId !== $me) {
        $pdo->rollBack();
        http_response_code(403);
        die('Your role on this trip does not allow this action.');
    }

    if ($action === 'revoke_invite') {
        $invitationId = filter_input(INPUT_POST, 'invitation_id', FILTER_VALIDATE_INT);
        $issueNo = filter_input(INPUT_POST, 'issue_no', FILTER_VALIDATE_INT);
        // issue_no must match the invitation shown on the owner's page, so an
        // old page cannot revoke a newer, reissued invitation.
        $stmt = $pdo->prepare(
            'UPDATE trip_invitations SET status = \'revoked\', responded_at = UTC_TIMESTAMP()
             WHERE id = :id AND trip_id = :trip_id AND issue_no = :issue_no AND status = \'pending\''
        );
        $stmt->execute(['id' => (int) $invitationId, 'trip_id' => $tripId, 'issue_no' => (int) $issueNo]);
        if ($stmt->rowCount() === 1) {
            log_trip_activity($pdo, $tripId, 'invitation_revoked');
        }
        $pdo->commit();
        member_notice($stmt->rowCount() === 1 ? 'Invitation revoked.' : 'That invitation changed or is no longer pending. Reload the page and try again.', $stmt->rowCount() !== 1);
        header('Location: ' . $back);
        exit;
    }

    // change_role, remove and transfer target an existing non-owner member.
    $memberId = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);

    // Transfer: only a current Customer can own a trip (the management
    // routes are customer-only). Lock the target's account row BEFORE their
    // membership row -- the same order an account deletion uses (user row,
    // then its memberships via the cascade) -- so the two cannot deadlock.
    if ($action === 'transfer') {
        $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => (int) $memberId]);
        if ($stmt->fetchColumn() !== 'customer') {
            $pdo->rollBack();
            member_notice('Ownership can only be transferred to a customer account.', true);
            header('Location: ' . $back);
            exit;
        }
    }

    $stmt = $pdo->prepare(
        'SELECT role FROM trip_memberships WHERE trip_id = :trip_id AND user_id = :user_id AND role <> \'owner\' FOR UPDATE'
    );
    $stmt->execute(['trip_id' => $tripId, 'user_id' => (int) $memberId]);
    $memberRole = $stmt->fetchColumn();
    if ($memberRole === false || (int) $memberId === $ownerId) {
        $pdo->rollBack();
        member_notice('That person is not a member of this trip.', true);
        header('Location: ' . $back);
        exit;
    }

    if ($action === 'change_role') {
        $role = (string) ($_POST['role'] ?? '');
        if (!in_array($role, ['editor', 'commenter', 'viewer'], true)) {
            $pdo->rollBack();
            http_response_code(400);
            die('Invalid role.');
        }
        $stmt = $pdo->prepare('UPDATE trip_memberships SET role = :role WHERE trip_id = :trip_id AND user_id = :user_id');
        $stmt->execute(['role' => $role, 'trip_id' => $tripId, 'user_id' => $memberId]);
        if ($role !== $memberRole) {
            log_trip_activity($pdo, $tripId, 'member_role_changed', (int) $memberId,
                (TRIP_ROLE_LABELS[$memberRole] ?? $memberRole) . ' to ' . TRIP_ROLE_LABELS[$role]);
        }
        $pdo->commit();
        member_notice('Member role updated.');
    } elseif ($action === 'remove') {
        $stmt = $pdo->prepare('DELETE FROM trip_memberships WHERE trip_id = :trip_id AND user_id = :user_id');
        $stmt->execute(['trip_id' => $tripId, 'user_id' => $memberId]);
        // Record the access removal on their invitation, if any.
        $stmt = $pdo->prepare(
            'UPDATE trip_invitations SET status = \'revoked\', responded_at = UTC_TIMESTAMP()
             WHERE trip_id = :trip_id AND invitee_id = :user_id'
        );
        $stmt->execute(['trip_id' => $tripId, 'user_id' => $memberId]);
        log_trip_activity($pdo, $tripId, 'member_removed', (int) $memberId);
        $pdo->commit();
        member_notice('Member removed. Their access ended immediately.');
    } else { // transfer (target eligibility checked above, under lock)
        // New owner: trips.user_id + their row becomes 'owner'.
        // Old owner stays on the trip as an editor. Exactly one owner remains.
        $stmt = $pdo->prepare('UPDATE trips SET user_id = :new_owner WHERE id = :id');
        $stmt->execute(['new_owner' => $memberId, 'id' => $tripId]);
        $stmt = $pdo->prepare('UPDATE trip_memberships SET role = \'owner\' WHERE trip_id = :trip_id AND user_id = :user_id');
        $stmt->execute(['trip_id' => $tripId, 'user_id' => $memberId]);
        $stmt = $pdo->prepare(
            'INSERT INTO trip_memberships (trip_id, user_id, role) VALUES (:trip_id, :user_id, \'editor\')
             ON DUPLICATE KEY UPDATE role = \'editor\''
        );
        $stmt->execute(['trip_id' => $tripId, 'user_id' => $me]);
        log_trip_activity($pdo, $tripId, 'ownership_transferred', (int) $memberId);
        $pdo->commit();
        member_notice('Ownership transferred. You are now an editor on this trip.');
    }
    header('Location: ' . $back);
    exit;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TravelEase: trip member action failed: ' . $e->getMessage());
    http_response_code(500);
    die('Something went wrong. Please try again.');
}
