<?php
/**
 * FR-32: what support staff may see about customers' trips.
 *
 * Staff see support conversations and how many trips the customer is on.
 * They see a trip's details and itinerary only after its owner shares it
 * from an open support request, and only while that share is active, the
 * request is still open and the sharer still owns the trip.
 */

/** The customer's current open support request, or null. */
function customer_open_escalation_id(PDO $pdo, int $userId): ?int
{
    $stmt = $pdo->prepare(
        'SELECT id FROM escalations WHERE user_id = :user_id AND status = \'open\'
         ORDER BY created_at DESC, id DESC LIMIT 1'
    );
    $stmt->execute(['user_id' => $userId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/** Number of trips the customer owns or is a member of. */
function customer_trip_count(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM trips t
         WHERE t.user_id = :owner_id OR EXISTS (
             SELECT 1 FROM trip_memberships m WHERE m.trip_id = t.id AND m.user_id = :member_id)'
    );
    $stmt->execute(['owner_id' => $userId, 'member_id' => $userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Shares staff may open for one support request: active, request open,
 * sharer still the trip's owner. Rows: trip_id, name, start_date, end_date, status, shared_at.
 */
function viewable_support_shares(PDO $pdo, int $escalationId): array
{
    $stmt = $pdo->prepare(
        'SELECT s.trip_id, t.name, t.start_date, t.end_date, t.status, s.shared_at
         FROM support_trip_shares s
         JOIN escalations e ON e.id = s.escalation_id AND e.status = \'open\'
         JOIN trips t ON t.id = s.trip_id AND t.user_id = s.shared_by
         WHERE s.escalation_id = :id AND s.revoked_at IS NULL
         ORDER BY s.shared_at DESC, s.trip_id DESC'
    );
    $stmt->execute(['id' => $escalationId]);
    return $stmt->fetchAll();
}

function staff_can_view_shared_trip(PDO $pdo, int $escalationId, int $tripId): bool
{
    foreach (viewable_support_shares($pdo, $escalationId) as $share) {
        if ((int) $share['trip_id'] === $tripId) {
            return true;
        }
    }
    return false;
}
