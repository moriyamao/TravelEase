-- Migration 030: Prevent stale itinerary edits from overwriting newer changes.

ALTER TABLE itinerary_items
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER sort_order,
    ADD CONSTRAINT chk_itinerary_items_version CHECK (version > 0);
