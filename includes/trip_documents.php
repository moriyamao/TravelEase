<?php
require_once __DIR__ . '/trip_references.php';
require_once __DIR__ . '/private_storage.php';

const TRIP_DOCUMENT_MAX_BYTES = 2097152;
const TRIP_DOCUMENT_TYPES = ['application/pdf' => ['pdf'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png']];

/** Uploaded-file origin is checked by the endpoint, before reading bytes here. */
function validate_trip_document(string $path, string $name): array
{
    if (!mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 150 || $name === ''
        || preg_match('/[\\\\\/\x00-\x1f\x7f]/', $name)) {
        throw new InvalidArgumentException('Use a filename of up to 150 characters without paths or control characters.');
    }
    $size = filesize($path);
    if ($size === false || $size < 1 || $size > TRIP_DOCUMENT_MAX_BYTES) {
        throw new InvalidArgumentException('Choose a non-empty PDF, JPEG or PNG up to 2 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(TRIP_DOCUMENT_TYPES[$mime]) || !in_array($ext, TRIP_DOCUMENT_TYPES[$mime], true)) {
        throw new InvalidArgumentException('The actual file type must match its PDF, JPEG or PNG extension.');
    }
    $bytes = file_get_contents($path);
    if ($bytes === false) { throw new RuntimeException('Could not read the uploaded file.'); }
    if ($mime === 'application/pdf' && !str_starts_with($bytes, '%PDF-')) {
        throw new InvalidArgumentException('Choose a valid PDF.');
    }
    if (str_starts_with($mime, 'image/')) {
        $image = @getimagesize($path);
        if (!$image || ($image['mime'] ?? '') !== $mime || $image[0] * $image[1] > 40000000) {
            throw new InvalidArgumentException('Choose a valid image of at most 40 megapixels.');
        }
    }
    return ['name' => $name, 'mime' => $mime, 'size' => $size, 'hash' => hash('sha256', $bytes), 'bytes' => $bytes];
}

/** Caller holds the trip lock. An attachment inherits its reference visibility. */
function require_document_reference(PDO $pdo, int $tripId, int $referenceId, string $role): array
{
    $stmt = $pdo->prepare('SELECT * FROM trip_references WHERE id = :id AND trip_id = :trip LOCK IN SHARE MODE');
    $stmt->execute(['id' => $referenceId, 'trip' => $tripId]);
    $ref = $stmt->fetch();
    if (!$ref || !trip_reference_visible($ref, $role, (int) current_user_id())) {
        throw new OutOfBoundsException('Document not found.');
    }
    return $ref;
}

/** Called only for reference IDs already filtered through membership/visibility. */
function list_reference_documents(PDO $pdo, array $references): array
{
    if (!$references) { return []; }
    $ids = array_map(static fn($r) => (int) $r['id'], $references);
    $stmt = $pdo->prepare('SELECT id, reference_id, original_name, mime_type, byte_size, created_at
        FROM trip_documents WHERE reference_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
        AND state = \'ready\' AND uploaded_by IS NOT NULL ORDER BY id');
    $stmt->execute($ids);
    $result = [];
    foreach ($stmt->fetchAll() as $doc) { $result[$doc['reference_id']][] = $doc; }
    return $result;
}

/** Bounded scheduled cleanup; no active ready document is ever removed. */
function cleanup_trip_documents(PDO $pdo): array
{
    $result = ['deleted' => 0, 'failed' => 0];
    if ((int) $pdo->query('SELECT GET_LOCK(\'travelease_document_cleanup\', 0)')->fetchColumn() !== 1) {
        return $result + ['busy' => true];
    }
    try {
        private_storage_config();
        $pdo->exec('DELETE FROM trip_document_upload_attempts WHERE created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 100');
        $rows = $pdo->query('SELECT id, object_key FROM trip_documents WHERE state = \'deleting\'
            OR reference_id IS NULL OR uploaded_by IS NULL
            OR (state = \'pending\' AND created_at < UTC_TIMESTAMP() - INTERVAL 1 HOUR)
            ORDER BY updated_at, id LIMIT 3')->fetchAll();
        foreach ($rows as $row) {
            try {
                // Atomically retire only a still-eligible row. An upload which
                // became ready since the candidate query must not be deleted.
                $stmt = $pdo->prepare('UPDATE trip_documents SET state = \'deleting\', updated_at = UTC_TIMESTAMP()
                    WHERE id = :id AND (state = \'deleting\' OR reference_id IS NULL OR uploaded_by IS NULL
                    OR (state = \'pending\' AND created_at < UTC_TIMESTAMP() - INTERVAL 1 HOUR))');
                $stmt->execute(['id' => $row['id']]);
                if ($stmt->rowCount() === 0) {
                    $check = $pdo->prepare('SELECT state FROM trip_documents WHERE id = :id');
                    $check->execute(['id' => $row['id']]);
                    if ($check->fetchColumn() !== 'deleting') { continue; }
                }
                private_storage_delete($row['object_key']);
                $stmt = $pdo->prepare('DELETE FROM trip_documents WHERE id = :id AND state = \'deleting\'');
                $stmt->execute(['id' => $row['id']]);
                $result['deleted']++;
            } catch (RuntimeException $e) {
                $stmt = $pdo->prepare('UPDATE trip_documents SET updated_at = UTC_TIMESTAMP() WHERE id = :id');
                $stmt->execute(['id' => $row['id']]);
                $result['failed']++;
            }
        }
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(\'travelease_document_cleanup\')');
    }
    return $result;
}
