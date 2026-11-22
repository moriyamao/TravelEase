<?php
/**
 * Server-side validation for admin-only actions (Milestone 7).
 */

const ADMIN_ASSIGNABLE_ROLES = ['customer', 'staff', 'admin'];

function validate_user_role(string $role): ?string
{
    if (!in_array($role, ADMIN_ASSIGNABLE_ROLES, true)) {
        return 'Invalid role.';
    }
    return null;
}

/**
 * FR-33: lock the acting admin's row and the target's row (primary key,
 * ascending ID, so two admins acting on each other wait in the same order)
 * and return [id => role] for the rows that still exist.
 *
 * Call inside the write transaction, then require the caller to still be
 * an admin before changing anything. The session role can be stale: another
 * admin may have demoted or deleted this account since sign-in. Because the
 * caller's row stays locked as 'admin' until commit and the caller is never
 * the target, every committed demotion or deletion leaves at least the
 * caller as an administrator, so the last admin can never be removed.
 */
function lock_admin_action_rows(PDO $pdo, int $callerId, int $targetId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, role FROM users WHERE id IN (:first_id, :second_id) ORDER BY id FOR UPDATE'
    );
    $stmt->execute(['first_id' => min($callerId, $targetId), 'second_id' => max($callerId, $targetId)]);
    $roles = [];
    foreach ($stmt->fetchAll() as $row) {
        $roles[(int) $row['id']] = $row['role'];
    }
    return $roles;
}
