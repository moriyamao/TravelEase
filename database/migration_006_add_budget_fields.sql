-- Migration 006: Add budget fields (Milestone 6)
-- Run this against your EXISTING travelease database.
--
-- Usage (phpMyAdmin): open the SQL tab on the `travelease` database,
-- paste this file's contents, click Go.

USE travelease;

ALTER TABLE trips
    ADD COLUMN budget_currency CHAR(3) NOT NULL DEFAULT 'PHP' AFTER status,
    ADD COLUMN budget_amount   DECIMAL(10,2) NULL AFTER budget_currency;

ALTER TABLE itinerary_items
    ADD COLUMN estimated_cost DECIMAL(10,2) NULL AFTER notes,
    ADD COLUMN actual_cost    DECIMAL(10,2) NULL AFTER estimated_cost;

-- ARCHITECTURE NOTES
--
-- Currency lives on the TRIP, not per item. A trip has one
-- destination and one currency you'd realistically be spending in --
-- multi-currency conversion within a single trip (e.g. mixing JPY
-- and USD costs with live exchange rates) is a much heavier feature
-- (needs a rate source, historical rates, refresh policy) that
-- nothing in the current app asks for. If that's ever genuinely
-- needed, it's an additive migration on top of this, not a rework.
--
-- budget_currency is a plain CHAR(3), validated at the application
-- layer against a fixed list of common ISO 4217 codes (see
-- includes/trip_validation.php) rather than a currencies lookup
-- table -- proportional complexity for a fixed, rarely-changing set.
--
-- estimated_cost / actual_cost are both nullable -- not every
-- itinerary item has a cost (e.g. "walk around the old town"), and
-- actual_cost is naturally unset until the traveler has actually
-- spent the money.
--
-- Costs are stored as the final, all-in amount actually paid or
-- expected to be paid (tax already included) -- no separate tax
-- column. This matches how a traveler actually thinks about a price
-- ("the ticket cost me 3300 yen") and avoids a second cost field on
-- every item for a distinction the app has no current use for. If
-- itemized tax reporting becomes a real requirement later, that's a
-- new additive column, not a redesign of these two.
--
-- budget_amount on trips is optional -- a target budget to compare
-- the summed item costs against. When unset, the app shows totals
-- only, with no over/under comparison.
--
-- DECIMAL(10,2), not FLOAT/DOUBLE, for exact monetary arithmetic --
-- no floating-point rounding surprises when summing item costs.
