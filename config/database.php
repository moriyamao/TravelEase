<?php
/**
 * Database connection.
 * Uses PDO exclusively so that every query elsewhere in the app
 * naturally goes through prepared statements (see §16 — SQL Security).
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/database_options.php';
require_once __DIR__ . '/../includes/timing.php';

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
        $options = database_pdo_options([
            'APP_ENV' => getenv('APP_ENV'),
            'DB_SSL_CA_FILE' => getenv('DB_SSL_CA_FILE'),
        ]);

        // Opt-in diagnostics (APP_TIMING=1): time connect, setup and every query.
        $timing = request_timing_enabled();
        if ($timing) {
            $options[PDO::ATTR_STATEMENT_CLASS] = [TimedPDOStatement::class];
            RequestTiming::register();
        }

        $connectStart = microtime(true);
        $pdo = $timing ? new TimedPDO($dsn, $user, $pass, $options) : new PDO($dsn, $user, $pass, $options);
        $setupStart = microtime(true);
        if ($timing) {
            RequestTiming::$connect = $setupStart - $connectStart;
        }

        // One round trip for both session settings:
        //
        // time_zone: store and compare all timestamps in UTC regardless of
        // the DB server's configured timezone. Local-time display, if ever
        // needed, happens in the presentation layer — never in storage.
        //
        // sql_mode: Aiven's MySQL 8.4 default sql_mode includes both ANSI_QUOTES and
        // the ANSI combination mode. ANSI expands back out to ANSI_QUOTES
        // (among others) the moment SET sql_mode runs, so stripping only
        // the ANSI_QUOTES substring doesn't actually work -- it silently
        // comes right back via the ANSI token. Both must be stripped in
        // the same statement. Verified directly against Aiven: after this,
        // double-quoted string literals (e.g. status = "open") parse
        // correctly again, matching local MariaDB/XAMPP behavior.
        $pdo->exec(
            "SET time_zone = '+00:00', "
            . "sql_mode = REPLACE(REPLACE(@@sql_mode, 'ANSI_QUOTES', ''), 'ANSI,', '')"
        );

        if ($timing) {
            // Report the setup statement separately from page queries.
            RequestTiming::$setup = microtime(true) - $setupStart;
            RequestTiming::$counts['other'] = 0;
            RequestTiming::$times['other'] = 0.0;
        }
    } catch (RuntimeException $e) {
        // Never leak DB connection details to the browser (§39 — Error Handling).
        if ($e instanceof PDOException) {
            error_log('TravelEase DB connection failed (driver ['
                . (int) ($e->errorInfo[1] ?? 0) . ']).');
        } else {
            // The options helper emits fixed configuration categories only.
            error_log('TravelEase DB connection failed: ' . $e->getMessage());
        }
        http_response_code(500);
        die('Something went wrong. Please try again later.');
    }

    return $pdo;
}
