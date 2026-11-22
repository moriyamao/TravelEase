-- FR-06: archive is reversible filing, not a booking status or deletion.
ALTER TABLE trips ADD COLUMN archived_at DATETIME NULL;
