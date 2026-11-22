<?php
/**
 * FR-34: download a copy of your own TravelEase data as one JSON file.
 * POST with CSRF so the download is always a deliberate action.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/account_privacy.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}
$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || !csrf_verify($token)) {
    http_response_code(403);
    exit('Your session expired. Reload the page and try again.');
}

$pdo = get_db_connection();
try {
    $data = build_account_export($pdo, (int) current_user_id());
} catch (Throwable $e) {
    error_log('TravelEase: account export failed (' . get_class($e) . ').');
    $_SESSION['account_error'] = 'Could not prepare your data. Please try again.';
    header('Location: /profile.php#your-data');
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="travelease-data-' . gmdate('Y-m-d') . '.json"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
