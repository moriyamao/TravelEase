-- FR-21 + FR-27. Run once after migration_018, before deploying maps.
-- Existing activities keep their values and timestamps; place_id starts NULL.
CREATE TABLE trip_places (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    address VARCHAR(255) NULL,
    latitude DECIMAL(9,6) NULL,
    longitude DECIMAL(9,6) NULL,
    category ENUM('sightseeing','food','transport','lodging','activity','shopping','other') NULL,
    source ENUM('manual','geoapify') NOT NULL DEFAULT 'manual',
    provider_place_id VARCHAR(2048) NULL,
    created_by INT UNSIGNED NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_trip_places_trip_name (trip_id, name),
    CONSTRAINT fk_trip_places_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
    CONSTRAINT fk_trip_places_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_trip_places_coordinates CHECK (
        (latitude IS NULL AND longitude IS NULL) OR
        (latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)
    ),
    CONSTRAINT chk_trip_places_version CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE itinerary_items
    ADD COLUMN place_id INT UNSIGNED NULL AFTER location,
    ADD INDEX idx_itinerary_place (place_id),
    ADD CONSTRAINT fk_itinerary_place FOREIGN KEY (place_id) REFERENCES trip_places(id) ON DELETE SET NULL;

-- Account-wide lookup quota (including cache hits), independent of sessions.
CREATE TABLE place_lookup_limits (
    user_id INT UNSIGNED PRIMARY KEY,
    window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_place_lookup_limits_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Public provider results only. The reserved 'provider' key stores the next
-- allowed provider-request time; all other keys are SHA-256 query hashes.
CREATE TABLE place_lookup_cache (
    cache_key VARCHAR(64) PRIMARY KEY,
    payload MEDIUMTEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_place_lookup_cache_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
