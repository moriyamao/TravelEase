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
