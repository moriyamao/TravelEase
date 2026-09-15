-- TravelEase Database Schema
-- Milestone 1: Users & Authentication only.
-- Additional tables (trips, destinations, budgets, booking_requests, etc.)
-- will be added in later milestones as those features are implemented.

CREATE DATABASE IF NOT EXISTS travelease
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE travelease;

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100)        NOT NULL,
    email           VARCHAR(255)        NOT NULL,
    password_hash   VARCHAR(255)        NULL,
    google_subject  VARCHAR(255)        NULL,
    role            ENUM('customer', 'staff', 'admin') NOT NULL DEFAULT 'customer',
    created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_google_subject (google_subject)
) ENGINE=InnoDB;

-- ARCHITECTURE NOTES
--
-- password_hash is NULLable: email/password accounts always have a
-- hash (enforced in application code); Google-only accounts do not.
--
-- google_subject is NULLable and uniquely constrained: it stores the
-- verified Google 'sub' claim for accounts created/linked via Google
-- Sign-In (Milestone 2). It is never populated from unverified client
-- input -- only from a server-side-verified ID token.
--
-- NOTE FOR EXISTING DATABASES: if you already ran an earlier version
-- of this file (before google_subject existed), do NOT re-run this
-- CREATE TABLE script -- run database/migration_002_add_google_subject.sql
-- against your existing database instead.

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

-- See database/migration_003_add_trips.sql for the reasoning behind
-- the trips table's design decisions (cascade delete, index, why
-- destination isn't a foreign key yet, etc.)
-- If you already had a database from before trips existed, run that
-- migration file instead of re-running this whole schema.
