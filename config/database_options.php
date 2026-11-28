<?php
/**
 * Build PDO connection options without loading application environment files.
 * Keeping this pure lets configuration failures be tested with synthetic input.
 */
function database_pdo_options(array $settings): array
{
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $appEnv = trim((string) ($settings['APP_ENV'] ?? ''));
    $caFile = trim((string) ($settings['DB_SSL_CA_FILE'] ?? ''));

    if ($appEnv === 'production' && $caFile === '') {
        throw new RuntimeException('DB TLS: production requires DB_SSL_CA_FILE');
    }

    if ($caFile !== '') {
        $handle = is_file($caFile) ? @fopen($caFile, 'rb') : false;
        if ($handle === false) {
            throw new RuntimeException('DB TLS: CA file not readable');
        }
        fclose($handle);

        $options[PDO::MYSQL_ATTR_SSL_CA] = $caFile;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    return $options;
}
