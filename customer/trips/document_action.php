<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_activity.php';
require_once __DIR__ . '/../../includes/trip_documents.php';
require_role(['customer']);
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST'); http_response_code(405); exit('Method not allowed.');
}
$csrf = $_POST['csrf_token'] ?? null;
if (!is_string($csrf) || !csrf_verify($csrf)) { http_response_code(403); exit('Reload the page and try again.'); }
$tripId = filter_var($_POST['trip_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$referenceId = filter_var($_POST['reference_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$version = filter_var($_POST['version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = $_POST['action'] ?? null;
if (!$tripId || !$referenceId || !$version || !in_array($action, ['upload', 'delete'], true)) {
    http_response_code(400); exit('Invalid document request.');
}
$pdo = get_db_connection();
require_trip_permission($pdo, $tripId, 'edit_itinerary');
$me = (int) current_user_id();
$pendingId = null;
try {
    if ($action === 'upload') {
        private_storage_config();
        $file = $_FILES['document'] ?? null;
        if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK
            || !is_string($file['tmp_name'] ?? null) || !is_string($file['name'] ?? null)
            || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException('Choose a PDF, JPEG or PNG up to 2 MB.');
        }
        $upload = validate_trip_document($file['tmp_name'], $file['name']);
    }
    $pdo->beginTransaction();
    $role = lock_trip_and_require_permission($pdo, $tripId, 'edit_itinerary');
    $ref = require_document_reference($pdo, $tripId, $referenceId, $role);
    if ((int) $ref['version'] !== $version) { throw new DomainException('This reference changed. Reload and try again.'); }
    // The account row serializes account-wide reservations across different trips.
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $me]);
    if ($stmt->fetchColumn() !== 'customer') { throw new OutOfBoundsException('Document not found.'); }
    if ($action === 'delete') {
        $id = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) { throw new InvalidArgumentException('Choose a valid attachment.'); }
        $stmt = $pdo->prepare('UPDATE trip_documents SET state = \'deleting\' WHERE id = :id
            AND reference_id = :ref AND state = \'ready\' AND uploaded_by IS NOT NULL');
        $stmt->execute(['id' => $id, 'ref' => $referenceId]);
        if ($stmt->rowCount() !== 1) { throw new OutOfBoundsException('Document not found.'); }
        log_trip_activity($pdo, $tripId, 'document_deleted');
        $pdo->commit();
        $_SESSION['reference_notice'][$tripId] = 'Attachment access removed. Its stored file is queued for deletion.';
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM trip_documents WHERE reference_id = :ref AND state <> \'deleting\'');
        $stmt->execute(['ref' => $referenceId]);
        if ((int) $stmt->fetchColumn() >= 3) { throw new InvalidArgumentException('Each reference can have up to 3 attachments.'); }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM trip_documents d JOIN trip_references r ON r.id = d.reference_id
            WHERE r.trip_id = :trip AND d.state <> \'deleting\'');
        $stmt->execute(['trip' => $tripId]);
        if ((int) $stmt->fetchColumn() >= 20) { throw new InvalidArgumentException('A trip can have up to 20 attachments.'); }
        $stmt = $pdo->prepare('DELETE FROM trip_document_upload_attempts WHERE user_id = :user AND created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
        $stmt->execute(['user' => $me]);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM trip_document_upload_attempts WHERE user_id = :user AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR');
        $stmt->execute(['user' => $me]);
        if ((int) $stmt->fetchColumn() >= 30) {
            $pdo->rollBack(); header('Retry-After: 3600'); http_response_code(429); exit('Up to 30 upload attempts per hour. Try again later.');
        }
        $stmt = $pdo->prepare('INSERT INTO trip_document_upload_attempts (user_id) VALUES (:user)');
        $stmt->execute(['user' => $me]);
        $objectKey = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare('INSERT INTO trip_documents (reference_id, uploaded_by, object_key, original_name, mime_type, byte_size, file_hash)
            VALUES (:ref, :user, :key, :name, :mime, :size, :hash)');
        $stmt->execute(['ref' => $referenceId, 'user' => $me, 'key' => $objectKey,
            'name' => $upload['name'], 'mime' => $upload['mime'], 'size' => $upload['size'], 'hash' => $upload['hash']]);
        $pendingId = (int) $pdo->lastInsertId();
        $pdo->commit();
        // The cleanup record is durable BEFORE the external write. A timeout or
        // lost PHP process cannot lose the key of an uncertain upload.
        private_storage_upload($objectKey, $upload['bytes'], $upload['mime']);
        $pdo->beginTransaction();
        $role = lock_trip_and_require_permission($pdo, $tripId, 'edit_itinerary');
        $ref = require_document_reference($pdo, $tripId, $referenceId, $role);
        $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LOCK IN SHARE MODE');
        $stmt->execute(['id' => $me]);
        if ($stmt->fetchColumn() !== 'customer' || (int) $ref['version'] !== $version) {
            throw new DomainException('Your access or the reference changed during upload. Reload and try again.');
        }
        $stmt = $pdo->prepare('UPDATE trip_documents SET state = \'ready\' WHERE id = :id
            AND state = \'pending\' AND reference_id = :ref AND uploaded_by = :user');
        $stmt->execute(['id' => $pendingId, 'ref' => $referenceId, 'user' => $me]);
        if ($stmt->rowCount() !== 1) { throw new DomainException('This upload expired. Please try again.'); }
        log_trip_activity($pdo, $tripId, 'document_added');
        $pdo->commit();
        $pendingId = null;
        $_SESSION['reference_notice'][$tripId] = 'Attachment uploaded.';
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    if ($pendingId !== null) {
        try {
            $stmt = $pdo->prepare('UPDATE trip_documents SET state = \'deleting\' WHERE id = :id AND state = \'pending\'');
            $stmt->execute(['id' => $pendingId]);
        } catch (PDOException $ignored) { /* An old pending row is also cleanup-eligible. */ }
    }
    if ($e instanceof OutOfBoundsException || $e instanceof DomainException) {
        http_response_code($e instanceof OutOfBoundsException ? 404 : 409); exit($e->getMessage());
    }
    if ($e instanceof InvalidArgumentException) {
        $_SESSION['reference_error'][$tripId] = $e->getMessage();
    } else {
        error_log('TravelEase: private document operation failed (' . get_class($e) . ').');
        $_SESSION['reference_error'][$tripId] = 'The attachment could not be saved. Storage may be unavailable. Please try again.';
    }
}
header('Location: /customer/trips/references.php?id=' . $tripId);
exit;
