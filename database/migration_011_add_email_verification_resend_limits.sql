-- Two migration_011 files create the same table (only the FK name differs).
-- Run at most ONE, and only if email_verification_resend_limits does not exist.
-- schema.sql uses fk_email_verification_resend_limit_user (the *_email_verification_resend_limits.sql file).
CREATE TABLE email_verification_resend_limits (
    user_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    last_sent_at TIMESTAMP NULL,
    PRIMARY KEY (user_id, ip_address),
    CONSTRAINT fk_email_verification_resend_limit_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
