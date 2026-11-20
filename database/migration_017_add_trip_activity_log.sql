-- Run once after migration_016.
-- FR-18: trip activity history. One row per significant collaboration
-- event, written in the same transaction as the change it describes, so
-- the history never shows an action that did not happen.
-- Adds one new table only. No existing table changes.
--
-- actor_id / target_user_id are SET NULL when that account is deleted,
-- so the trip's history survives (shown as "Deleted user"). Deleting the
-- trip deletes its history. details holds a short snapshot (a role, or
-- an activity title at the time of the event), never an email address.
CREATE TABLE trip_activity_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id INT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED NULL,
    event_type VARCHAR(40) NOT NULL,
    target_user_id INT UNSIGNED NULL,
    details VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_trip_activity_log_trip (trip_id, created_at, id),
    INDEX idx_trip_activity_log_actor (actor_id),
    INDEX idx_trip_activity_log_target (target_user_id),
    CONSTRAINT fk_trip_activity_log_trip
        FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
    CONSTRAINT fk_trip_activity_log_actor
        FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_trip_activity_log_target
        FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
