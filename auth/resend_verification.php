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
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Resend Verification — TravelEase</title><link rel="stylesheet" href="/assets/css/tokens.css"><link rel="icon" href="/assets/images/travelease-logo-mark.svg?v=2" type="image/svg+xml"><link rel="stylesheet" href="/assets/css/auth.css"><link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/pages.css"></head><body class="auth-page"><div class="auth-shell"><aside class="auth-panel"><a class="auth-panel-brand" href="/public/index.php"><img src="/assets/images/travelease-logo-mark-light.svg?v=2" alt="" width="40" height="40"><span class="app-wordmark">Travel<span>Ease</span></span></a><div class="auth-panel-copy"><p class="auth-panel-kicker">Plan · organize · go</p><p class="auth-panel-title">Every route, date and plan in one calm place.</p></div><svg class="auth-panel-route" viewBox="0 0 600 260" aria-hidden="true" focusable="false"><path d="M20 230C300 230 300 60 560 60" fill="none" stroke="#f6f2e8" stroke-opacity=".9" stroke-width="10" stroke-linecap="round"/><path d="M20 250H580" fill="none" stroke="#f6f2e8" stroke-opacity=".18" stroke-width="3" stroke-linecap="round" stroke-dasharray="1 14"/><circle cx="20" cy="230" r="13" fill="#f6f2e8"/><path d="M560 92c-12-16-24-27-24-44a24 24 0 1 1 48 0c0 17-12 28-24 44Z" fill="#bd4d28"/><circle cx="560" cy="48" r="9" fill="#f6f2e8"/></svg></aside><main class="auth-container" id="main-content"><h1>Resend verification email</h1><?php if ($message): ?><p class="success-message" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?><?php if ($errors): ?><ul class="form-errors" role="alert"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul><?php endif; ?><form method="post" novalidate><?= csrf_field() ?><label for="email">Email</label><input type="email" id="email" name="email" required value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"><button type="submit">Send verification email</button></form><p class="auth-alt-action"><a href="/auth/login.php">Back to sign in</a></p></main></div></body></html>
