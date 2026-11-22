<?php
/** Shared by verification and reminder emails; credentials never leave the server. */
function transactional_mail_config(): ?array
{
    $base = rtrim(getenv('APP_URL') ?: '', '/');
    $key = getenv('BREVO_API_KEY') ?: '';
    $from = getenv('MAIL_FROM_EMAIL') ?: '';
    if (!filter_var($base, FILTER_VALIDATE_URL)
        || !in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)
        || $key === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    return ['base_url' => $base, 'key' => $key, 'from' => $from,
        'name' => getenv('MAIL_FROM_NAME') ?: 'TravelEase',
        'api_url' => getenv('BREVO_API_URL') ?: 'https://api.brevo.com/v3/smtp/email'];
}

/** 'sent' means accepted by Brevo; a transport failure can have an uncertain outcome. */
function send_transactional_email(string $email, string $name, string $subject, string $text, int $timeout = 10): string
{
    $config = transactional_mail_config();
    if ($config === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'failed';
    }
    $payload = json_encode([
        'sender' => ['email' => $config['from'], 'name' => $config['name']],
        'to' => [['email' => $email, 'name' => $name]], 'subject' => $subject, 'textContent' => $text,
    ]);
    if ($payload === false) { return 'failed'; }
    $ch = curl_init($config['api_url']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $config['key']],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_TIMEOUT => $timeout]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response !== false && $status >= 200 && $status < 300) { return 'sent'; }
    error_log('TravelEase: transactional email was not confirmed (HTTP ' . $status . ').');
    return $response === false || $status >= 500 ? 'unknown' : 'failed';
}
