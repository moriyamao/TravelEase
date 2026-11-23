-- Security hardening: database-backed rate limits for sign-in, registration
-- and the support chat (replaces per-session counters that reset when the
-- cookie was dropped). New table only; no existing data is touched.
-- limit_key = sha256(bucket|subject); raw emails and IP addresses are never stored.
CREATE TABLE rate_limits (
    limit_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    window_started DATETIME NOT NULL,
    attempts INT UNSIGNED NOT NULL,
    INDEX idx_rate_limits_window (window_started)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
