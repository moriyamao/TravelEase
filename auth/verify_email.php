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
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Email Verification — TravelEase</title><link rel="stylesheet" href="/assets/css/tokens.css"><link rel="stylesheet" href="/assets/css/auth.css"></head><body><main class="auth-container"><h1>Email verification</h1><p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><p class="auth-alt-action"><a href="/auth/login.php">Sign in</a></p></main></body></html>
