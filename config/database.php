<?php
/**
 * Database connection.
 * Uses PDO exclusively so that every query elsewhere in the app
 * naturally goes through prepared statements (see §16 — SQL Security).
 */

require_once __DIR__ . '/env.php';

function get_db_connection(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'travelease';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // use real prepared statements
        ]);

        // Store and compare all timestamps in UTC regardless of the DB
        // server's configured timezone. Local-time display, if ever
        // needed, happens in the presentation layer — never in storage.
        $pdo->exec("SET time_zone = '+00:00'");

        // Aiven's MySQL 8.4 default sql_mode includes both ANSI_QUOTES and
        // the ANSI combination mode. ANSI expands back out to ANSI_QUOTES
        // (among others) the moment SET sql_mode runs, so stripping only
        // the ANSI_QUOTES substring doesn't actually work -- it silently
        // comes right back via the ANSI token. Both must be stripped in
        // the same statement. Verified directly against Aiven: after this,
        // double-quoted string literals (e.g. status = "open") parse
        // correctly again, matching local MariaDB/XAMPP behavior.
        $pdo->exec("SET sql_mode = REPLACE(REPLACE(@@sql_mode, 'ANSI_QUOTES', ''), 'ANSI,', '')");
    } catch (PDOException $e) {
        // Never leak DB connection details to the browser (§39 — Error Handling).
        error_log('TravelEase DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        die('Something went wrong. Please try again later.');
    }

    return $pdo;
}