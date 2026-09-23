-- Migration 007: Escalations queue

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