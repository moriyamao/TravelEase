-- Run once after migration_015.
-- FR-17 activity slice: trip members whose role has the 'feedback'
-- permission (owner, editor, commenter) vote on and comment on itinerary
-- activities. Viewers can read feedback but not write it. Destination
-- proposals are deferred: trips.destination is a single owner-edited
-- field with no proposal model yet.
-- Adds two new tables only; no existing table changes.

-- 1. One vote per member per activity (enforced by the primary key).
--    vote is +1 (up) or -1 (down); changing a vote updates the row and
--    clearing it deletes the row. created_at is kept on change, so the
--    "edited after feedback" check uses when feedback first arrived.
CREATE TABLE itinerary_item_votes (
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

-- 2. Comments on an activity, 1-1000 characters (validated in PHP by
--    Unicode character count). Not editable or deletable in this slice;
--    they stay as trip history if the author later leaves the trip.
CREATE TABLE itinerary_item_comments (
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
