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

/** FR-19 categories (value => label). Empty means "Uncategorized". */
const ITINERARY_CATEGORIES = [
    'sightseeing' => 'Sightseeing',
    'food'        => 'Food',
    'transport'   => 'Transport',
    'lodging'     => 'Lodging',
    'activity'    => 'Activity',
    'shopping'    => 'Shopping',
    'other'       => 'Other',
];

/**
 * Activity order within one day. The page, the reorder action and new
 * activities all use this, so "up" and "down" always mean what is shown.
 * sort_order 0 everywhere = plain time order (as before FR-19).
 */
const ITINERARY_DAY_ORDER_SQL = 'sort_order ASC, item_time ASC, id ASC';

/**
 * Optional end time (HH:MM). Needs a start time and must be later than
 * it on the same day; overnight activities are not supported.
 */
function validate_item_end_time(string $startTime, string $endTime): ?string
{
    if ($endTime === '') {
        return null;
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $endTime)) {
        return 'End time must be a valid time.';
    }
    if ($startTime === '') {
        return 'Add a start time before adding an end time.';
    }
    if ($endTime <= substr($startTime, 0, 5)) {
        return 'End time must be later than the start time on the same day.';
    }
    return null;
}

function validate_item_category(string $category): ?string
{
    if ($category === '' || isset(ITINERARY_CATEGORIES[$category])) {
        return null;
    }
    return 'Choose a valid category.';
}

/** Assignee field: '' => null (unassigned), digits => user id, else false. */
function parse_item_assignee(string $raw): int|false|null
{
    if ($raw === '') {
        return null;
    }
    return ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : false;
}

/**
 * sort_order for an activity placed on $date: 0 (time order) while that
 * day has never been reordered, otherwise after the day's last activity.
 * Call under the trip lock.
 */
function itinerary_next_sort_order(PDO $pdo, int $tripId, string $date, int $excludeItemId = 0): int
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(sort_order), 0) FROM itinerary_items
         WHERE trip_id = :trip_id AND item_date = :item_date AND id <> :exclude_id'
    );
    $stmt->execute(['trip_id' => $tripId, 'item_date' => $date, 'exclude_id' => $excludeItemId]);
    $max = (int) $stmt->fetchColumn();
    return $max > 0 ? $max + 1 : 0;
}
