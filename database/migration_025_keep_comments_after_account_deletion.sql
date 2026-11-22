-- Migration 025 (FR-34): keep itinerary comments when their author deletes
-- their account. Each comment stores its author's display name, and the
-- user link becomes NULL instead of deleting the comment.
-- Apply once after 024. Fresh installs already have this shape in schema.sql.
ALTER TABLE itinerary_item_comments ADD COLUMN author_name VARCHAR(100) NULL AFTER user_id;
UPDATE itinerary_item_comments c JOIN users u ON u.id = c.user_id SET c.author_name = u.name WHERE c.author_name IS NULL;
ALTER TABLE itinerary_item_comments DROP FOREIGN KEY fk_itinerary_item_comments_user;
ALTER TABLE itinerary_item_comments MODIFY user_id INT UNSIGNED NULL;
ALTER TABLE itinerary_item_comments ADD CONSTRAINT fk_itinerary_item_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
