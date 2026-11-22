<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_documents.php';
require_role(['customer']);
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET'); http_response_code(405); exit('Method not allowed.');
}
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(404); exit('Document not found.'); }
$pdo = get_db_connection();
try {
    // Nonlocking discovery is followed by current locking reads, not a snapshot.
    $stmt = $pdo->prepare('SELECT r.trip_id, r.id AS reference_id FROM trip_documents d
        JOIN trip_references r ON r.id = d.reference_id WHERE d.id = :id');
    $stmt->execute(['id' => $id]);
    $found = $stmt->fetch();
    if (!$found) { throw new OutOfBoundsException('Document not found.'); }
    $pdo->beginTransaction();
    // Shared trip lock blocks removal, role/visibility changes and deletion until
    // this authorized fetch finishes. No public or bearer download URL is issued.
    $stmt = $pdo->prepare('SELECT user_id FROM trips WHERE id = :id LOCK IN SHARE MODE');
    $stmt->execute(['id' => $found['trip_id']]);
    $owner = $stmt->fetchColumn();
    $me = (int) current_user_id();
    $stmt = $pdo->prepare('SELECT role FROM trip_memberships WHERE trip_id = :trip AND user_id = :user LOCK IN SHARE MODE');
    $stmt->execute(['trip' => $found['trip_id'], 'user' => $me]);
    $role = $owner !== false && (int) $owner === $me ? 'owner' : $stmt->fetchColumn();
    if (!trip_role_can(is_string($role) ? $role : null, 'view')) { throw new OutOfBoundsException('Document not found.'); }
    require_document_reference($pdo, (int) $found['trip_id'], (int) $found['reference_id'], $role);
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LOCK IN SHARE MODE');
    $stmt->execute(['id' => $me]);
    if ($stmt->fetchColumn() !== 'customer') { throw new OutOfBoundsException('Document not found.'); }
    $stmt = $pdo->prepare('SELECT object_key, original_name, mime_type, byte_size, file_hash FROM trip_documents
        WHERE id = :id AND reference_id = :ref AND state = \'ready\' AND uploaded_by IS NOT NULL LOCK IN SHARE MODE');
    $stmt->execute(['id' => $id, 'ref' => $found['reference_id']]);
    $doc = $stmt->fetch();
    if (!$doc) { throw new OutOfBoundsException('Document not found.'); }
    $bytes = private_storage_download($doc['object_key']);
    if (strlen($bytes) !== (int) $doc['byte_size'] || !hash_equals($doc['file_hash'], hash('sha256', $bytes))) {
        throw new RuntimeException('Document integrity check failed.');
    }
    $pdo->commit();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="document.' . TRIP_DOCUMENT_TYPES[$doc['mime_type']][0]
        . '"; filename*=UTF-8\'\'' . rawurlencode($doc['original_name']));
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    if ($e instanceof OutOfBoundsException) { http_response_code(404); echo 'Document not found.'; }
    else {
        error_log('TravelEase: private document download failed (' . get_class($e) . ').');
        http_response_code(503); echo 'Document storage is temporarily unavailable. Please try again.';
    }
}
