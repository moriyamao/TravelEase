-- Migration 024 (FR-32): trips a customer chooses to share with support.
-- Apply after migrations 007-009 (escalations). Like the other support
-- tables, this is not part of schema.sql: fresh installs run it after 007-009.
-- Staff can open a shared trip only while the share is active, the support
-- request is open and the person who shared it still owns the trip.
CREATE TABLE IF NOT EXISTS support_trip_shares (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    escalation_id INT UNSIGNED NOT NULL,
    trip_id       INT UNSIGNED NOT NULL,
    shared_by     INT UNSIGNED NOT NULL,
    shared_at     DATETIME     NOT NULL,
    revoked_at    DATETIME     NULL,
    UNIQUE KEY uq_support_trip_shares (escalation_id, trip_id),
    INDEX idx_support_trip_shares_trip (trip_id),
    INDEX idx_support_trip_shares_user (shared_by),
    CONSTRAINT fk_support_shares_escalation FOREIGN KEY (escalation_id) REFERENCES escalations(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_shares_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_shares_user FOREIGN KEY (shared_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
