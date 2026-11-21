-- FR-24 booking-reference metadata. Attachments require private object storage
-- and are not part of this migration. Private references follow the canonical
-- owner, not the historical owner membership row.
CREATE TABLE trip_references (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    title VARCHAR(150) NOT NULL,
    kind ENUM('booking','transport','lodging','activity','other') NOT NULL,
    provider VARCHAR(100) NULL,
    confirmation_code VARCHAR(150) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    reference_url VARCHAR(2048) NULL,
    status ENUM('pending','confirmed') NOT NULL DEFAULT 'pending',
    visibility ENUM('members','editors','private') NOT NULL DEFAULT 'private',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_trip_references_trip (trip_id, id),
    INDEX idx_trip_references_creator (created_by),
    CONSTRAINT fk_trip_references_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
    CONSTRAINT fk_trip_references_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
