<?php
require_once __DIR__ . '/trip_places.php';

const PLACE_NEARBY_CATEGORIES = [
    'sightseeing' => 'tourism', 'food' => 'catering', 'transport' => 'public_transport',
    'lodging' => 'accommodation', 'activity' => 'entertainment', 'shopping' => 'commercial', 'other' => 'leisure',
];

/** Reserve one of 30 lookups/hour for an account, across sessions/trips. */
function reserve_place_lookup(PDO $pdo, int $userId): int
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO place_lookup_limits (user_id) VALUES (:user_id)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)'
        );
        $stmt->execute(['user_id' => $userId]);
        $stmt = $pdo->prepare(
            'SELECT attempts, TIMESTAMPDIFF(SECOND, window_started_at, UTC_TIMESTAMP()) AS age
             FROM place_lookup_limits WHERE user_id = :user_id FOR UPDATE'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        if ((int) $row['age'] < 3600 && (int) $row['attempts'] >= 30) {
            $pdo->rollBack();
            return max(1, min(3600, 3600 - (int) $row['age']));
        }
        $stmt = $pdo->prepare((int) $row['age'] >= 3600
            ? 'UPDATE place_lookup_limits SET window_started_at = UTC_TIMESTAMP(), attempts = 1 WHERE user_id = :user_id'
            : 'UPDATE place_lookup_limits SET attempts = attempts + 1 WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $pdo->commit();
        return 0;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Normalize provider data to fields the save form accepts; no HTML/URLs. */
function normalize_place_results(array $data): array
{
    if (!isset($data['features']) || !is_array($data['features'])) {
        throw new UnexpectedValueException('Invalid place provider response.');
    }
    $results = [];
    foreach (array_slice($data['features'], 0, 5) as $feature) {
        $p = $feature['properties'] ?? null;
        if (!is_array($p) || !is_scalar($p['lat'] ?? null) || !is_scalar($p['lon'] ?? null)
            || is_bool($p['lat']) || is_bool($p['lon'])) {
            continue;
        }
        try {
            [$latitude, $longitude] = place_coordinates((string) $p['lat'], (string) $p['lon']);
        } catch (InvalidArgumentException $e) {
            continue;
        }
        if ($latitude === null || $longitude === null) {
            continue;
        }
        $name = $p['name'] ?? $p['address_line1'] ?? $p['formatted'] ?? '';
        $address = $p['formatted'] ?? $p['address_line2'] ?? '';
        if (!is_string($name) || !is_string($address) || trim($name) === ''
            || preg_match('//u', $name . $address) !== 1) {
            continue;
        }
        $category = 'other';
        foreach (is_array($p['categories'] ?? null) ? $p['categories'] : [] as $providerCategory) {
            foreach (PLACE_NEARBY_CATEGORIES as $local => $prefix) {
                if (is_string($providerCategory) && ($providerCategory === $prefix || str_starts_with($providerCategory, $prefix . '.'))) {
                    $category = $local;
                    break 2;
                }
            }
        }
        $results[] = [
            'name' => mb_substr(trim($name), 0, 150), 'address' => mb_substr($address, 0, 255),
            'latitude' => $latitude, 'longitude' => $longitude, 'category' => $category,
            'provider_place_id' => is_string($p['place_id'] ?? null) ? mb_substr($p['place_id'], 0, 2048) : '',
        ];
    }
    return $results;
}

/** Fixed Geoapify paths; the optional base override is for local stub tests. */
/**
 * One GET to the configured Geoapify-compatible API. Returns the decoded JSON
 * and the HTTP status; throws on transport failure, bad configuration or a
 * non-JSON body. The URL is never logged (it contains the API key).
 */
function geoapify_request(string $path, array $params): array
{
    $base = rtrim(getenv('GEOAPIFY_API_URL') ?: 'https://api.geoapify.com', '/');
    $parts = parse_url($base);
    $localTest = in_array(PHP_SAPI, ['cli', 'cli-server'], true)
        && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true)
        && ($parts['scheme'] ?? '') === 'http';
    if (!$parts || (!$localTest && ($parts['scheme'] ?? '') !== 'https')
        || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) {
        throw new UnexpectedValueException('Invalid place provider configuration.');
    }
    $params['apiKey'] = getenv('GEOAPIFY_API_KEY') ?: '';
    $url = $base . $path . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $body = '';
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'TravelEase/1.0 (+https://github.com/moriyamao/TravelEase)',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 524288) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $success = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($success === false) {
        error_log('TravelEase: place provider request failed (no response).');
        throw new UnexpectedValueException('Place search is unavailable. Please try again later or add the place manually.');
    }
    $data = json_decode($body, true, 64);
    return ['status' => $status, 'data' => is_array($data) ? $data : null];
}

function fetch_place_results(string $path, array $params): array
{
    if (!in_array($path, ['/v1/geocode/search', '/v2/places'], true)) {
        throw new InvalidArgumentException('Invalid place lookup.');
    }
    $response = geoapify_request($path, $params);
    if ($response['status'] !== 200) {
        // Do not log the URL: it contains the API key and search text.
        error_log('TravelEase: place provider request failed (HTTP ' . $response['status'] . ').');
        throw new UnexpectedValueException('Place search is unavailable. Please try again later or add the place manually.');
    }
    if ($response['data'] === null) {
        throw new UnexpectedValueException('Invalid place provider response.');
    }
    return normalize_place_results($response['data']);
}

/** Fifteen-minute public-result cache plus at most one provider start/second. */
function lookup_places(PDO $pdo, string $path, array $params): array
{
    $key = hash('sha256', $path . json_encode($params, JSON_THROW_ON_ERROR));
    $stmt = $pdo->prepare('SELECT payload FROM place_lookup_cache WHERE cache_key = :key AND expires_at > UTC_TIMESTAMP()');
    $stmt->execute(['key' => $key]);
    $cached = $stmt->fetchColumn();
    if ($cached !== false) {
        $results = json_decode($cached, true, 64, JSON_THROW_ON_ERROR);
        if (is_array($results)) {
            return ['ok' => true, 'results' => $results];
        }
    }
    $stmt = $pdo->prepare(
        'INSERT INTO place_lookup_cache (cache_key, payload, expires_at)
         VALUES (\'provider\', \'\', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 SECOND))
         ON DUPLICATE KEY UPDATE expires_at = IF(expires_at <= UTC_TIMESTAMP(), VALUES(expires_at), expires_at)'
    );
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'status' => 429, 'retry_after' => 1, 'error' => 'Place search is busy. Try again in a moment.'];
    }
    $results = fetch_place_results($path, $params);
    $stmt = $pdo->prepare(
        'INSERT INTO place_lookup_cache (cache_key, payload, expires_at)
         VALUES (:key, :payload, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at)'
    );
    $stmt->execute(['key' => $key, 'payload' => json_encode($results, JSON_THROW_ON_ERROR)]);
    $pdo->exec('DELETE FROM place_lookup_cache WHERE expires_at < UTC_TIMESTAMP() LIMIT 100');
    return ['ok' => true, 'results' => $results];
}
