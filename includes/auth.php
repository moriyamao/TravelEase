<?php
/**
 * Authentication / authorization guard helpers.
 * These are the only functions the rest of the app should use to check
 * "who is logged in" and "are they allowed to be here" (§25 — Authorization).
 */

function current_user_id(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

function current_user_role(): ?string
{
    return $_SESSION['user_role'] ?? null;
}

function is_logged_in(): bool
{
    return current_user_id() !== null;
}

/**
 * Redirects to login if the user is not authenticated.
 * Call at the top of any page that requires a logged-in user.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: /auth/login.php');
        exit;
    }
}

/**
 * Redirects/blocks if the user does not have one of the allowed roles.
 * Role is always read from the server-side session — never from
 * anything the client sends (§25).
 *
 * @param string[] $allowedRoles
 */
function require_role(array $allowedRoles): void
{
    require_login();

    if (!in_array(current_user_role(), $allowedRoles, true)) {
        http_response_code(403);
        die('You do not have permission to access this page.');
    }
}
