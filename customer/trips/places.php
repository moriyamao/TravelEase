<?php
/**
 * FR-21 / FR-27: a trip's saved places, map and day-by-day route context.
 *
 * Every trip member can view this page and search (place_search.php).
 * Owners and editors save, update, delete and link places
 * (place_action.php). Loading this page makes no geocoding calls: the map
 * shows only coordinates already saved. Lines between a day's places show
 * the order of the itinerary only -- not a road route or travel time.
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/itinerary_validation.php';

require_role(['customer']);

$tripId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$tripId) {
    http_response_code(400);
    die('Invalid trip.');
}

$pdo = get_db_connection();
$tripRole = require_trip_permission($pdo, $tripId, 'view');
$canEdit = trip_role_can($tripRole, 'edit_itinerary');

$stmt = $pdo->prepare('SELECT name, destination FROM trips WHERE id = :id');
$stmt->execute(['id' => $tripId]);
$trip = $stmt->fetch();

$stmt = $pdo->prepare(
    'SELECT id, name, address, latitude, longitude, category, source, version
     FROM trip_places WHERE trip_id = :trip_id ORDER BY name ASC, id ASC'
);
$stmt->execute(['trip_id' => $tripId]);
$places = $stmt->fetchAll();
$placesById = array_column($places, null, 'id');

// Activities in itinerary order with their linked place (if any). The
// place must belong to the same trip; anything else is treated as unlinked.
$stmt = $pdo->prepare(
    'SELECT i.id, i.item_date, i.item_time, i.title, i.place_id
     FROM itinerary_items i
     WHERE i.trip_id = :trip_id
     ORDER BY i.item_date ASC, i.sort_order ASC, i.item_time ASC, i.id ASC'
);
$stmt->execute(['trip_id' => $tripId]);
$activitiesByDate = [];
foreach ($stmt->fetchAll() as $activity) {
    $activitiesByDate[$activity['item_date']][] = $activity;
}

// Map data: saved places with coordinates, and per-day ordered points.
$hasCoords = fn($p) => $p !== null && $p['latitude'] !== null && $p['longitude'] !== null;
$mapPlaces = [];
foreach ($places as $p) {
    if ($hasCoords($p)) {
        $mapPlaces[] = ['id' => (int) $p['id'], 'name' => $p['name'], 'lat' => (float) $p['latitude'], 'lon' => (float) $p['longitude']];
    }
}
$mapDays = [];
foreach ($activitiesByDate as $date => $dayActivities) {
    $points = [];
    foreach ($dayActivities as $a) {
        $p = $a['place_id'] !== null ? ($placesById[$a['place_id']] ?? null) : null;
        if ($hasCoords($p)) {
            $points[] = ['lat' => (float) $p['latitude'], 'lon' => (float) $p['longitude'], 'title' => $a['title']];
        }
    }
    if (count($points) >= 2) {
        $mapDays[] = ['date' => $date, 'points' => $points];
    }
}
$mapData = ['places' => $mapPlaces, 'days' => $mapDays, 'tileUrl' => getenv('MAP_TILE_URL') ?: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'];

$notice = $_SESSION['trip_place_notice'] ?? null;
$error = $_SESSION['trip_place_error'] ?? null;
unset($_SESSION['trip_place_notice'], $_SESSION['trip_place_error']);

$e = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$osmView = fn($p) => 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string) $p['latitude']) . '&mlon=' . rawurlencode((string) $p['longitude']) . '#map=17/' . rawurlencode((string) $p['latitude']) . '/' . rawurlencode((string) $p['longitude']);
$osmDirections = fn($p) => 'https://www.openstreetmap.org/directions?route=%3B' . rawurlencode($p['latitude'] . ',' . $p['longitude']);
$categoryLabel = fn($c) => ITINERARY_CATEGORIES[$c ?? ''] ?? 'Uncategorized';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Places &amp; map — <?= $e($trip['name']) ?> — TravelEase</title>
    <link rel="icon" href="/assets/images/travelease-logo-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/places.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/pages.css">
</head>
<body>
    <?php $navCurrent = null; require __DIR__ . '/../../includes/partials/app_header.php'; ?>
    <main class="app-page legacy-page" id="main-content" tabindex="-1">
        <?php $tripId = (int) $tripId; $tripTab = 'places'; $tripPageTitle = 'Places & map'; $tripPageLead = null; require __DIR__ . '/../../includes/partials/trip_header.php'; ?>

        <p class="eyebrow"><?= $e($trip['name']) ?> &middot; <?= $e($trip['destination']) ?></p>
        <?php if ($notice): ?><p class="form-notice" role="status"><?= $e($notice) ?></p><?php endif; ?>
        <?php if ($error): ?><p class="form-errors" role="alert"><?= $e($error) ?></p><?php endif; ?>

        <div class="places-layout">
            <section class="places-map-card" aria-labelledby="map-title">
                <h2 id="map-title">Map</h2>
                <?php if ($mapPlaces): ?>
                    <div id="places-map" class="places-map" role="region" aria-label="Map of saved places"></div>
                    <p class="places-note">Lines connect each day's places in itinerary order. They are not a road route or a travel time. Map data &copy; OpenStreetMap contributors.</p>
                <?php else: ?>
                    <p class="empty-state">No saved places with coordinates yet. Places you save from search, or add with coordinates, appear here.</p>
                <?php endif; ?>
            </section>

            <section class="places-search-card" aria-labelledby="search-title" data-search-panel hidden>
                <h2 id="search-title">Find a place</h2>
                <form id="place-search-form" class="place-search-form">
                    <label for="place-q">Search by name or address</label>
                    <div class="place-search-row">
                        <input id="place-q" name="q" minlength="2" maxlength="100" required placeholder="e.g. Fushimi Inari Taisha">
                        <button type="submit">Search</button>
                    </div>
                </form>
                <p class="places-note">Search text is sent to Geoapify to find places. Nothing else about your trip is sent.</p>
                <div id="place-search-status" class="place-search-status" role="status" aria-live="polite"></div>
                <ul id="place-search-results" class="place-results"></ul>
            </section>
            <noscript><p class="places-note">Place search needs JavaScript.<?= $canEdit ? ' You can still add a place manually below.' : '' ?></p></noscript>
        </div>

        <?php if ($canEdit): ?>
        <!-- Filled by the search results' "Save" buttons (JS), then submitted. -->
        <form id="place-save-form" method="post" action="/customer/trips/place_action.php" hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="source" value="geoapify">
            <input type="hidden" name="name"><input type="hidden" name="address">
            <input type="hidden" name="latitude"><input type="hidden" name="longitude">
            <input type="hidden" name="category"><input type="hidden" name="provider_place_id">
        </form>

        <details class="add-item-form">
            <summary>+ Add a place manually</summary>
            <form method="post" action="/customer/trips/place_action.php">
                <?= csrf_field() ?>
                <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="source" value="manual">
                <label for="new-name">Name</label>
                <input id="new-name" name="name" required maxlength="150">
                <label for="new-address">Address (optional)</label>
                <input id="new-address" name="address" maxlength="255">
                <div class="form-row">
                    <div><label for="new-lat">Latitude (optional)</label><input id="new-lat" name="latitude" inputmode="decimal" placeholder="35.0116"></div>
                    <div><label for="new-lon">Longitude (optional)</label><input id="new-lon" name="longitude" inputmode="decimal" placeholder="135.7681"></div>
                </div>
                <label for="new-category">Category (optional)</label>
                <select id="new-category" name="category">
                    <option value="">Uncategorized</option>
                    <?php foreach (ITINERARY_CATEGORIES as $value => $label): ?>
                        <option value="<?= $value ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Save place</button>
            </form>
        </details>
        <?php endif; ?>

        <section class="places-list-card" aria-labelledby="saved-title">
            <h2 id="saved-title">Saved places</h2>
            <?php if (!$places): ?>
                <p class="empty-state">No saved places yet.</p>
            <?php else: ?>
                <ul class="place-list">
                    <?php foreach ($places as $p): ?>
                        <li id="place-<?= (int) $p['id'] ?>">
                            <div class="place-head">
                                <strong><?= $e($p['name']) ?></strong>
                                <span class="itinerary-category"><?= $e($categoryLabel($p['category'])) ?></span>
                            </div>
                            <?php if ($p['address'] !== null && $p['address'] !== ''): ?><p class="place-address"><?= $e($p['address']) ?></p><?php endif; ?>
                            <p class="place-links">
                                <?php if ($hasCoords($p)): ?>
                                    <a href="<?= $e($osmView($p)) ?>" target="_blank" rel="noopener noreferrer">View on OpenStreetMap</a>
                                    <a href="<?= $e($osmDirections($p)) ?>" target="_blank" rel="noopener noreferrer">Directions</a>
                                    <span class="place-nearby" data-place-id="<?= (int) $p['id'] ?>" hidden>
                                        <label class="visually-hidden" for="nearby-cat-<?= (int) $p['id'] ?>">Nearby category</label>
                                        <select id="nearby-cat-<?= (int) $p['id'] ?>">
                                            <?php foreach (ITINERARY_CATEGORIES as $value => $label): ?>
                                                <option value="<?= $value ?>"><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" data-nearby="<?= (int) $p['id'] ?>">Find nearby</button>
                                    </span>
                                <?php else: ?>
                                    <span class="places-note">No coordinates saved, so this place is not on the map.</span>
                                <?php endif; ?>
                            </p>
                            <?php if ($canEdit): ?>
                                <details class="place-edit">
                                    <summary>Edit</summary>
                                    <form method="post" action="/customer/trips/place_action.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="place_id" value="<?= (int) $p['id'] ?>">
                                        <input type="hidden" name="version" value="<?= (int) $p['version'] ?>">
                                        <label for="name-<?= (int) $p['id'] ?>">Name</label>
                                        <input id="name-<?= (int) $p['id'] ?>" name="name" required maxlength="150" value="<?= $e($p['name']) ?>">
                                        <label for="address-<?= (int) $p['id'] ?>">Address (optional)</label>
                                        <input id="address-<?= (int) $p['id'] ?>" name="address" maxlength="255" value="<?= $e($p['address']) ?>">
                                        <div class="form-row">
                                            <div><label for="lat-<?= (int) $p['id'] ?>">Latitude</label><input id="lat-<?= (int) $p['id'] ?>" name="latitude" inputmode="decimal" value="<?= $e($p['latitude']) ?>"></div>
                                            <div><label for="lon-<?= (int) $p['id'] ?>">Longitude</label><input id="lon-<?= (int) $p['id'] ?>" name="longitude" inputmode="decimal" value="<?= $e($p['longitude']) ?>"></div>
                                        </div>
                                        <label for="category-<?= (int) $p['id'] ?>">Category</label>
                                        <select id="category-<?= (int) $p['id'] ?>" name="category">
                                            <option value="">Uncategorized</option>
                                            <?php foreach (ITINERARY_CATEGORIES as $value => $label): ?>
                                                <option value="<?= $value ?>" <?= $p['category'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit">Save changes</button>
                                    </form>
                                    <form method="post" action="/customer/trips/place_action.php" onsubmit="return confirm('Remove this place? Linked activities keep their details.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="place_id" value="<?= (int) $p['id'] ?>">
                                        <input type="hidden" name="version" value="<?= (int) $p['version'] ?>">
                                        <button type="submit" class="link-danger">Remove place</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="places-list-card" aria-labelledby="days-title">
            <h2 id="days-title">Places by day</h2>
            <p class="places-note">Activities in itinerary order with their saved place. This shows the order of visits only, not a road route or travel time.</p>
            <?php if (!$activitiesByDate): ?>
                <p class="empty-state">No activities yet.</p>
            <?php else: ?>
                <?php foreach ($activitiesByDate as $date => $dayActivities): ?>
                    <h3 class="itinerary-day-heading"><?= $e($date) ?></h3>
                    <ol class="day-places">
                        <?php foreach ($dayActivities as $a): ?>
                            <?php $linked = $a['place_id'] !== null ? ($placesById[$a['place_id']] ?? null) : null; ?>
                            <li id="activity-<?= (int) $a['id'] ?>">
                                <span class="itinerary-time"><?= $a['item_time'] !== null ? $e(substr($a['item_time'], 0, 5)) : '' ?></span>
                                <span><?= $e($a['title']) ?></span>
                                <span class="day-place"><?= $linked ? 'at ' . $e($linked['name']) : 'no place linked' ?></span>
                                <?php if ($canEdit && $places): ?>
                                    <form class="link-form" method="post" action="/customer/trips/place_action.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                                        <input type="hidden" name="action" value="link_item">
                                        <input type="hidden" name="item_id" value="<?= (int) $a['id'] ?>">
                                        <input type="hidden" name="expected_place_id" value="<?= (int) ($a['place_id'] ?? 0) ?>">
                                        <label class="visually-hidden" for="link-<?= (int) $a['id'] ?>">Place for <?= $e($a['title']) ?></label>
                                        <select id="link-<?= (int) $a['id'] ?>" name="place_id">
                                            <?php foreach ($places as $p): ?>
                                                <option value="<?= (int) $p['id'] ?>" <?= (int) ($a['place_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= $e($p['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit"><?= $linked ? 'Change place' : 'Link place' ?></button>
                                    </form>
                                    <?php if ($a['place_id'] !== null): ?>
                                        <form class="link-form" method="post" action="/customer/trips/place_action.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="trip_id" value="<?= (int) $tripId ?>">
                                            <input type="hidden" name="action" value="unlink_item">
                                            <input type="hidden" name="item_id" value="<?= (int) $a['id'] ?>">
                                            <input type="hidden" name="expected_place_id" value="<?= (int) $a['place_id'] ?>">
                                            <button type="submit" class="link-danger">Unlink</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>

    <script type="application/json" id="places-data"><?= json_encode($mapData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
    <script>window.TRIP_PLACES = { tripId: <?= (int) $tripId ?>, canEdit: <?= $canEdit ? 'true' : 'false' ?>, csrf: <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> };</script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src="/assets/js/places.js"></script>
</body>
</html>
