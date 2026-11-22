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
    $provided = $_SERVER['HTTP_X_REMINDER_KEY'] ?? '';
    if (!hash_equals($secret, $provided)) { http_response_code(403); exit('{"error":"Forbidden"}'); }
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/reminder_worker.php';
ignore_user_abort(true);
set_time_limit(90);
try {
    $pdo = get_db_connection();
    if (PHP_SAPI === 'cli' && in_array('--dry-run', $argv, true)) {
        $count = $pdo->query('SELECT COUNT(*) FROM trip_reminders WHERE due_at <= UTC_TIMESTAMP() AND cancelled_at IS NULL AND acknowledged_at IS NULL')->fetchColumn();
        echo json_encode(['dry_run' => true, 'existing_due' => (int) $count, 'automatic_generation' => false, 'no_writes' => true]);
    } else {
        echo json_encode(run_due_reminders($pdo));
    }
} catch (Throwable $e) {
    error_log('TravelEase: reminder job failed (' . get_class($e) . ').');
    http_response_code(500); echo '{"error":"Reminder processing failed"}';
    if (PHP_SAPI === 'cli') { exit(1); }
}
