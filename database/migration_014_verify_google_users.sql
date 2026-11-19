-- Run once after migration_013.
-- Google sign-up accounts were created without email_verified_at even
-- though Google had already verified the email, so they could not be
-- invited to trips. Mark those existing accounts as verified.
UPDATE users
SET email_verified_at = UTC_TIMESTAMP()
WHERE google_subject IS NOT NULL
  AND email_verified_at IS NULL;
