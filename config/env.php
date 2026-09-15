<?php
/**
 * Minimal .env loader.
 * Avoids pulling in a Composer dependency for something this small
 * (see project instructions §44 — only add dependencies when they earn it).
 */

function load_env(string $path): void
{
    if (!is_readable($path)) {
        // Fail loudly in development, but never leak the path to a browser response.
        error_log("TravelEase: .env file not found or not readable at: {$path}");
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Strip optional surrounding quotes.
        $value = trim($value, "\"'");

        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

$envFile = dirname(__DIR__) . '/.env';
load_env($envFile);

// All server-side time handling is UTC. Any local-time display is a
// presentation-layer concern for a later milestone, not a storage one.
date_default_timezone_set('UTC');
