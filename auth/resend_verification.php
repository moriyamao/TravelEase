<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/email_verification.php';

$errors = [];
$message = null;
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $email = normalize_email($_POST['email'] ?? '');
        $pdo = get_db_connection();
        $stmt = $pdo->prepare('SELECT id, name, email, email_verified_at FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        if ($user && $user['email_verified_at'] === null) {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
                $ipAddress = '0.0.0.0';
            }
            if (reserve_email_verification_resend($pdo, (int) $user['id'], $ipAddress)
                && !send_email_verification($pdo, (int) $user['id'], $user['email'], $user['name'])) {
                // Delivery failed: free the 60-second window so an immediate retry is possible.
                release_email_verification_resend($pdo, (int) $user['id'], $ipAddress);
            }
        }
        // Identical for every outcome so this page never reveals whether an account exists.
        $message = 'If that account exists and still needs verification, check its inbox for an email. If none arrives, please try again later.';
    }
}
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Resend Verification — TravelEase</title><link rel="stylesheet" href="/assets/css/tokens.css"><link rel="stylesheet" href="/assets/css/auth.css"></head><body><main class="auth-container"><h1>Resend verification email</h1><?php if ($message): ?><p class="success-message" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?><?php if ($errors): ?><ul class="form-errors" role="alert"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul><?php endif; ?><form method="post" novalidate><?= csrf_field() ?><label for="email">Email</label><input type="email" id="email" name="email" required value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"><button type="submit">Send verification email</button></form><p class="auth-alt-action"><a href="/auth/login.php">Back to sign in</a></p></main></body></html>
