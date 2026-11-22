<?php
/**
 * FR-20: warn when consecutive activities at two different saved places do
 * not leave enough time to travel between them.
 *
 * Only activities that have a start time and are linked to a saved place
 * with coordinates are considered. Estimates are Geoapify driving times,
 * cached for 30 days in place_lookup_cache. Pages read the cache only;
 * provider calls happen when a member presses "Check travel times"
 * (travel_check.php). Warnings never block saving.
 */
require_once __DIR__ . '/place_lookup.php';

const TRAVEL_CACHE_DAYS = 30;
const TRAVEL_CHECKS_PER_REQUEST = 5;

function travel_seconds_of_day(?string $time): ?int
{
    if ($time === null || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/D', $time, $m)) {
        return null;
    }
    return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) ($m[3] ?? 0);
}

/**
 * Consecutive same-day pairs (by start time) at two different located places.
 * Items need: id, item_date, item_time, end_time, place_lat, place_lon.
 * The gap runs from the earlier activity's end (or its start, if it has no
 * end time) to the next activity's start. Overlaps are reported elsewhere.
 * @return list<array{from_id:int, to_id:int, from:array{float,float}, to:array{float,float}, gap:int, key:string}>
 */
function itinerary_travel_pairs(array $items): array
{
    $byDay = [];
    foreach ($items as $item) {
        $start = travel_seconds_of_day($item['item_time'] ?? null);
        if ($start === null || ($item['place_lat'] ?? null) === null || ($item['place_lon'] ?? null) === null) {
            continue;
        }
        $end = travel_seconds_of_day($item['end_time'] ?? null);
        $byDay[$item['item_date']][] = [
            'id' => (int) $item['id'], 'start' => $start, 'end' => ($end !== null && $end > $start) ? $end : $start,
            'point' => [round((float) $item['place_lat'], 5), round((float) $item['place_lon'], 5)],
        ];
    }
    $pairs = [];
    foreach ($byDay as $day) {
        usort($day, fn($a, $b) => [$a['start'], $a['id']] <=> [$b['start'], $b['id']]);
        for ($i = 1, $n = count($day); $i < $n; $i++) {
            [$a, $b] = [$day[$i - 1], $day[$i]];
            $gap = $b['start'] - $a['end'];
            if ($gap < 0 || $a['point'] === $b['point']) {
                continue;
            }
            $pairs[] = ['from_id' => $a['id'], 'to_id' => $b['id'], 'from' => $a['point'], 'to' => $b['point'],
                'gap' => $gap, 'key' => travel_cache_key($a['point'], $b['point'])];
        }
    }
    return $pairs;
}

function travel_cache_key(array $from, array $to): string
{
    return hash('sha256', sprintf('route|drive|%.5f,%.5f|%.5f,%.5f', $from[0], $from[1], $to[0], $to[1]));
}

/**
 * Cached estimates for the given pairs: cache key => ['seconds' => int] or
 * ['none' => true] when the provider found no route. Missing keys are unchecked.
 */
function cached_travel_estimates(PDO $pdo, array $pairs): array
{
    $keys = array_values(array_unique(array_column($pairs, 'key')));
    if (!$keys) {
        return [];
    }
    $placeholders = implode(',', array_map(fn($i) => ':k' . $i, array_keys($keys)));
    $stmt = $pdo->prepare("SELECT cache_key, payload FROM place_lookup_cache
                           WHERE cache_key IN ($placeholders) AND expires_at > UTC_TIMESTAMP()");
    $stmt->execute(array_combine(array_map(fn($i) => 'k' . $i, array_keys($keys)), $keys));
    $estimates = [];
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $key => $payload) {
        $data = json_decode((string) $payload, true);
        if (is_array($data) && (isset($data['seconds']) || isset($data['none']))) {
            $estimates[$key] = $data;
        }
    }
    return $estimates;
}

/** @return array<int, list<array{type:string, other_id:int, travel_minutes:int, gap_minutes:int}>> warnings keyed by the later activity */
function itinerary_travel_warnings(array $pairs, array $estimates): array
{
    $warnings = [];
    foreach ($pairs as $pair) {
        $seconds = $estimates[$pair['key']]['seconds'] ?? null;
        if ($seconds !== null && (int) $seconds > $pair['gap']) {
            $warnings[$pair['to_id']][] = [
                'type' => 'travel', 'other_id' => $pair['from_id'],
                'travel_minutes' => (int) ceil($seconds / 60), 'gap_minutes' => intdiv($pair['gap'], 60),
            ];
        }
    }
    return $warnings;
}

/** Pairs whose estimate is not cached yet. */
function unchecked_travel_pairs(array $pairs, array $estimates): array
{
    $seen = [];
    $unchecked = [];
    foreach ($pairs as $pair) {
        if (!isset($estimates[$pair['key']]) && !isset($seen[$pair['key']])) {
            $seen[$pair['key']] = true;
            $unchecked[] = $pair;
        }
    }
    return $unchecked;
}

/**
 * Ask the provider for one driving estimate and cache it. Returns
 * ['seconds' => int] or ['none' => true]; throws UnexpectedValueException
 * when the provider is unavailable (nothing is cached then).
 */
function fetch_and_cache_travel_estimate(PDO $pdo, array $pair): array
{
    $response = geoapify_request('/v1/routing', [
        'waypoints' => sprintf('%.5f,%.5f|%.5f,%.5f', $pair['from'][0], $pair['from'][1], $pair['to'][0], $pair['to'][1]),
        'mode' => 'drive',
    ]);
    $time = $response['data']['features'][0]['properties']['time'] ?? null;
    if ($response['status'] === 200 && is_numeric($time) && $time >= 0 && $time < 7 * 86400) {
        $estimate = ['seconds' => (int) round((float) $time)];
    } elseif ($response['status'] === 200 || $response['status'] === 400) {
        $estimate = ['none' => true]; // valid request, but no drivable route between the two places
    } else {
        error_log('TravelEase: routing provider request failed (HTTP ' . $response['status'] . ').');
        throw new UnexpectedValueException('Travel times are unavailable right now.');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO place_lookup_cache (cache_key, payload, expires_at)
         VALUES (:key, :payload, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . TRAVEL_CACHE_DAYS . ' DAY))
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at)'
    );
    $stmt->execute(['key' => $pair['key'], 'payload' => json_encode($estimate)]);
    return $estimate;
}

/** Claim the app-wide one-provider-call-per-second slot, waiting up to ~1.5 s. */
function claim_provider_slot(PDO $pdo): bool
{
    $stmt = $pdo->prepare(
        'INSERT INTO place_lookup_cache (cache_key, payload, expires_at)
         VALUES (\'provider\', \'\', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 SECOND))
         ON DUPLICATE KEY UPDATE expires_at = IF(expires_at <= UTC_TIMESTAMP(), VALUES(expires_at), expires_at)'
    );
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            return true;
        }
        usleep(500000);
    }
    return false;
}
