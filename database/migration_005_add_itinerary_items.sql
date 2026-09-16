-- Migration 005: Add itinerary_items table (Milestone 5)
-- Run this against your EXISTING travelease database.
--
-- Usage (phpMyAdmin): open the SQL tab on the `travelease` database,
-- paste this file's contents, click Go.

USE travelease;

CREATE TABLE IF NOT EXISTS itinerary_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id         INT UNSIGNED        NOT NULL,
    item_date       DATE                NOT NULL,
    item_time       TIME                NULL,
    title           VARCHAR(150)        NOT NULL,
    location        VARCHAR(150)        NULL,
    notes           TEXT                NULL,
    created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_itinerary_trip
        FOREIGN KEY (trip_id) REFERENCES trips(id)
        ON DELETE CASCADE,

    INDEX idx_itinerary_trip_id (trip_id),
    INDEX idx_itinerary_trip_date (trip_id, item_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ARCHITECTURE NOTES
--
-- No separate "days" table -- an item's own item_date is the day it
-- belongs to. The app groups items by date at query/display time
-- (ORDER BY item_date, item_time). This avoids a join for something
-- that's really just a derived grouping of a date column.
--
-- Ownership is two levels deep: itinerary_items -> trips -> users.
-- Every query in the app that touches an itinerary item must join
-- through trips and check trips.user_id = the logged-in user -- an
-- item's own row has no user_id of its own to check directly.
--
-- item_date is validated server-side (not just DB-constrained) to
-- fall within the parent trip's start_date/end_date range -- the
-- database itself doesn't enforce that cross-table constraint, so
-- the application layer is responsible for it on every write.
--
-- idx_itinerary_trip_date supports the common "all items for this
-- trip, in day order" query directly.
