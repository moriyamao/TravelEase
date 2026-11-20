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
    // FR-17: vote and comment on itinerary activities. Viewers read only.
    'feedback'       => ['owner', 'editor', 'commenter'],
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

/**
 * Write-time permission check. Call inside a transaction, right before
 * the write. Locks the trip row FOR UPDATE -- the lock every member,
 * ownership and invitation change takes first -- then re-reads the
 * user's role with a locking read, so a role change, removal or
 * ownership transfer that commits after the page loaded cannot slip in
 * between this check and the write. Rolls back and stops on failure.
 */
function lock_trip_and_require_permission(PDO $pdo, int $tripId, string $permission): string
{
    $userId = (int) current_user_id();
    $stmt = $pdo->prepare('SELECT user_id FROM trips WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $tripId]);
    $ownerId = $stmt->fetchColumn();

    $role = null;
    if ($ownerId !== false) {
        if ((int) $ownerId === $userId) {
            $role = 'owner';
        } else {
            $stmt = $pdo->prepare(
                'SELECT role FROM trip_memberships WHERE trip_id = :trip_id AND user_id = :user_id LOCK IN SHARE MODE'
            );
            $stmt->execute(['trip_id' => $tripId, 'user_id' => $userId]);
            $memberRole = $stmt->fetchColumn();
            $role = in_array($memberRole, ['editor', 'commenter', 'viewer'], true) ? $memberRole : null;
        }
    }

    if (!trip_role_can($role, $permission)) {
        $pdo->rollBack();
        http_response_code($role === null ? 404 : 403);
        die($role === null ? 'Trip not found.' : 'Your role on this trip does not allow this action.');
    }
    return $role;
}

/**
 * FR-19: can $userId be newly assigned to an activity on this trip?
 * Yes for a current member (owner, editor, commenter or viewer) whose
 * account is a customer. Call inside the write transaction after
 * lock_trip_and_require_permission(); the share locks keep the answer
 * valid until commit.
 */
function trip_member_is_assignable(PDO $pdo, int $tripId, int $userId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM trips t
         JOIN users u ON u.id = :user_id
         LEFT JOIN trip_memberships m ON m.trip_id = t.id AND m.user_id = u.id
         WHERE t.id = :trip_id
           AND u.role = \'customer\'
           AND (t.user_id = u.id OR m.role IN (\'editor\', \'commenter\', \'viewer\'))
         LOCK IN SHARE MODE'
    );
    $stmt->execute(['user_id' => $userId, 'trip_id' => $tripId]);
    return $stmt->fetchColumn() !== false;
}
