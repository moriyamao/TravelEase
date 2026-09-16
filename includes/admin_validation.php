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
