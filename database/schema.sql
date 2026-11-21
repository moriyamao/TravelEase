-- TravelEase Database Schema -- FRESH INSTALLS ONLY.
-- Never run this over an existing database; apply only the missing
-- database/migration_*.sql files instead. This file covers users,
-- email verification, trips, trip memberships/invitations and
-- itinerary items; the support tables come from migrations 007-009.

CREATE DATABASE IF NOT EXISTS travelease
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE travelease;

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100)        NOT NULL,
    email           VARCHAR(255)        NOT NULL,
    email_verified_at TIMESTAMP        NULL,
    password_hash   VARCHAR(255)        NULL,
    google_subject  VARCHAR(255)        NULL,
    role            ENUM('customer', 'staff', 'admin') NOT NULL DEFAULT 'customer',
    profile_picture VARCHAR(255)        NULL,
    created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_google_subject (google_subject)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_verification_token_hash (token_hash),
    INDEX idx_email_verification_user (user_id),
    CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verification_resend_limits (
    user_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    last_sent_at TIMESTAMP NULL,
    PRIMARY KEY (user_id, ip_address),
    CONSTRAINT fk_email_verification_resend_limit_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ARCHITECTURE NOTES
--
-- password_hash is NULLable: email/password accounts always have a
-- hash (enforced in application code); Google-only accounts do not
--
-- google_subject is NULLable and uniquely constrained: it stores the
-- verified Google 'sub' claim for accounts created/linked via Google
-- Sign-In (Milestone 2). It is never populated from unverified client
-- input -- only from a server-side-verified ID token
--
-- NOTE FOR EXISTING DATABASES: if you already ran an earlier version
-- of this file (before google_subject existed), do NOT re-run this
-- CREATE TABLE script -- run database/migration_002_add_google_subject.sql
-- against your existing database instead   

CREATE TABLE IF NOT EXISTS trips (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED        NOT NULL,
    name            VARCHAR(150)        NOT NULL,
    destination     VARCHAR(150)        NOT NULL,
    start_date      DATE                NOT NULL,
    end_date        DATE                NOT NULL,
    status          ENUM('planning', 'confirmed', 'completed', 'cancelled')
                                         NOT NULL DEFAULT 'planning',
    budget_currency CHAR(3)             NOT NULL DEFAULT 'PHP',
    budget_amount   DECIMAL(10,2)       NULL,
    notes           TEXT                NULL,
    created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_trips_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_trips_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- See database/migration_003_add_trips.sql for the reasoning behind
-- the trips table's design decisions (cascade delete, index, why
-- destination isn't a foreign key yet, etc.), and
-- database/migration_006_add_budget_fields.sql for budget_currency
-- and budget_amount
-- If you already had a database from before trips existed, run that
-- migration file instead of re-running this whole schema

CREATE TABLE IF NOT EXISTS trip_memberships (
    trip_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('owner', 'editor', 'commenter', 'viewer') NOT NULL,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (trip_id, user_id),
    INDEX idx_trip_memberships_user (user_id),
    CONSTRAINT fk_trip_memberships_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
    CONSTRAINT fk_trip_memberships_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_invitations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id INT UNSIGNED NOT NULL,
    invitee_id INT UNSIGNED NOT NULL,
    role ENUM('editor', 'commenter', 'viewer') NOT NULL,
    status ENUM('pending', 'accepted', 'declined', 'expired', 'revoked') NOT NULL DEFAULT 'pending',
    issue_no INT UNSIGNED NOT NULL DEFAULT 1,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP NULL,
    UNIQUE KEY uq_trip_invitation_recipient (trip_id, invitee_id),
    INDEX idx_trip_invitations_recipient (invitee_id, status),
    CONSTRAINT fk_trip_invitations_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
    CONSTRAINT fk_trip_invitations_recipient FOREIGN KEY (invitee_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_invite_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_trip_invite_attempts_user_time (user_id, created_at),
    INDEX idx_trip_invite_attempts_time (created_at),
    CONSTRAINT fk_trip_invite_attempts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_places (
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

CREATE TABLE IF NOT EXISTS itinerary_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id         INT UNSIGNED        NOT NULL,
    item_date       DATE                NOT NULL,
    item_time       TIME                NULL,
    end_time        TIME                NULL,
    title           VARCHAR(150)        NOT NULL,
    location        VARCHAR(150)        NULL,
    place_id        INT UNSIGNED        NULL,
    category        ENUM('sightseeing', 'food', 'transport', 'lodging', 'activity', 'shopping', 'other') NULL,
    assignee_id     INT UNSIGNED        NULL,
    sort_order      INT UNSIGNED        NOT NULL DEFAULT 0,
    notes           TEXT                NULL,
    estimated_cost  DECIMAL(10,2)       NULL,
    actual_cost     DECIMAL(10,2)       NULL,
    completed_at    TIMESTAMP           NULL,
    completed_by    INT UNSIGNED        NULL,
    created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_itinerary_trip
        FOREIGN KEY (trip_id) REFERENCES trips(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_itinerary_place
        FOREIGN KEY (place_id) REFERENCES trip_places(id) ON DELETE SET NULL,

    CONSTRAINT fk_itinerary_assignee
        FOREIGN KEY (assignee_id) REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_itinerary_completed_by
        FOREIGN KEY (completed_by) REFERENCES users(id)
        ON DELETE SET NULL,

    INDEX idx_itinerary_place (place_id),
    INDEX idx_itinerary_trip_id (trip_id),
    INDEX idx_itinerary_trip_date (trip_id, item_date),
    INDEX idx_itinerary_trip_date_order (trip_id, item_date, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- See database/migration_005_add_itinerary_items.sql for the
-- reasoning behind the itinerary_items design (two-level ownership
-- check, no separate days table, etc.), and
-- database/migration_006_add_budget_fields.sql for estimated_cost /
-- actual_cost (both all-in amounts, tax included, no separate tax
-- column -- see that migration's notes), and
-- database/migration_018_add_itinerary_activity_fields.sql for end_time,
-- category, assignee_id, sort_order, completed_at and completed_by (FR-19)

-- FR-17 activity slice: votes and comments on itinerary activities.
-- See database/migration_016_add_itinerary_feedback.sql for the notes.
CREATE TABLE IF NOT EXISTS itinerary_item_votes (
    itinerary_item_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    vote TINYINT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                         ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (itinerary_item_id, user_id),
    INDEX idx_itinerary_item_votes_user (user_id),
    CONSTRAINT chk_itinerary_item_votes_vote CHECK (vote IN (-1, 1)),
    CONSTRAINT fk_itinerary_item_votes_item
        FOREIGN KEY (itinerary_item_id) REFERENCES itinerary_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_itinerary_item_votes_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itinerary_item_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    itinerary_item_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body VARCHAR(1000) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_itinerary_item_comments_item (itinerary_item_id, created_at),
    INDEX idx_itinerary_item_comments_user (user_id),
    CONSTRAINT fk_itinerary_item_comments_item
        FOREIGN KEY (itinerary_item_id) REFERENCES itinerary_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_itinerary_item_comments_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FR-18: trip activity history.
-- See database/migration_017_add_trip_activity_log.sql for the notes.
CREATE TABLE IF NOT EXISTS trip_activity_log (
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


-- Account-wide lookup quota (including cache hits), independent of sessions.
CREATE TABLE IF NOT EXISTS place_lookup_limits (
    user_id INT UNSIGNED PRIMARY KEY,
    window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_place_lookup_limits_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Public provider results only. The reserved 'provider' key stores the next
-- allowed provider-request time; all other keys are SHA-256 query hashes.
CREATE TABLE IF NOT EXISTS place_lookup_cache (
    cache_key VARCHAR(64) PRIMARY KEY,
    payload MEDIUMTEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_place_lookup_cache_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FR-22/23. Amounts use the app's two-decimal convention.
-- Payer and traveler identities are snapshots retained with the trip.
CREATE TABLE IF NOT EXISTS trip_expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    category ENUM('sightseeing','food','transport','lodging','activity','shopping','other') NOT NULL,
    currency CHAR(3) NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL CHECK (amount_minor BETWEEN 1 AND 9999999999),
    payer_id INT UNSIGNED NOT NULL,
    payer_name VARCHAR(100) NOT NULL,
    split_mode ENUM('equal','custom') NOT NULL,
    receipt_url VARCHAR(2048) NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_trip_expenses_trip (trip_id, id),
    CONSTRAINT fk_trip_expenses_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_expense_shares (
    expense_id INT UNSIGNED NOT NULL,
    traveler_id INT UNSIGNED NOT NULL,
    traveler_name VARCHAR(100) NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL CHECK (amount_minor BETWEEN 1 AND 9999999999),
    settled_at DATETIME NULL,
    PRIMARY KEY (expense_id, traveler_id),
    CONSTRAINT fk_expense_shares_expense FOREIGN KEY (expense_id) REFERENCES trip_expenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
