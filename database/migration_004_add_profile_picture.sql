-- Migration 004: Add profile_picture column (Milestone 4)
-- Run this against your EXISTING travelease database.
--
-- Usage (phpMyAdmin): open the SQL tab on the `travelease` database,
-- paste this file's contents, click Go.

USE travelease;

ALTER TABLE users
  ADD COLUMN profile_picture VARCHAR(255) NULL AFTER role;

-- Stores a relative path like /uploads/profile_pictures/user_3_...jpg,
-- not the image itself. NULL means no picture uploaded -- the UI
-- falls back to a generic placeholder in that case.
