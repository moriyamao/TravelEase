-- The canonical migration 011 (the Aiven runner and schema.sql use this file and
-- its fk_email_verification_resend_limit_user name). A duplicate copy with a
-- different FK name was removed; run this only if the table does not exist yet.
CREATE TABLE email_verification_resend_limits (
    user_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    last_sent_at TIMESTAMP NULL,
    PRIMARY KEY (user_id, ip_address),
    CONSTRAINT fk_email_verification_resend_limit_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
