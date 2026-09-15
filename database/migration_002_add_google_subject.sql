-- Migration 002: Add Google Sign-In support
-- Run this against your EXISTING travelease database (the one created
-- by schema.sql). This does not touch or delete any existing rows.
--
-- Usage (phpMyAdmin): open the SQL tab on the `travelease` database,
-- paste this file's contents, click Go.

USE travelease;

ALTER TABLE users
  ADD COLUMN google_subject VARCHAR(255) NULL AFTER password_hash,
  ADD UNIQUE KEY uq_users_google_subject (google_subject);

-- After this migration:
--   - Existing email/password accounts are unaffected (google_subject
--     stays NULL for them).
--   - A Google-only account will have password_hash = NULL and
--     google_subject = the verified Google 'sub' claim.
--   - password_hash was already made nullable in schema.sql back in
--     Milestone 1 specifically to allow this.
