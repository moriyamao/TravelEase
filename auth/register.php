<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/auth.php';

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

        if (empty($errors)) {
            $pdo = get_db_connection();

            // Check whether an account with this email already exists.
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);

            if ($stmt->fetch()) {
                $errors[] = 'An account with that email already exists.';
            } else {
                try {
                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    $stmt = $pdo->prepare(
                        'INSERT INTO users (name, email, password_hash, role)
                         VALUES (:name, :email, :password_hash, :role)'
                    );
                    $stmt->execute([
                        'name'          => $name,
                        'email'         => $email,
                        'password_hash' => $hash,
                        'role'          => 'customer', // role is NEVER taken from the client
                    ]);

                    $userId = (int) $pdo->lastInsertId();

                    // Log the new user in immediately.
                    session_regenerate_id(true);
                   $_SESSION['user_id']    = $userId;
                   $_SESSION['user_role']  = 'customer';
                   $_SESSION['user_name']  = $name;
                   $_SESSION['user_email'] = $email;

                    header('Location: /includes/redirect_dashboard.php');
                    exit;

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
    <link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>
    <main class="auth-container">
        <h1>Create your TravelEase account</h1>

        <?php if (!empty($errors)): ?>
            <ul class="form-errors" role="alert">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
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
    </main>
</body>
</html>
