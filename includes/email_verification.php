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
    $baseUrl = rtrim(getenv('APP_URL') ?: '', '/');
    $apiKey = getenv('BREVO_API_KEY') ?: '';
    $fromEmail = getenv('MAIL_FROM_EMAIL') ?: '';
    $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
    if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)
        || $apiKey === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('TravelEase: verification email not sent; APP_URL, BREVO_API_KEY or MAIL_FROM_EMAIL is missing or invalid.');
        return false;
    }

    $token = create_email_verification_token($pdo, $userId);
    $link = $baseUrl . '/auth/verify_email.php?token=' . urlencode($token);
    $payload = json_encode([
        'sender'      => ['email' => $fromEmail, 'name' => getenv('MAIL_FROM_NAME') ?: 'TravelEase'],
        'to'          => [['email' => $email, 'name' => $name]],
        'subject'     => 'Verify your TravelEase email address',
        'textContent' => "Hello {$name},\n\nPlease verify your TravelEase account by opening this link:\n{$link}\n\nThis link expires in 24 hours and can only be used once.\n",
    ]);

    // BREVO_API_URL is only for pointing local tests at a stub; leave it unset.
    $ch = curl_init(getenv('BREVO_API_URL') ?: 'https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        error_log("TravelEase: Brevo rejected verification email for user {$userId} (HTTP {$httpCode}) {$curlError}");
        // Don't leave a usable token behind for an email that never went out.
        $stmt = $pdo->prepare('DELETE FROM email_verification_tokens WHERE token_hash = :token_hash');
        $stmt->execute(['token_hash' => hash('sha256', $token)]);
        return false;
    }
    return true;
}
