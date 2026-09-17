-- Migration 007: Escalations queue
--
-- Previously, an AI chat escalation only sent an email (EmailJS,
-- client-side) with no record inside the app itself -- staff had no
-- way to see open support requests except checking Mori's inbox.
-- This table makes escalations a real, queryable, in-app queue.
--
-- Deliberately minimal: no assignment, no priority, no category --
-- just "is this open or resolved." Add fields later only if an actual
-- need shows up; don't build ahead of it.

CREATE TABLE IF NOT EXISTS escalations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED  NOT NULL,
    message     TEXT          NOT NULL,
    status      ENUM('open', 'resolved') NOT NULL DEFAULT 'open',
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP     NULL,

    CONSTRAINT fk_escalations_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_escalations_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;