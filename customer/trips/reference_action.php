<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/trip_membership.php';
require_once __DIR__ . '/../../includes/trip_activity.php';
require_once __DIR__ . '/../../includes/trip_references.php';

require_role(['customer']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}
$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || !csrf_verify($token)) {
    http_response_code(403);
    exit('Your session expired. Reload the page and try again.');
}
$tripId = filter_var($_POST['trip_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
$action = $_POST['action'] ?? null;
if (!$tripId || !in_array($action, ['create', 'update', 'delete'], true)) {
    http_response_code(400);
    exit('Choose a valid trip and reference action.');
}
$pdo = get_db_connection();
require_trip_permission($pdo, $tripId, 'edit_itinerary');
unset($_SESSION['reference_error'][$tripId], $_SESSION['reference_notice'][$tripId]);
try {
    $pdo->beginTransaction();
    $role = lock_trip_and_require_permission($pdo, $tripId, 'edit_itinerary');
    if ($action !== 'create') {
        $referenceId = filter_var($_POST['reference_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        $version = filter_var($_POST['version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        if (!$referenceId || !$version) {
            throw new InvalidArgumentException('Choose a valid reference and version.');
        }
        $stmt = $pdo->prepare('SELECT * FROM trip_references WHERE id = :id AND trip_id = :trip FOR UPDATE');
        $stmt->execute(['id' => $referenceId, 'trip' => $tripId]);
        $reference = $stmt->fetch();
        if (!$reference || !trip_reference_visible($reference, $role, (int) current_user_id())) {
            throw new OutOfBoundsException('Reference not found.');
        }
        if ($version !== (int) $reference['version']) {
            throw new DomainException('This reference changed. Reload and try again.');
        }
    }
    if ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM trip_references WHERE id = :id AND trip_id = :trip AND version = :version');
        $stmt->execute(['id' => $referenceId, 'trip' => $tripId, 'version' => $version]);
        $event = 'reference_deleted';
    } else {
        $validation = validate_trip_reference($_POST);
        if ($validation['errors']) {
            throw new InvalidArgumentException(implode(' ', $validation['errors']));
        }
        $values = $validation['values'];
        foreach (['provider', 'confirmation_code', 'start_date', 'end_date', 'reference_url'] as $field) {
            if ($values[$field] === '') {
                $values[$field] = null;
            }
        }
        $values['trip'] = $tripId;
        if ($action === 'create') {
            $values['creator'] = current_user_id();
            $stmt = $pdo->prepare(
                'INSERT INTO trip_references (trip_id, created_by, title, kind, provider, confirmation_code,
                 start_date, end_date, reference_url, status, visibility)
                 VALUES (:trip, :creator, :title, :kind, :provider, :confirmation_code,
                 :start_date, :end_date, :reference_url, :status, :visibility)'
            );
            $event = 'reference_added';
        } else {
            $values['id'] = $referenceId;
            $values['version'] = $version;
            $stmt = $pdo->prepare(
                'UPDATE trip_references SET title = :title, kind = :kind, provider = :provider,
                 confirmation_code = :confirmation_code, start_date = :start_date, end_date = :end_date,
                 reference_url = :reference_url, status = :status, visibility = :visibility,
                 version = version + 1 WHERE id = :id AND trip_id = :trip AND version = :version'
            );
            $event = 'reference_updated';
        }
        $stmt->execute($values);
    }
    if ($stmt->rowCount() !== 1) {
        throw new DomainException('This reference changed. Reload and try again.');
    }
    // Trip history is visible to all members. Never put reference content there.
    log_trip_activity($pdo, $tripId, $event);
    $pdo->commit();
    $_SESSION['reference_notice'][$tripId] = 'Reference saved.';
} catch (OutOfBoundsException | DomainException $e) {
    $pdo->rollBack();
    http_response_code($e instanceof OutOfBoundsException ? 404 : 409);
    exit($e->getMessage());
} catch (InvalidArgumentException $e) {
    $pdo->rollBack();
    $_SESSION['reference_error'][$tripId] = $e->getMessage();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TravelEase: reference write failed (database code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
    $_SESSION['reference_error'][$tripId] = 'Could not save the reference. Please try again.';
}
header('Location: /customer/trips/references.php?id=' . $tripId);
exit;
