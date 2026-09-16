<?php
/**
 * Server-side validation for itinerary item forms.
 */

function validate_item_title(string $title): ?string
{
    $title = trim($title);
    if ($title === '') {
        return 'Title is required.';
    }
    if (mb_strlen($title) > 150) {
        return 'Title must be 150 characters or fewer.';
    }
    return null;
}

function validate_item_location(string $location): ?string
{
    if (mb_strlen($location) > 150) {
        return 'Location must be 150 characters or fewer.';
    }
    return null;
}

function validate_item_notes(string $notes): ?string
{
    if (mb_strlen($notes) > 2000) {
        return 'Notes must be 2000 characters or fewer.';
    }
    return null;
}

/**
 * Validates the item's date is a real date AND falls within the
 * parent trip's own date range. This is an application-layer rule --
 * the database has no way to enforce "this date must be between two
 * columns on a different table."
 */
function validate_item_date(string $itemDate, string $tripStart, string $tripEnd): ?string
{
    if ($err = validate_date_format($itemDate, 'Date')) {
        return $err;
    }
    if ($itemDate < $tripStart || $itemDate > $tripEnd) {
        return "Date must fall within the trip's dates ({$tripStart} to {$tripEnd}).";
    }
    return null;
}

/**
 * Validates an optional time string (HH:MM), or accepts an empty string.
 */
function validate_item_time(string $itemTime): ?string
{
    if ($itemTime === '') {
        return null; // time is optional
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $itemTime)) {
        return 'Time must be a valid time.';
    }
    return null;
}
