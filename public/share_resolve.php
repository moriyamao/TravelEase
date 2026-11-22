<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/trip_shares.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST'); http_response_code(405); exit('{"error":"POST required"}');
}
$csrf = $_POST['csrf_token'] ?? null;
if (!is_string($csrf) || !csrf_verify($csrf)) {
    http_response_code(403); exit('{"error":"Reload the original share link and try again."}');
}
$token = $_POST['token'] ?? null;
if (!valid_trip_share_token($token)) { http_response_code(404); exit('{"error":"This link is unavailable or has expired."}'); }
$pdo = get_db_connection();
try {
    $wait = reserve_trip_share_access($pdo, $_SERVER['REMOTE_ADDR'] ?? '');
    if ($wait) { header('Retry-After: ' . $wait); http_response_code(429); exit('{"error":"Too many requests. Try again shortly."}'); }
    $pdo->beginTransaction();
    $share = load_trip_share($pdo, $token);
    if (!$share) { $pdo->rollBack(); http_response_code(404); exit('{"error":"This link is unavailable or has expired."}'); }
    $trip = $share['trip'];
    $result = ['kind' => $share['link']['kind'], 'destination' => $trip['destination'],
        'start_date' => $trip['start_date'], 'end_date' => $trip['end_date'], 'time_zone' => $trip['time_zone']];
    if ($share['link']['kind'] === 'view') {
        // Explicit public projection: never SELECT *, never include private notes,
        // costs, identities, feedback, booking references or personal reminders.
        $stmt = $pdo->prepare('SELECT i.item_date AS date, i.item_time AS time, i.end_time, i.title,
            COALESCE(p.name, i.location, \'\') AS location
            FROM itinerary_items i LEFT JOIN trip_places p ON p.id = i.place_id AND p.trip_id = i.trip_id
            WHERE i.trip_id = :trip ORDER BY i.item_date, i.sort_order, i.item_time, i.id LOCK IN SHARE MODE');
        $stmt->execute(['trip' => $trip['id']]);
        $result['activities'] = $stmt->fetchAll();
    }
    $pdo->commit();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('TravelEase: share-link read failed (database code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    http_response_code(503); echo '{"error":"Shared trip temporarily unavailable. Please try again."}';
}
