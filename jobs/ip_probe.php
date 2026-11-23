<?php
/**
 * TEMPORARY diagnostic (remove after use): shows which client-address
 * headers reach PHP behind Render, to decide CLIENT_IP_SOURCE.
 * Answers 404 unless the X-Probe-Token header matches a one-time token whose
 * SHA-256 is below (the token itself is not in the repository). Returns only
 * address headers; logs nothing; no cookies, session, database or secrets.
 */
header('Cache-Control: no-store');
$expected = '62b198a236e131f021221f7a2d844ef95d4224f31f8d4e48118490a00919c24c';
$given = (string) ($_SERVER['HTTP_X_PROBE_TOKEN'] ?? '');
if ($given === '' || !hash_equals($expected, hash('sha256', $given))) {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json');
$pick = static fn(string $k): ?string => isset($_SERVER[$k]) ? (string) $_SERVER[$k] : null;
echo json_encode([
    'remote_addr'      => $pick('REMOTE_ADDR'),
    'cf_connecting_ip' => $pick('HTTP_CF_CONNECTING_IP'),
    'true_client_ip'   => $pick('HTTP_TRUE_CLIENT_IP'),
    'x_forwarded_for'  => $pick('HTTP_X_FORWARDED_FOR'),
    'x_real_ip'        => $pick('HTTP_X_REAL_IP'),
    'cf_ray'           => $pick('HTTP_CF_RAY'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
