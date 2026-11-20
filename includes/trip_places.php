<?php
require_once __DIR__ . '/itinerary_validation.php';

/** Read form fields without allowing arrays to reach string functions. */
function place_post_string(array $input, string $key, string $default = ''): string
{
    if (!array_key_exists($key, $input)) {
        return $default;
    }
    if (!is_string($input[$key])) {
        throw new InvalidArgumentException('Invalid place field.');
    }
    return trim($input[$key]);
}

function place_positive_id(array $input, string $key, bool $allowZero = false): int
{
    $value = place_post_string($input, $key);
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $allowZero ? 0 : 1, 'max_range' => 4294967295]]);
    if ($id === false) {
        throw new InvalidArgumentException('Invalid place or activity identifier.');
    }
    return $id;
}

/** Optional coordinate pair; normalized to the schema's six decimal places. */
function place_coordinates(string $latitude, string $longitude): array
{
    if ($latitude === '' && $longitude === '') {
        return [null, null];
    }
    $lat = filter_var($latitude, FILTER_VALIDATE_FLOAT);
    $lon = filter_var($longitude, FILTER_VALIDATE_FLOAT);
    if ($latitude === '' || $longitude === '' || $lat === false || $lon === false
        || !is_finite($lat) || !is_finite($lon) || abs($lat) > 90 || abs($lon) > 180) {
        throw new InvalidArgumentException('Enter both valid coordinates: latitude -90 to 90, longitude -180 to 180.');
    }
    return [number_format($lat, 6, '.', ''), number_format($lon, 6, '.', '')];
}

function validate_trip_place(array $input): array
{
    $name = place_post_string($input, 'name');
    $address = place_post_string($input, 'address');
    $category = place_post_string($input, 'category');
    $source = place_post_string($input, 'source', 'manual');
    $providerId = place_post_string($input, 'provider_place_id');
    if ($name === '' || mb_strlen($name) > 150 || mb_strlen($address) > 255
        || mb_strlen($providerId) > 2048 || validate_item_category($category) !== null
        || !in_array($source, ['manual', 'geoapify'], true)) {
        throw new InvalidArgumentException('Enter a place name (up to 150 characters), valid category and address (up to 255 characters).');
    }
    [$latitude, $longitude] = place_coordinates(
        place_post_string($input, 'latitude'), place_post_string($input, 'longitude')
    );
    return [
        'name' => $name, 'address' => $address !== '' ? $address : null,
        'latitude' => $latitude, 'longitude' => $longitude,
        'category' => $category !== '' ? $category : null, 'source' => $source,
        'provider_place_id' => $source === 'geoapify' && $providerId !== '' ? $providerId : null,
    ];
}
