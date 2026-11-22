<?php
require_once __DIR__ . '/../config/env.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (PHP_SAPI !== 'cli') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST'); http_response_code(405); exit('{"error":"POST required"}');
    }
    $secret = getenv('REMINDER_JOB_SECRET') ?: '';
    if (strlen($secret) < 32) { http_response_code(503); exit('{"error":"Job is not configured"}'); }
    if (!hash_equals($secret, $_SERVER['HTTP_X_REMINDER_KEY'] ?? '')) {
        http_response_code(403); exit('{"error":"Forbidden"}');
    }
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/trip_documents.php';
ignore_user_abort(true);
set_time_limit(45);
try {
    $pdo = get_db_connection();
    if (PHP_SAPI === 'cli' && in_array('--dry-run', $argv, true)) {
        $count = $pdo->query('SELECT COUNT(*) FROM trip_documents WHERE state = \'deleting\'
            OR reference_id IS NULL OR uploaded_by IS NULL
            OR (state = \'pending\' AND created_at < UTC_TIMESTAMP() - INTERVAL 1 HOUR)')->fetchColumn();
        echo json_encode(['dry_run' => true, 'cleanup_candidates' => (int) $count, 'no_writes' => true]);
    } else {
        echo json_encode(cleanup_trip_documents($pdo));
    }
} catch (Throwable $e) {
    error_log('TravelEase: document cleanup failed (' . get_class($e) . ').');
    http_response_code(503); echo '{"error":"Document cleanup unavailable"}';
    if (PHP_SAPI === 'cli') { exit(1); }
}
