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

        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET sql_mode = REPLACE(@@sql_mode, 'ANSI_QUOTES', '')");
    } catch (PDOException $e) {
        error_log('TravelEase DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        die('Something went wrong. Please try again later.');
    }

    return $pdo;
}