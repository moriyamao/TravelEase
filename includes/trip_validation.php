<?php
/**
 * Server-side validation for trip create/edit forms.
 * Client-side validation on the forms is for usability only.
 */

function validate_trip_name(string $name): ?string
{
    $name = trim($name);
    if ($name === '') {
        return 'Trip name is required.';
    }
    if (mb_strlen($name) > 150) {
        return 'Trip name must be 150 characters or fewer.';
    }
    return null;
}

function validate_trip_destination(string $destination): ?string
{
    $destination = trim($destination);
    if ($destination === '') {
        return 'Destination is required.';
    }
    if (mb_strlen($destination) > 150) {
        return 'Destination must be 150 characters or fewer.';
    }
    return null;
}

/**
 * Validates a date string is in Y-m-d format and represents a real date.
 */
function validate_date_format(string $date, string $fieldLabel): ?string
{
    if ($date === '') {
        return "{$fieldLabel} is required.";
    }

    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    $errors = DateTime::getLastErrors();

    if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return "{$fieldLabel} must be a valid date.";
    }

    return null;
}

function validate_trip_dates(string $startDate, string $endDate): ?string
{
    if ($err = validate_date_format($startDate, 'Start date')) {
        return $err;
    }
    if ($err = validate_date_format($endDate, 'End date')) {
        return $err;
    }
    if ($endDate < $startDate) {
        return 'End date cannot be before start date.';
    }
    return null;
}

function validate_trip_status(string $status): ?string
{
    $allowed = ['planning', 'confirmed', 'completed', 'cancelled'];
    if (!in_array($status, $allowed, true)) {
        return 'Invalid trip status.';
    }
    return null;
}

function validate_trip_notes(string $notes): ?string
{
    if (mb_strlen($notes) > 5000) {
        return 'Notes must be 5000 characters or fewer.';
    }
    return null;
}

/**
 * A fixed list of common travel currencies, not a currencies lookup
 * table -- this set changes rarely enough that a small allowlist is
 * proportional, and it protects budget_currency from arbitrary junk
 * input without the overhead of a full ISO 4217 table.
 */
const TRIP_ALLOWED_CURRENCIES = [
    'PHP', 'USD', 'EUR', 'GBP', 'JPY', 'KRW', 'CNY', 'HKD', 'SGD',
    'THB', 'VND', 'IDR', 'MYR', 'TWD', 'AUD', 'CAD', 'AED', 'INR',
];

function validate_trip_currency(string $currency): ?string
{
    if (!in_array($currency, TRIP_ALLOWED_CURRENCIES, true)) {
        return 'Please choose a supported currency.';
    }
    return null;
}

/**
 * Shared cost validator for both a trip's overall budget_amount and
 * an itinerary item's estimated_cost / actual_cost -- all-in amounts
 * (tax already included, see migration_006's notes), so the rule is
 * the same wherever a money field appears: optional, non-negative,
 * at most two decimal places, and within what DECIMAL(10,2) can hold.
 */
function validate_money_amount(string $amount, string $fieldLabel): ?string
{
    if ($amount === '') {
        return null; // every cost/budget field in this app is optional
    }
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $amount)) {
        return "{$fieldLabel} must be a non-negative number with at most 2 decimal places.";
    }
    if ((float) $amount > 99999999.99) {
        return "{$fieldLabel} is too large.";
    }
    return null;
}
