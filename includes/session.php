<?php
/**
 * Secure session bootstrap.
 * Include this at the top of every PHP entry point BEFORE any output.
 */

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

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
