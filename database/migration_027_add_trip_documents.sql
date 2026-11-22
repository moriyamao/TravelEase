-- FR-24/NFR-06: private files. Detached rows retain object keys for cleanup
-- after reference/trip/account deletion; never cascade away the cleanup record.
CREATE TABLE trip_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference_id INT UNSIGNED NULL,
    uploaded_by INT UNSIGNED NULL,
    object_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_name VARCHAR(150) NOT NULL,
    mime_type VARCHAR(32) NOT NULL,
    byte_size INT UNSIGNED NOT NULL CHECK (byte_size BETWEEN 1 AND 2097152),
    file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('pending','ready','deleting') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_trip_document_object (object_key),
    INDEX idx_trip_document_reference (reference_id, state),
    INDEX idx_trip_document_cleanup (state, updated_at),
    CONSTRAINT fk_trip_document_reference FOREIGN KEY (reference_id) REFERENCES trip_references(id) ON DELETE SET NULL,
    CONSTRAINT fk_trip_document_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_document_upload_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_document_attempt_user (user_id, created_at),
    INDEX idx_document_attempt_cleanup (created_at),
    CONSTRAINT fk_document_attempt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
