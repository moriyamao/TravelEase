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
 * Re-checks the logged-in user's row against the database on every
 * protected request. Catches two things a stale session otherwise
 * misses: an admin demoting/promoting someone (session keeps the old
 * role until this runs), and an admin deleting the account entirely
 * (session would otherwise keep working until it expires).
 */
function sync_current_user_session(): void
{
    require_once __DIR__ . '/../config/database.php';

    $pdo = get_db_connection();
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id');
    $stmt->execute(['id' => current_user_id()]);
    $row = $stmt->fetch();

    if ($row === false) {
        // Account no longer exists -- kill the session outright.
        session_unset();
        session_destroy();
        header('Location: /auth/login.php');
        exit;
    }

    if ($row['role'] !== current_user_role()) {
        $_SESSION['user_role'] = $row['role'];
    }
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

    sync_current_user_session();
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