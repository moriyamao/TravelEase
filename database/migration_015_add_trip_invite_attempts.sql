-- Run once after migration_014.
-- Records every invitation email lookup so the limit counts lookups of
-- unknown addresses too (not only invitations actually created).
-- Rows older than a day are deleted by customer/trips/invite.php.
CREATE TABLE trip_invite_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_trip_invite_attempts_user_time (user_id, created_at),
    CONSTRAINT fk_trip_invite_attempts_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
