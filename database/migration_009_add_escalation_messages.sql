-- Migration 009: Escalation message threads

-- Upgrades escalations from a single customer message + single staff
-- reply into a real back-and-forth thread. escalations.staff_reply /
-- replied_at are left in place (not dropped) for safety, but the app
-- stops writing to them after this point -- all messages now live in
-- escalation_messages instead.

-- sender_id is nullable because old staff_reply rows never recorded
-- which staff member replied -- that data simply doesn't exist for
-- anything created before this migration.

CREATE TABLE IF NOT EXISTS escalation_messages (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    escalation_id  INT UNSIGNED NOT NULL,
    sender_type    ENUM('customer', 'staff') NOT NULL,
    sender_id      INT UNSIGNED NULL,
    message        TEXT NOT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_escalation_messages_escalation
        FOREIGN KEY (escalation_id) REFERENCES escalations(id)
        ON DELETE CASCADE,

    INDEX idx_escalation_messages_escalation (escalation_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill existing escalations into the new thread table, so nothing
-- already in the queue looks empty once the UI switches over.
INSERT INTO escalation_messages (escalation_id, sender_type, sender_id, message, created_at)
SELECT id, 'customer', user_id, message, created_at FROM escalations;

INSERT INTO escalation_messages (escalation_id, sender_type, sender_id, message, created_at)
SELECT id, 'staff', NULL, staff_reply, replied_at
FROM escalations
WHERE staff_reply IS NOT NULL;