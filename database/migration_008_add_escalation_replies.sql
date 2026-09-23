-- Migration 008: Staff replies on escalations

-- Turns escalation from a one-way notification (customer message ->
-- staff sees it -> mark resolved) into an actual two-way exchange:
-- staff can now respond to the customer through the app itself, not
-- just via the separate EmailJS-sent email
--
-- Kept as one reply per ticket, not a full message thread, matches
-- "staff handles this request" rather than open-ended back-and-forth.
-- Reply and resolution are deliberately independent: a staff member
-- can reply without resolving (e.g. "looking into this") and the
-- eventual resolve is a separate action
--
-- customer_seen_at tracks whether the customer has acknowledged the
-- reply, so the dashboard banner doesn't show forever once they've
-- already read it

ALTER TABLE escalations
    ADD COLUMN staff_reply TEXT NULL AFTER message,
    ADD COLUMN replied_at TIMESTAMP NULL AFTER staff_reply,
    ADD COLUMN customer_seen_at TIMESTAMP NULL AFTER replied_at;