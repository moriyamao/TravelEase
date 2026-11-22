<?php
require_once __DIR__ . '/../config/database.php';

$message = 'This verification link is invalid or has expired.';
$token = $_GET['token'] ?? '';
if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $pdo = get_db_connection();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'UPDATE email_verification_tokens
         SET used_at = UTC_TIMESTAMP()
         WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()'
    );
    $stmt->execute(['token_hash' => hash('sha256', $token)]);
    if ($stmt->rowCount() === 1) {
        $stmt = $pdo->prepare('SELECT user_id FROM email_verification_tokens WHERE token_hash = :token_hash LIMIT 1');
        $stmt->execute(['token_hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        $stmt = $pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = :user_id');
        $stmt->execute(['user_id' => $row['user_id']]);
        $pdo->commit();
        $message = 'Your email is verified. You can now sign in.';
    } else {
        $pdo->rollBack();
    }
}
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Email Verification — TravelEase</title><link rel="stylesheet" href="/assets/css/tokens.css"><link rel="icon" href="/assets/images/travelease-logo-mark.svg" type="image/svg+xml"><link rel="stylesheet" href="/assets/css/auth.css"><link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/pages.css"></head><body class="auth-page"><div class="auth-shell"><aside class="auth-panel"><a class="auth-panel-brand" href="/public/index.php"><img src="/assets/images/travelease-logo-mark-light.svg" alt="" width="40" height="40"><span class="app-wordmark">Travel<span>Ease</span></span></a><div class="auth-panel-copy"><p class="auth-panel-kicker">Plan · organize · go</p><p class="auth-panel-title">Every route, date and plan in one calm place.</p></div><svg class="auth-panel-route" viewBox="0 0 600 260" aria-hidden="true" focusable="false"><path d="M20 230C300 230 300 60 560 60" fill="none" stroke="#f6f2e8" stroke-opacity=".9" stroke-width="10" stroke-linecap="round"/><path d="M20 250H580" fill="none" stroke="#f6f2e8" stroke-opacity=".18" stroke-width="3" stroke-linecap="round" stroke-dasharray="1 14"/><circle cx="20" cy="230" r="13" fill="#f6f2e8"/><path d="M560 92c-12-16-24-27-24-44a24 24 0 1 1 48 0c0 17-12 28-24 44Z" fill="#bd4d28"/><circle cx="560" cy="48" r="9" fill="#f6f2e8"/></svg></aside><main class="auth-container" id="main-content"><h1>Email verification</h1><p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><p class="auth-alt-action"><a href="/auth/login.php">Sign in</a></p></main></div></body></html>
