<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email_verification.php';
require_once __DIR__ . '/../includes/rate_limit.php';

// Already logged in? No need to register again.
if (is_logged_in()) {
    header('Location: /includes/redirect_dashboard.php');
    exit;
}

$errors = [];
$name   = '';
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $name     = trim($_POST['name'] ?? '');
        $email    = normalize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if ($err = validate_name($name))            $errors[] = $err;
        if ($err = validate_email_format($email))    $errors[] = $err;
        if ($err = validate_password($password))     $errors[] = $err;
        if ($password !== $confirm)                  $errors[] = 'Passwords do not match.';

        // The same message for new and already-registered addresses, so this
        // form cannot be used to find out who has an account.
        $registeredMessage = 'If that email can be registered, we sent a verification link to it. Check your inbox before signing in. If nothing arrives, use "Resend verification email" on the sign-in page.';

        if (empty($errors)) {
            $pdo = get_db_connection();
            // Each attempt can send an email (Brevo quota), so cap attempts in the
            // database: 10 per IP address and 3 per email address per hour.
            $wait = max(
                rate_limit_reserve($pdo, rate_limit_ip_key('register_ip'), 10, 3600),
                rate_limit_reserve($pdo, rate_limit_key('register_email', $email), 3, 3600)
            );

            // Check whether an account with this email already exists.
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);

            if ($wait > 0) {
                $errors[] = 'Too many sign-up attempts. Please try again in about ' . max(1, (int) ceil($wait / 60)) . ' minutes.';
            } elseif ($stmt->fetch()) {
                $success = $registeredMessage;
            } else {
                try {
                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    $stmt = $pdo->prepare(
                        'INSERT INTO users (name, email, password_hash, email_verified_at, role)
                         VALUES (:name, :email, :password_hash, NULL, :role)'
                    );
                    $stmt->execute([
                        'name'          => $name,
                        'email'         => $email,
                        'password_hash' => $hash,
                        'role'          => 'customer', // role is NEVER taken from the client
                    ]);

                    $userId = (int) $pdo->lastInsertId();

                    if (!send_email_verification($pdo, $userId, $email, $name)) {
                        error_log('TravelEase: verification email not sent after registration.');
                    }
                    $success = $registeredMessage;

                } catch (PDOException $e) {
                    error_log('TravelEase registration failed: ' . $e->getMessage());
                    $errors[] = 'Something went wrong while creating your account. Please try again.';
                }
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
    <title>Create Account — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="icon" href="/assets/images/travelease-logo-mark.svg" type="image/svg+xml"><link rel="stylesheet" href="/assets/css/auth.css"><link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/pages.css">
</head>
<body class="auth-page">
    <div class="auth-shell"><aside class="auth-panel"><a class="auth-panel-brand" href="/public/index.php"><img src="/assets/images/travelease-logo-mark-light.svg" alt="" width="40" height="40"><span class="app-wordmark">Travel<span>Ease</span></span></a><div class="auth-panel-copy"><p class="auth-panel-kicker">Plan · organize · go</p><p class="auth-panel-title">Every route, date and plan in one calm place.</p></div><svg class="auth-panel-route" viewBox="0 0 600 260" aria-hidden="true" focusable="false"><path d="M20 230C300 230 300 60 560 60" fill="none" stroke="#f6f2e8" stroke-opacity=".9" stroke-width="10" stroke-linecap="round"/><path d="M20 250H580" fill="none" stroke="#f6f2e8" stroke-opacity=".18" stroke-width="3" stroke-linecap="round" stroke-dasharray="1 14"/><circle cx="20" cy="230" r="13" fill="#f6f2e8"/><path d="M560 92c-12-16-24-27-24-44a24 24 0 1 1 48 0c0 17-12 28-24 44Z" fill="#bd4d28"/><circle cx="560" cy="48" r="9" fill="#f6f2e8"/></svg></aside>
    <main class="auth-container" id="main-content">
        <h1>Create your TravelEase account</h1>

        <?php if (!empty($errors)): ?>
            <ul class="form-errors" role="alert">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <p class="success-message" role="status"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form method="post" action="/auth/register.php" novalidate>
            <?= csrf_field() ?>

            <label for="name">Full name</label>
            <input type="text" id="name" name="name" required maxlength="100"
                   value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">

            <label for="email">Email</label>
            <input type="email" id="email" name="email" required maxlength="255"
                   value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required minlength="8">

            <label for="confirm_password">Confirm password</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8">

            <button type="submit">Create account</button>
        </form>

        <p class="auth-alt-action">
            Already have an account? <a href="/auth/login.php">Sign in</a>
        </p>
        <p class="auth-alt-action">How TravelEase handles your information: <a href="/public/information.php">terms, privacy, AI and services</a></p>
    </main></div>
</body>
</html>
