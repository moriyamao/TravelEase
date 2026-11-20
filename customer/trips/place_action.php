<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_activity.php';
require_once __DIR__ . '/../../includes/trip_places.php';

require_role(['customer']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}
if (!csrf_verify(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    http_response_code(403);
    exit('Your session expired. Reload the page and try again.');
}
try {
    $tripId = place_positive_id($_POST, 'trip_id');
    $action = place_post_string($_POST, 'action');
    if (!in_array($action, ['create', 'update', 'delete', 'link_item', 'unlink_item'], true)) {
        throw new InvalidArgumentException('Invalid place action.');
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    exit($e->getMessage());
}
$pdo = get_db_connection();
require_trip_permission($pdo, $tripId, 'edit_itinerary');

try {
    $pdo->beginTransaction();
    lock_trip_and_require_permission($pdo, $tripId, 'edit_itinerary');
    $place = null;
    if ($action !== 'create' && $action !== 'unlink_item') {
        $placeId = place_positive_id($_POST, 'place_id');
        $stmt = $pdo->prepare('SELECT * FROM trip_places WHERE id = :id AND trip_id = :trip_id FOR UPDATE');
        $stmt->execute(['id' => $placeId, 'trip_id' => $tripId]);
        $place = $stmt->fetch();
        if (!$place) {
            $pdo->rollBack();
            http_response_code(404);
            exit('Place not found.');
        }
    }
    if ($action === 'create' || $action === 'update') {
        $values = validate_trip_place($_POST + ($place === null ? [] : [
            'source' => $place['source'], 'provider_place_id' => $place['provider_place_id'] ?? '',
        ]));
        if ($action === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO trip_places (trip_id, name, address, latitude, longitude, category, source, provider_place_id, created_by)
                 VALUES (:trip_id, :name, :address, :latitude, :longitude, :category, :source, :provider_place_id, :created_by)'
            );
            $stmt->execute($values + ['trip_id' => $tripId, 'created_by' => current_user_id()]);
            log_trip_activity($pdo, $tripId, 'place_added', null, $values['name']);
        } else {
            if (place_positive_id($_POST, 'version') !== (int) $place['version']) {
                throw new DomainException('This place changed. Reload the page before saving.');
            }
            // A repeated unchanged form is harmless and creates no history.
            $changed = false;
            foreach ($values as $key => $value) {
                if ($value !== $place[$key]) {
                    $changed = true;
                    break;
                }
            }
            if ($changed) {
                $stmt = $pdo->prepare(
                    'UPDATE trip_places SET name = :name, address = :address, latitude = :latitude, longitude = :longitude,
                     category = :category, source = :source, provider_place_id = :provider_place_id, version = version + 1
                     WHERE id = :id AND trip_id = :trip_id'
                );
                $stmt->execute($values + ['id' => $placeId, 'trip_id' => $tripId]);
                log_trip_activity($pdo, $tripId, 'place_updated', null, $values['name']);
            }
        }
    } elseif ($action === 'delete') {
        if (place_positive_id($_POST, 'version') !== (int) $place['version']) {
            throw new DomainException('This place changed. Reload the page before deleting it.');
        }
        $stmt = $pdo->prepare('DELETE FROM trip_places WHERE id = :id AND trip_id = :trip_id');
        $stmt->execute(['id' => $placeId, 'trip_id' => $tripId]);
        log_trip_activity($pdo, $tripId, 'place_deleted', null, $place['name']);
    } else {
        $itemId = place_positive_id($_POST, 'item_id');
        $expectedPlaceId = place_positive_id($_POST, 'expected_place_id', true);
        $stmt = $pdo->prepare('SELECT title, place_id FROM itinerary_items WHERE id = :id AND trip_id = :trip_id FOR UPDATE');
        $stmt->execute(['id' => $itemId, 'trip_id' => $tripId]);
        $item = $stmt->fetch();
        if (!$item) {
            $pdo->rollBack();
            http_response_code(404);
            exit('Activity not found.');
        }
        if ((int) ($item['place_id'] ?? 0) !== $expectedPlaceId) {
            throw new DomainException('The activity\'s place changed. Reload the page before changing its link.');
        }
        $newPlaceId = $action === 'link_item' ? $placeId : null;
        if ((int) ($item['place_id'] ?? 0) !== (int) ($newPlaceId ?? 0)) {
            $stmt = $pdo->prepare('UPDATE itinerary_items SET place_id = :place_id WHERE id = :id AND trip_id = :trip_id');
            $stmt->execute(['place_id' => $newPlaceId, 'id' => $itemId, 'trip_id' => $tripId]);
            log_trip_activity($pdo, $tripId, $newPlaceId === null ? 'place_unlinked' : 'place_linked', null, $item['title']);
        }
    }
    $pdo->commit();
    $_SESSION['trip_place_notice'] = 'Place changes saved.';
} catch (DomainException $e) {
    $pdo->rollBack();
    http_response_code(409);
    exit($e->getMessage());
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['trip_place_error'] = $e->getMessage();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TravelEase: saved-place write failed (database code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    $_SESSION['trip_place_error'] = 'Could not save the place. Please try again.';
}
header('Location: /customer/trips/places.php?id=' . $tripId);
exit;
