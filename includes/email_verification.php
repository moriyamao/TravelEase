<?php

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

    $stmt = $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = :user_id OR expires_at <= UTC_TIMESTAMP()');
    $stmt->execute(['user_id' => $userId]);

    $stmt = $pdo->prepare(
        'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))'
    );
    $stmt->execute(['user_id' => $userId, 'token_hash' => $hash]);

    return $token;
}

function send_email_verification(PDO $pdo, int $userId, string $email, string $name): bool
{
    $token = create_email_verification_token($pdo, $userId);
    $baseUrl = rtrim(getenv('APP_URL') ?: '', '/');
    if ($baseUrl === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000');
    }
    $link = $baseUrl . '/auth/verify_email.php?token=' . urlencode($token);
    $subject = 'Verify your TravelEase email address';
    $message = "Hello {$name},\n\nPlease verify your TravelEase account by opening this link:\n{$link}\n\nThis link expires in 24 hours and can only be used once.\n";
    $headers = "Content-Type: text/plain; charset=UTF-8\r\n";

    return mail($email, $subject, $message, $headers);
}
