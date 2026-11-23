<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

// Signing out is a state change, so it only happens on a POST with this
// session's CSRF token (the header's Sign out button). A plain link, image
// or cross-site page can no longer sign someone out. Anything else shows a
// small confirmation form instead.
$valid = $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null);

if (!$valid) {
    if (!is_logged_in()) {
        header('Location: /auth/login.php');
        exit;
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign out — TravelEase</title>
    <link rel="icon" href="/assets/images/travelease-logo-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/pages.css">
</head>
<body class="auth-page">
    <div class="auth-shell auth-shell-single">
        <main class="auth-container" id="main-content">
            <h1>Sign out of TravelEase?</h1>
            <form method="post" action="/auth/logout.php">
                <?= csrf_field() ?>
                <button type="submit">Sign out</button>
            </form>
            <p class="auth-alt-action"><a href="<?= htmlspecialchars(dashboard_path_for_role(current_user_role()), ENT_QUOTES, 'UTF-8') ?>">Stay signed in</a></p>
        </main>
    </div>
</body>
</html>
    <?php
    exit;
}

// Clear all session data.
$_SESSION = [];

// Remove the session cookie itself.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header('Location: /auth/login.php');
exit;
