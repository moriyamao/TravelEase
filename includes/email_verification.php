<?php
require_once __DIR__ . '/transactional_mail.php';

function reserve_email_verification_resend(PDO $pdo, int $userId, string $ipAddress): bool
{
    $stmt = $pdo->prepare(
        'INSERT INTO email_verification_resend_limits (user_id, ip_address, last_sent_at)
         VALUES (:user_id, :ip_address, NULL)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)'
    );
    $stmt->execute(['user_id' => $userId, 'ip_address' => $ipAddress]);

    $stmt = $pdo->prepare(
        'UPDATE email_verification_resend_limits
         SET last_sent_at = UTC_TIMESTAMP()
         WHERE user_id = :user_id AND ip_address = :ip_address
           AND (last_sent_at IS NULL OR last_sent_at <= UTC_TIMESTAMP() - INTERVAL 60 SECOND)'
    );
    $stmt->execute(['user_id' => $userId, 'ip_address' => $ipAddress]);

    return $stmt->rowCount() === 1;
}

function create_email_verification_token(PDO $pdo, int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);

    $stmt = $pdo->prepare('DELETE FROM email_verification_tokens WHERE expires_at <= UTC_TIMESTAMP()');
    $stmt->execute();

    $stmt = $pdo->prepare(
        'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))'
    );
    $stmt->execute(['user_id' => $userId, 'token_hash' => $hash]);

    return $token;
}

function release_email_verification_resend(PDO $pdo, int $userId, string $ipAddress): void
{
    $stmt = $pdo->prepare(
        'UPDATE email_verification_resend_limits SET last_sent_at = NULL
         WHERE user_id = :user_id AND ip_address = :ip_address'
    );
    $stmt->execute(['user_id' => $userId, 'ip_address' => $ipAddress]);
}

/**
 * Sends the verification link through Brevo's HTTPS API (Render blocks
 * outbound SMTP). Returns true only when Brevo accepts the message.
 * Links always use the configured APP_URL, never the request's Host
 * header, so a forged Host cannot redirect a token elsewhere.
 */
function send_email_verification(PDO $pdo, int $userId, string $email, string $name): bool
{
    $config = transactional_mail_config();
    if ($config === null) {
        error_log('TravelEase: verification email not sent; APP_URL, BREVO_API_KEY or MAIL_FROM_EMAIL is missing or invalid.');
        return false;
    }
    $baseUrl = $config['base_url'];

    $token = create_email_verification_token($pdo, $userId);
    $link = $baseUrl . '/auth/verify_email.php?token=' . urlencode($token);
    $result = send_transactional_email($email, $name, 'Verify your TravelEase email address',
        "Hello {$name},\n\nPlease verify your TravelEase account by opening this link:\n{$link}\n\nThis link expires in 24 hours and can only be used once.\n");
    if ($result !== 'sent') {
        // Don't leave a usable token behind for an email that never went out.
        $stmt = $pdo->prepare('DELETE FROM email_verification_tokens WHERE token_hash = :token_hash');
        $stmt->execute(['token_hash' => hash('sha256', $token)]);
        return false;
    }
    return true;
}
