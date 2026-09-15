<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/google_auth.php';

header('Content-Type: application/json');

function fail(int $httpStatus, string $userMessage): void
{
    http_response_code($httpStatus);
    echo json_encode(['error' => $userMessage]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Method not allowed.');
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    fail(403, 'Your session expired. Please refresh and try again.');
}

$credential = $_POST['credential'] ?? '';
if ($credential === '') {
    fail(400, 'Missing sign-in credential.');
}

$clientId = getenv('GOOGLE_CLIENT_ID') ?: '';
if ($clientId === '') {
    // Misconfiguration, not a user error -- log details, tell user nothing specific.
    error_log('TravelEase: GOOGLE_CLIENT_ID is not configured.');
    fail(500, 'Google sign-in is not available right now.');
}

try {
    $googleUser = verify_google_id_token($credential, $clientId);
} catch (GoogleAuthException $e) {
    // Log the real reason server-side; never show token-verification
    // internals to the browser (§22 -- Google Login Error Handling).
    error_log('TravelEase: Google token verification failed: ' . $e->getMessage());
    fail(401, 'We could not verify your Google sign-in. Please try again.');
}

if (!$googleUser['email_verified']) {
    fail(401, 'Your Google account email is not verified.');
}

$email = normalize_email($googleUser['email']);
$sub   = $googleUser['sub'];
$name  = $googleUser['name'] !== '' ? $googleUser['name'] : $email;

$pdo = get_db_connection();

try {
    // 1. Already linked? Log them straight in.
    $stmt = $pdo->prepare('SELECT id, role, name FROM users WHERE google_subject = :sub LIMIT 1');
    $stmt->execute(['sub' => $sub]);
    $user = $stmt->fetch();

    if ($user) {
        log_in_user((int) $user['id'], $user['role'], $user['name']);
        echo json_encode(['redirect' => '/includes/redirect_dashboard.php']);
        exit;
    }

    // 2. An account with this email already exists but isn't linked to
    //    Google. Do NOT auto-link -- that's a deliberate security
    //    decision (see project instructions §19). Ask them to use
    //    their password instead. A proper "link your Google account"
    //    flow is a future enhancement, not implied by this milestone.
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    if ($stmt->fetch()) {
        fail(409, 'An account with this email already exists. Please sign in with your password instead.');
    }

    // 3. Brand new user via Google. Role is always 'customer' --
    //    never settable by the client, Google or otherwise (§20).
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, google_subject, role)
         VALUES (:name, :email, NULL, :sub, :role)'
    );
    $stmt->execute([
        'name'  => $name,
        'email' => $email,
        'sub'   => $sub,
        'role'  => 'customer',
    ]);

    $newUserId = (int) $pdo->lastInsertId();
    log_in_user($newUserId, 'customer', $name);
    echo json_encode(['redirect' => '/includes/redirect_dashboard.php']);

} catch (PDOException $e) {
    error_log('TravelEase: Google login DB error: ' . $e->getMessage());
    fail(500, 'Something went wrong while signing you in. Please try again.');
}

/**
 * Establishes a normal TravelEase session -- identical in shape to
 * the one set by auth/login.php, so every existing guard
 * (require_login, require_role) works unchanged regardless of which
 * authentication method was used.
 */
function log_in_user(int $userId, string $role, string $name): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']   = $userId;
    $_SESSION['user_role'] = $role;
    $_SESSION['user_name'] = $name;
    $_SESSION['login_attempts'] = 0;
}
