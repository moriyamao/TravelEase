<?php
/**
 * Trip-level permissions (Owner / Editor / Commenter / Viewer).
 *
 * These are separate from the system roles (customer / staff / admin)
 * checked by require_role(). trips.user_id is the canonical owner;
 * trip_memberships holds every other member's trip role. Every trip
 * page and mutation endpoint authorizes through these helpers on the
 * server -- hiding a button is never the access check.
 */

const TRIP_PERMISSIONS = [
    'view'           => ['owner', 'editor', 'commenter', 'viewer'],
    'edit_itinerary' => ['owner', 'editor'],
    'manage_trip'    => ['owner'],
];

/**
 * The user's role on a trip, or null when they have no access.
 * Ownership always comes from trips.user_id, so a stray 'owner'
 * membership row for anyone else grants nothing.
 */
function trip_member_role(PDO $pdo, int $tripId, int $userId): ?string
{
    $stmt = $pdo->prepare(
        'SELECT t.user_id, m.role
         FROM trips t
         LEFT JOIN trip_memberships m ON m.trip_id = t.id AND m.user_id = :member_id
         WHERE t.id = :trip_id
         LIMIT 1'
    );
    $stmt->execute(['trip_id' => $tripId, 'member_id' => $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if ((int) $row['user_id'] === $userId) {
        return 'owner';
    }
    return in_array($row['role'], ['editor', 'commenter', 'viewer'], true) ? $row['role'] : null;
}

function trip_role_can(?string $role, string $permission): bool
{
    return $role !== null && in_array($role, TRIP_PERMISSIONS[$permission] ?? [], true);
}

/**
 * Stops the request unless the current user's trip role allows
 * $permission. Non-members get 404, so trip IDs they cannot access are
 * indistinguishable from trips that do not exist (IDOR prevention);
 * members without the permission get 403.
 */
function require_trip_permission(PDO $pdo, int $tripId, string $permission): string
{
    $role = trip_member_role($pdo, $tripId, (int) current_user_id());
    if ($role === null) {
        http_response_code(404);
        die('Trip not found.');
    }
    if (!trip_role_can($role, $permission)) {
        http_response_code(403);
        die('Your role on this trip does not allow this action.');
    }
    return $role;
}
