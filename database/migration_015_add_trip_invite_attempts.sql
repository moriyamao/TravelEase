-- Run once after migration_014.
-- 1. Records every invitation email lookup so the limit counts lookups of
--    unknown addresses too (not only invitations actually created).
--    customer/trips/invite.php prunes each account's rows older than a day.
CREATE TABLE trip_invite_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_trip_invite_attempts_user_time (user_id, created_at),
    INDEX idx_trip_invite_attempts_time (created_at),
    CONSTRAINT fk_trip_invite_attempts_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Issue number per invitation. Reopening an invitation increments it,
--    and accept/decline/revoke must match it, so a form for an earlier
--    issue cannot act on a reissued invitation.
ALTER TABLE trip_invitations
    ADD COLUMN issue_no INT UNSIGNED NOT NULL DEFAULT 1 AFTER status;
