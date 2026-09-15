<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    header('Location: /includes/redirect_dashboard.php');
    exit;
}

$errors = [];
$email  = '';

// Very basic login rate limiting per session to slow down brute force.
// (A production deployment would back this with a persistent store/IP
// tracking; this is intentionally minimal for the current milestone.)
$_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? 0;
$_SESSION['login_attempts_window'] = $_SESSION['login_attempts_window'] ?? time();

if (time() - $_SESSION['login_attempts_window'] > 300) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_attempts_window'] = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($_SESSION['login_attempts'] >= 10) {
        $errors[] = 'Too many login attempts. Please wait a few minutes and try again.';
    } elseif (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $email    = normalize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $errors[] = 'Please enter both email and password.';
        } else {
            $pdo = get_db_connection();

            $stmt = $pdo->prepare(
                'SELECT id, name, password_hash, role FROM users WHERE email = :email LIMIT 1'
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
                $_SESSION['login_attempts']++;
                $errors[] = 'Incorrect email or password.';
            } else {
                $_SESSION['login_attempts'] = 0;

                session_regenerate_id(true);
                $_SESSION['user_id']   = (int) $user['id'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_name'] = $user['name'];

                header('Location: /includes/redirect_dashboard.php');
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
    <link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>
    <main class="auth-container">
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

        <div class="auth-divider"><span>or</span></div>

        <div id="google-signin-error" class="form-errors" role="alert" style="display:none;"></div>

        <!-- Google's official button renders here via Identity Services. -->
        <div id="google-signin-button"></div>
    </main>

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
