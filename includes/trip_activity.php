<?php
/**
 * FR-18 trip activity history.
 *
 * log_trip_activity() must be called inside the transaction that makes
 * the change (after the trip lock), so an event is recorded only if the
 * change commits. A failure throws PDOException and the caller's existing
 * rollback undoes the change too.
 *
 * Privacy: no email addresses are stored. Pending invitations are shown
 * by role only; a person's name appears once they have joined the trip.
 */

/** Event type => sentence template. {actor}, {target}, {details}. */
const TRIP_ACTIVITY_EVENTS = [
    'trip_created'          => '{actor} created the trip',
    'trip_updated'          => '{actor} updated trip details: {details}',
    'member_invited'        => '{actor} invited someone as {details}',
    'invitation_revoked'    => '{actor} revoked a pending invitation',
    'invitation_accepted'   => '{actor} joined the trip as {details}',
    'invitation_declined'   => 'An invitation as {details} was declined',
    'member_role_changed'   => '{actor} changed {target}\'s role: {details}',
    'member_removed'        => '{actor} removed {target} from the trip',
    'member_left'           => '{actor} left the trip',
    'ownership_transferred' => '{actor} transferred ownership to {target}',
    'activity_added'        => '{actor} added the activity "{details}"',
    'activity_updated'      => '{actor} updated the activity "{details}"',
    'activity_deleted'      => '{actor} deleted the activity "{details}"',
    'comment_added'         => '{actor} commented on "{details}"',
    'activity_completed'    => '{actor} marked "{details}" as done',
    'activity_reopened'     => '{actor} reopened "{details}"',
    'activity_moved_up'     => '{actor} moved "{details}" earlier in the day',
    'activity_moved_down'   => '{actor} moved "{details}" later in the day',
    'place_added'           => '{actor} saved the place "{details}"',
    'place_updated'         => '{actor} updated the place "{details}"',
    'place_deleted'         => '{actor} removed the place "{details}"',
    'place_linked'          => '{actor} linked a place to "{details}"',
    'place_unlinked'        => '{actor} unlinked the place from "{details}"',
    'expense_added'         => '{actor} recorded the expense "{details}"',
    'expense_removed'       => '{actor} removed the expense "{details}"',
    'expense_share_settled' => '{actor} marked a share of "{details}" as settled',
    'expense_share_reopened' => '{actor} reopened a share of "{details}"',
];

const TRIP_ROLE_LABELS = [
    'owner' => 'Owner', 'editor' => 'Editor', 'commenter' => 'Commenter', 'viewer' => 'Viewer',
];

function log_trip_activity(PDO $pdo, int $tripId, string $eventType, ?int $targetUserId = null, ?string $details = null, bool $anonymous = false): void
{
    // $anonymous stores no actor (e.g. a declined invitation: the person
    // never joined the trip, so their identity is not kept in its history).
    if (!isset(TRIP_ACTIVITY_EVENTS[$eventType])) {
        throw new InvalidArgumentException('Unknown trip activity event: ' . $eventType);
    }
    if ($details !== null) {
        // Keep at most 255 characters (code points), matching the column.
        if (preg_match('/^.{0,255}/us', $details, $m) === 1) {
            $details = $m[0];
        }
    }
    $stmt = $pdo->prepare(
        'INSERT INTO trip_activity_log (trip_id, actor_id, event_type, target_user_id, details)
         VALUES (:trip_id, :actor_id, :event_type, :target_user_id, :details)'
    );
    $stmt->execute([
        'trip_id'        => $tripId,
        'actor_id'       => $anonymous ? null : current_user_id(),
        'event_type'     => $eventType,
        'target_user_id' => $targetUserId,
        'details'        => $details,
    ]);
}

/** Plain-text sentence for one history row (escape when rendering). */
function trip_activity_sentence(array $row): string
{
    $template = TRIP_ACTIVITY_EVENTS[$row['event_type']] ?? '{actor} made a change';
    $actor = $row['actor_id'] === null ? 'Deleted user' : (string) $row['actor_name'];
    $target = $row['target_user_id'] === null ? 'a deleted user' : (string) $row['target_name'];
    return strtr($template, [
        '{actor}'   => $actor,
        '{target}'  => $target,
        '{details}' => (string) ($row['details'] ?? ''),
    ]);
}
