-- Migration 003: Add trips table (Milestone 3)
-- Run this against your EXISTING travelease database.
--
-- Usage (phpMyAdmin): open the SQL tab on the `travelease` database,
-- paste this file's contents, click Go.

USE travelease;

CREATE TABLE IF NOT EXISTS trips (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED        NOT NULL,
    name            VARCHAR(150)        NOT NULL,
    destination     VARCHAR(150)        NOT NULL,
    start_date      DATE                NOT NULL,
    end_date        DATE                NOT NULL,
    status          ENUM('planning', 'confirmed', 'completed', 'cancelled')
                                         NOT NULL DEFAULT 'planning',
    notes           TEXT                NULL,
    created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_trips_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_trips_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ARCHITECTURE NOTES
--
-- user_id + ON DELETE CASCADE: if a user account is ever deleted,
-- their trips go with it rather than becoming orphaned rows pointing
-- at a nonexistent user. There is no account-deletion feature yet,
-- but this makes the constraint correct from the start rather than
-- needing a later migration.
--
-- idx_trips_user_id: every trip query in the app filters by
-- user_id (ownership/IDOR check on every access), so this index is
-- not optional -- it's the difference between a fast query and a
-- full table scan on every dashboard load as trips grow.
--
-- destination is a plain VARCHAR for now, not a foreign key to a
-- Destinations table. A full Destinations entity (used by later
-- itinerary features) is a future milestone -- adding it now would
-- be building ahead of an actual need (see project instructions §28).
--
-- status intentionally excludes 'draft' or other extra states not
-- currently used by any UI -- states are added when a workflow
-- actually needs them, not speculatively.
