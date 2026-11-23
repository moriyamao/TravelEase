<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rate_limit.php';

if (is_logged_in()) {
    header('Location: /includes/redirect_dashboard.php');
    exit;
}

$errors = [];
$email  = '';

// Failed sign-ins are limited in the database (survives new sessions and
// dropped cookies): 10 per email address and 50 per IP address in 15 minutes.
// The IP limit is looser because many people can share one address.
const LOGIN_WINDOW_SECONDS = 900;
const LOGIN_MAX_FAILURES_PER_EMAIL = 10;
const LOGIN_MAX_FAILURES_PER_IP = 50;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $email    = normalize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $pdo = get_db_connection();
        $emailKey = rate_limit_key('login_email', $email);
        $ipKey = rate_limit_key('login_ip', client_ip());

        if (rate_limit_wait($pdo, $emailKey, LOGIN_MAX_FAILURES_PER_EMAIL, LOGIN_WINDOW_SECONDS) > 0
            || rate_limit_wait($pdo, $ipKey, LOGIN_MAX_FAILURES_PER_IP, LOGIN_WINDOW_SECONDS) > 0) {
            $errors[] = 'Too many sign-in attempts. Please wait a few minutes and try again.';
        } elseif ($email === '' || $password === '') {
            $errors[] = 'Please enter both email and password.';
        } else {

            $stmt = $pdo->prepare(
                'SELECT id, name, email, password_hash, email_verified_at, role FROM users WHERE email = :email LIMIT 1'
            );
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            // Generic error message regardless of which part failed,
            // so we don't reveal whether the email exists.
            // password_hash can be NULL for a future Google-only account
            // (no password set) — treat that the same as a failed login
            // here rather than passing null into password_verify().
            if (!$user || $user['password_hash'] === null
                || !password_verify($password, $user['password_hash'])) {
                rate_limit_hit($pdo, $emailKey, LOGIN_WINDOW_SECONDS);
                rate_limit_hit($pdo, $ipKey, LOGIN_WINDOW_SECONDS);
                $errors[] = 'Incorrect email or password.';
            } elseif ($user['email_verified_at'] === null) {
                $errors[] = 'Please verify your email address before signing in. You can request a new verification email below.';
            } else {
                session_regenerate_id(true);
               $_SESSION['user_id']    = (int) $user['id'];
               $_SESSION['user_role']  = $user['role'];
               $_SESSION['user_name']  = $user['name'];
               $_SESSION['user_email'] = $user['email'];
               $_SESSION['authenticated_at'] = time(); // FR-34: recent sign-in check for account deletion

                // Go straight to the role's dashboard (role was just read
                // from the database above); that page re-checks access.
                header('Location: ' . dashboard_path_for_role($user['role']));
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="icon" href="/assets/images/travelease-logo-mark.svg" type="image/svg+xml"><link rel="stylesheet" href="/assets/css/auth.css"><link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/pages.css">
</head>
<body class="auth-page">
    <div class="auth-shell"><aside class="auth-panel"><a class="auth-panel-brand" href="/public/index.php"><img src="/assets/images/travelease-logo-mark-light.svg" alt="" width="40" height="40"><span class="app-wordmark">Travel<span>Ease</span></span></a><div class="auth-panel-copy"><p class="auth-panel-kicker">Plan · organize · go</p><p class="auth-panel-title">Every route, date and plan in one calm place.</p></div><svg class="auth-panel-route" viewBox="0 0 600 260" aria-hidden="true" focusable="false"><path d="M20 230C300 230 300 60 560 60" fill="none" stroke="#f6f2e8" stroke-opacity=".9" stroke-width="10" stroke-linecap="round"/><path d="M20 250H580" fill="none" stroke="#f6f2e8" stroke-opacity=".18" stroke-width="3" stroke-linecap="round" stroke-dasharray="1 14"/><circle cx="20" cy="230" r="13" fill="#f6f2e8"/><path d="M560 92c-12-16-24-27-24-44a24 24 0 1 1 48 0c0 17-12 28-24 44Z" fill="#bd4d28"/><circle cx="560" cy="48" r="9" fill="#f6f2e8"/></svg></aside>
    <main class="auth-container" id="main-content">
        <?php if (isset($_GET['account_deleted'])): ?>
            <p class="success-message" role="status">Your account was deleted.</p>
        <?php endif; ?>
        <h1>Sign in to TravelEase</h1>

        <?php if (!empty($errors)): ?>
            <ul class="form-errors" role="alert">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="post" action="/auth/login.php" novalidate>
            <?= csrf_field() ?>

            <label for="email">Email</label>
            <input type="email" id="email" name="email" required
                   value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <button type="submit">Sign in</button>
        </form>

        <p class="auth-alt-action">
            Don't have an account? <a href="/auth/register.php">Create account</a>
        </p>
        <p class="auth-alt-action"><a href="/auth/resend_verification.php">Resend verification email</a></p>
        <p class="auth-alt-action"><a href="/public/information.php">Terms, privacy, AI and services</a></p>

        <div class="auth-divider"><span>or</span></div>

        <div id="google-signin-error" class="form-errors" role="alert" style="display:none;"></div>

        <!-- Google's official button renders here via Identity Services. -->
        <div id="google-signin-button"></div>
    </main></div>

    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script>
        window.onload = function () {
            google.accounts.id.initialize({
                client_id: <?= json_encode(getenv('GOOGLE_CLIENT_ID') ?: '', JSON_UNESCAPED_SLASHES) ?>,
                callback: handleGoogleCredential
            });
            google.accounts.id.renderButton(
                document.getElementById('google-signin-button'),
                { theme: 'outline', size: 'large', width: 320 }
            );
        };

        function handleGoogleCredential(response) {
            const errorBox = document.getElementById('google-signin-error');
            errorBox.style.display = 'none';

            const formData = new URLSearchParams();
            formData.set('credential', response.credential);
            formData.set('csrf_token', <?= json_encode(csrf_token()) ?>);

            fetch('/auth/google_login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
                .then(async (res) => {
                    const data = await res.json();
                    if (!res.ok) {
                        throw new Error(data.error || 'Google sign-in failed.');
                    }
                    window.location.href = data.redirect;
                })
                .catch((err) => {
                    errorBox.textContent = err.message;
                    errorBox.style.display = 'block';
                });
        }
    </script>
</body>
</html>
