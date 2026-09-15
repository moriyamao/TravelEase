<?php
/**
 * Server-side validation helpers.
 * Client-side validation (in the HTML forms) is for usability only —
 * these are the checks that actually matter (§14 — Input Validation).
 */

function validate_name(string $name): ?string
{
    $name = trim($name);

    if ($name === '') {
        return 'Name is required.';
    }
    if (mb_strlen($name) > 100) {
        return 'Name must be 100 characters or fewer.';
    }
    return null;
}

/**
 * Normalizes an email address for storage and lookup.
 * Lowercasing avoids case-variant duplicate accounts and keeps future
 * Google-provided emails directly comparable to stored ones.
 */
function normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

function validate_email_format(string $email): ?string
{
    $email = trim($email);

    if ($email === '') {
        return 'Email is required.';
    }
    if (mb_strlen($email) > 255) {
        return 'Email must be 255 characters or fewer.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    return null;
}

function validate_password(string $password): ?string
{
    if (mb_strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (mb_strlen($password) > 255) {
        return 'Password is too long.';
    }
    return null;
}
