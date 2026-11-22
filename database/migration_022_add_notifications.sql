-- FR-30 in-app notifications and FR-04 notification preferences.
-- No email delivery or scheduled reminders are introduced here.
ALTER TABLE users ADD COLUMN notify_in_app TINYINT UNSIGNED NOT NULL DEFAULT 1
    CHECK (notify_in_app IN (0, 1));

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    trip_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    activity_log_id BIGINT UNSIGNED NULL,
    invitation_id INT UNSIGNED NULL,
    invitation_issue_no INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    dismissed_at DATETIME NULL,
    UNIQUE KEY uq_notifications_activity (user_id, activity_log_id),
    UNIQUE KEY uq_notifications_invitation (user_id, invitation_id, invitation_issue_no),
    INDEX idx_notifications_inbox (user_id, dismissed_at, read_at, id),
    INDEX idx_notifications_trip (trip_id),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_activity FOREIGN KEY (activity_log_id) REFERENCES trip_activity_log(id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_invitation FOREIGN KEY (invitation_id) REFERENCES trip_invitations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
