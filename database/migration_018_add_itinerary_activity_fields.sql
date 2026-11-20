-- Run once after migration_017.
-- FR-19: richer itinerary activities. Adds columns to itinerary_items in
-- one statement. Existing columns and rows are unchanged: item_time stays
-- the start time, costs and notes are untouched, and every existing row
-- gets NULL / 0 in the new columns. Adding columns does not fire
-- ON UPDATE, so no activity's updated_at changes (FR-17's "edited after
-- feedback" label is not triggered by this migration).
--
-- end_time      optional end, later than item_time on the same day (PHP)
-- category      optional fixed list; NULL shows as "Uncategorized"
-- assignee_id   a trip member when assigned; kept if they later leave
-- sort_order    0 = time order; a day that has been reordered uses 1..n
-- completed_at  set when an owner/editor marks the activity done
-- completed_by  who marked it done
ALTER TABLE itinerary_items
    ADD COLUMN end_time TIME NULL AFTER item_time,
    ADD COLUMN category ENUM('sightseeing', 'food', 'transport', 'lodging', 'activity', 'shopping', 'other') NULL AFTER location,
    ADD COLUMN assignee_id INT UNSIGNED NULL AFTER category,
    ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER assignee_id,
    ADD COLUMN completed_at TIMESTAMP NULL AFTER actual_cost,
    ADD COLUMN completed_by INT UNSIGNED NULL AFTER completed_at,
    ADD INDEX idx_itinerary_trip_date_order (trip_id, item_date, sort_order),
    ADD CONSTRAINT fk_itinerary_assignee
        FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_itinerary_completed_by
        FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL;
