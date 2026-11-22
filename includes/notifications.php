<?php
// Generic text only: never copy comment bodies, booking codes or event details.
const NOTIFICATION_MESSAGES = [
    'invitation_received' => 'You have a pending trip invitation.',
    'trip_updated' => 'Trip details were updated.',
    'member_role_changed' => 'A trip member role changed.',
    'member_removed' => 'Trip membership changed.',
    'member_left' => 'A traveler left the trip.',
    'ownership_transferred' => 'Trip ownership changed.',
    'invitation_accepted' => 'A traveler joined the trip.',
    'activity_added' => 'An itinerary activity was added.',
    'activity_updated' => 'An itinerary activity was updated.',
    'activity_deleted' => 'An itinerary activity was removed.',
    'activity_completed' => 'An itinerary activity was completed.',
    'activity_reopened' => 'An itinerary activity was reopened.',
    'activity_moved_up' => 'The itinerary order changed.',
    'activity_moved_down' => 'The itinerary order changed.',
    'comment_added' => 'A new itinerary comment was posted.',
    'place_added' => 'A place was added to the trip.',
    'place_updated' => 'A saved place was updated.',
    'place_deleted' => 'A saved place was removed.',
    'place_linked' => 'An activity location was updated.',
    'place_unlinked' => 'An activity location was updated.',
    'expense_added' => 'A shared expense was recorded.',
    'expense_removed' => 'A shared expense was removed.',
    'expense_share_settled' => 'An expense share was marked settled.',
    'expense_share_reopened' => 'An expense share was reopened.',
    'access_removed' => 'Your access to a shared trip was removed.',
];

function notification_sentence(string $eventType): string
{
    return NOTIFICATION_MESSAGES[$eventType] ?? 'There is a trip update.';
}

/** Called within the activity write transaction, after the trip lock. */
function notify_trip_activity(PDO $pdo, int $tripId, int $eventId, string $eventType, int $actorId, ?int $targetUserId): void
{
    // Reference events intentionally have no message: their visibility is private.
    if (!isset(NOTIFICATION_MESSAGES[$eventType]) || $eventType === 'access_removed' || $eventType === 'invitation_received') {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, trip_id, event_type, activity_log_id)
         SELECT u.id, t.id, :event_type, :event_id FROM trips t
         JOIN users u ON u.id = t.user_id OR EXISTS (
             SELECT 1 FROM trip_memberships m WHERE m.trip_id = t.id AND m.user_id = u.id
             AND m.role IN (\'editor\', \'commenter\', \'viewer\')
         )
         WHERE t.id = :trip AND u.id <> :actor AND u.role = \'customer\' AND u.notify_in_app = 1
         ON DUPLICATE KEY UPDATE activity_log_id = VALUES(activity_log_id)'
    );
    $stmt->execute(['event_type' => $eventType, 'event_id' => $eventId, 'trip' => $tripId, 'actor' => $actorId]);
    if ($eventType === 'member_removed' && $targetUserId !== null && $targetUserId !== $actorId) {
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, trip_id, event_type, activity_log_id)
             SELECT id, :trip, \'access_removed\', :event_id FROM users
             WHERE id = :user_id AND role = \'customer\' AND notify_in_app = 1
             ON DUPLICATE KEY UPDATE activity_log_id = VALUES(activity_log_id)'
        );
        $stmt->execute(['trip' => $tripId, 'event_id' => $eventId, 'user_id' => $targetUserId]);
    }
}

/** Called after creating/reissuing an invitation, while holding the trip lock. */
function notify_trip_invitation(PDO $pdo, int $tripId, int $inviteeId): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, trip_id, event_type, invitation_id, invitation_issue_no)
         SELECT u.id, i.trip_id, \'invitation_received\', i.id, i.issue_no
         FROM trip_invitations i JOIN users u ON u.id = i.invitee_id
         WHERE i.trip_id = :trip AND i.invitee_id = :invitee
           AND i.status = \'pending\' AND i.expires_at > UTC_TIMESTAMP()
           AND u.role = \'customer\' AND u.notify_in_app = 1
         ON DUPLICATE KEY UPDATE invitation_issue_no = VALUES(invitation_issue_no)'
    );
    $stmt->execute(['trip' => $tripId, 'invitee' => $inviteeId]);
}

/** Shared privacy boundary for inbox rows AND unread counts. */
function visible_notifications_sql(): string
{
    return 'SELECT n.id, n.trip_id, n.event_type, n.created_at, n.read_at, n.dismissed_at,
                CASE WHEN n.event_type = \'access_removed\' THEN NULL ELSE t.name END AS trip_name,
                CASE WHEN n.event_type <> \'access_removed\' AND (t.user_id = n.user_id OR m.user_id IS NOT NULL)
                     THEN 1 ELSE 0 END AS can_open_trip,
                CASE WHEN i.id IS NOT NULL THEN 1 ELSE 0 END AS invitation_pending
            FROM notifications n JOIN trips t ON t.id = n.trip_id
            LEFT JOIN trip_memberships m ON m.trip_id = t.id AND m.user_id = n.user_id
                AND m.role IN (\'editor\', \'commenter\', \'viewer\')
            LEFT JOIN trip_invitations i ON i.id = n.invitation_id AND i.trip_id = n.trip_id
                AND i.invitee_id = n.user_id AND i.issue_no = n.invitation_issue_no
                AND i.status = \'pending\' AND i.expires_at > UTC_TIMESTAMP()
            WHERE n.user_id = :user_id AND (
                n.event_type = \'access_removed\'
                OR (n.event_type = \'invitation_received\' AND i.id IS NOT NULL)
                OR (n.event_type NOT IN (\'access_removed\', \'invitation_received\')
                    AND (t.user_id = n.user_id OR m.user_id IS NOT NULL)))';
}

function list_user_notifications(PDO $pdo, int $userId, string $filter = 'all'): array
{
    $condition = match ($filter) {
        'all' => 'dismissed_at IS NULL',
        'unread' => 'dismissed_at IS NULL AND read_at IS NULL',
        'dismissed' => 'dismissed_at IS NOT NULL',
        default => throw new InvalidArgumentException('Choose a valid notification filter.'),
    };
    $stmt = $pdo->prepare('SELECT * FROM (' . visible_notifications_sql() . ') AS inbox WHERE ' . $condition . ' ORDER BY id DESC LIMIT 100');
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll();
}

function count_unread_notifications(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM (' . visible_notifications_sql() . ') AS inbox WHERE dismissed_at IS NULL AND read_at IS NULL');
    $stmt->execute(['user_id' => $userId]);
    return (int) $stmt->fetchColumn();
}
