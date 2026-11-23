<?php
/**
 * Secure session bootstrap.
 * Include this at the top of every PHP entry point BEFORE any output.
 */

if (session_status() === PHP_SESSION_NONE) {
    // Render terminates TLS at its proxy and forwards plain HTTP, so HTTPS is
    // empty there; the proxy reports the original scheme in X-Forwarded-Proto.
    // APP_ENV=production forces Secure regardless. (A forged header can only
    // make the cookie stricter, never weaker.)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || getenv('APP_ENV') === 'production';

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,      // only sent over HTTPS in production
        'httponly' => true,          // not accessible to JavaScript
        'samesite' => 'Lax',         // CSRF mitigation for cross-site requests
    ]);

    session_start();
}
