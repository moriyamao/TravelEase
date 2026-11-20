<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/place_lookup.php';

require_role(['customer']);
function place_search_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    if (isset($data['retry_after'])) {
        header('Retry-After: ' . (int) $data['retry_after']);
    }
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    place_search_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
if (!csrf_verify(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    place_search_response(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 403);
}
try {
    $tripId = place_positive_id($_POST, 'trip_id');
    $mode = place_post_string($_POST, 'mode');
    if (!in_array($mode, ['text', 'nearby'], true)) {
        throw new InvalidArgumentException('Choose a valid place search.');
    }
    $pdo = get_db_connection();
    require_trip_permission($pdo, $tripId, 'view');
    if ($mode === 'text') {
        $query = place_post_string($_POST, 'q');
        if (mb_strlen($query) < 2 || mb_strlen($query) > 100 || preg_match('//u', $query) !== 1) {
            throw new InvalidArgumentException('Search using 2 to 100 characters.');
        }
        $path = '/v1/geocode/search';
        $params = ['text' => $query, 'format' => 'geojson', 'limit' => 5];
    } else {
        $placeId = place_positive_id($_POST, 'place_id');
        $category = place_post_string($_POST, 'category');
        if (!isset(PLACE_NEARBY_CATEGORIES[$category])) {
            throw new InvalidArgumentException('Choose a nearby place category.');
        }
        $stmt = $pdo->prepare('SELECT latitude, longitude FROM trip_places WHERE id = :id AND trip_id = :trip_id');
        $stmt->execute(['id' => $placeId, 'trip_id' => $tripId]);
        $place = $stmt->fetch();
        if (!$place) {
            place_search_response(['ok' => false, 'error' => 'Place not found.'], 404);
        }
        if ($place['latitude'] === null || $place['longitude'] === null) {
            throw new InvalidArgumentException('Add coordinates to this place before searching nearby.');
        }
        $center = $place['longitude'] . ',' . $place['latitude'];
        $path = '/v2/places';
        $params = ['categories' => PLACE_NEARBY_CATEGORIES[$category], 'filter' => 'circle:' . $center . ',1500',
            'bias' => 'proximity:' . $center, 'limit' => 5];
    }
    if (!getenv('GEOAPIFY_API_KEY')) {
        place_search_response(['ok' => false, 'error' => 'Search unavailable — add the place manually.'], 503);
    }
    $retry = reserve_place_lookup($pdo, (int) current_user_id());
    if ($retry > 0) {
        place_search_response(['ok' => false, 'error' => 'Place search limit reached. Try again later.', 'retry_after' => $retry], 429);
    }
    $result = lookup_places($pdo, $path, $params);
    place_search_response($result, $result['status'] ?? 200);
} catch (InvalidArgumentException $e) {
    place_search_response(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (PDOException $e) {
    error_log('TravelEase: place lookup database failure (code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    place_search_response(['ok' => false, 'error' => 'Place search is temporarily unavailable. Saved places are still available.'], 503);
} catch (UnexpectedValueException | JsonException $e) {
    error_log('TravelEase: place provider returned an unavailable or invalid response.');
    place_search_response(['ok' => false, 'error' => 'Place search is unavailable. Try again later or add the place manually.'], 503);
}
