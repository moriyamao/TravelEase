ALTER TABLE trips ADD COLUMN time_zone VARCHAR(64) NOT NULL DEFAULT 'Asia/Manila';
ALTER TABLE users ADD COLUMN notify_email TINYINT UNSIGNED NOT NULL DEFAULT 0 CHECK (notify_email IN (0, 1));

CREATE TABLE trip_reminders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    itinerary_item_id INT UNSIGNED NULL,
    title VARCHAR(150) NOT NULL,
    due_at DATETIME NOT NULL,
    activity_start_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    acknowledged_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    in_app_processed_at DATETIME NULL,
    in_app_delivered_at DATETIME NULL,
    email_status ENUM('pending','unknown','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    email_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    email_attempted_at DATETIME NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_reminders_activity_user (itinerary_item_id, user_id),
    INDEX idx_reminders_trip_user (trip_id, user_id, due_at),
    INDEX idx_reminders_due (cancelled_at, acknowledged_at, due_at),
    CONSTRAINT fk_reminders_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
    CONSTRAINT fk_reminders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_reminders_activity FOREIGN KEY (itinerary_item_id) REFERENCES itinerary_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE notifications
    ADD COLUMN reminder_id BIGINT UNSIGNED NULL,
    ADD UNIQUE KEY uq_notifications_reminder (user_id, reminder_id),
    ADD CONSTRAINT fk_notifications_reminder FOREIGN KEY (reminder_id) REFERENCES trip_reminders(id) ON DELETE CASCADE;
