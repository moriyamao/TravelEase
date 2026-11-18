<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/google_auth.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
function link_fail(int $status, string $message): void { http_response_code($status); echo json_encode(['error' => $message]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_logged_in()) link_fail(401, 'Please sign in first.');
if (!csrf_verify($_POST['csrf_token'] ?? null)) link_fail(403, 'Your session expired. Please refresh and try again.');
$password = $_POST['password'] ?? '';
$credential = $_POST['credential'] ?? '';
if ($password === '' || $credential === '') link_fail(400, 'Password and Google credential are required.');
$clientId = getenv('GOOGLE_CLIENT_ID') ?: '';
if ($clientId === '') { error_log('TravelEase: GOOGLE_CLIENT_ID is not configured.'); link_fail(500, 'Google linking is not available right now.'); }
try {
    $googleUser = verify_google_id_token($credential, $clientId);
    if (!$googleUser['email_verified']) link_fail(401, 'Your Google account email is not verified.');
    $pdo = get_db_connection();
    $stmt = $pdo->prepare('SELECT id, email, password_hash FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => current_user_id()]);
    $user = $stmt->fetch();
    if (!$user || $user['password_hash'] === null || !password_verify($password, $user['password_hash'])) link_fail(403, 'Password verification failed.');
    if (normalize_email($googleUser['email']) !== normalize_email($user['email'])) link_fail(409, 'The Google email must match your TravelEase email.');
    $stmt = $pdo->prepare('UPDATE users SET google_subject = :sub WHERE id = :id AND google_subject IS NULL');
    $stmt->execute(['sub' => $googleUser['sub'], 'id' => current_user_id()]);
    if ($stmt->rowCount() !== 1) link_fail(409, 'This Google account is already linked to an account.');
    echo json_encode(['success' => true]);
} catch (GoogleAuthException $e) {
    error_log('TravelEase: Google link verification failed: ' . $e->getMessage());
    link_fail(401, 'We could not verify your Google account. Please try again.');
} catch (PDOException $e) {
    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
        link_fail(409, 'This Google account is already linked to an account.');
    }
    throw $e;
}
