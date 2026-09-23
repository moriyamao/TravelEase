-- Migration 002: Add Google Sign-In support

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
