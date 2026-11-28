<?php
const REFERENCE_KIND_LABELS = [
    'booking' => 'Booking', 'transport' => 'Transport', 'lodging' => 'Lodging',
    'activity' => 'Activity', 'other' => 'Other',
];
const REFERENCE_VISIBILITY_LABELS = [
    'members' => 'All trip members', 'editors' => 'Owner and editors',
    'private' => 'Creator and trip owner only',
];

/** Call only after checking trip membership. Never send these records to AI. */
function list_trip_references(PDO $pdo, int $tripId, string $role, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM trip_references WHERE trip_id = :trip
         AND (visibility = \'members\' OR :is_owner = 1
              OR (visibility = \'editors\' AND :is_editor = 1)
              OR (visibility = \'private\' AND created_by = :user_id))
         ORDER BY id DESC'
    );
    $stmt->execute(['trip' => $tripId, 'is_owner' => (int) ($role === 'owner'),
        'is_editor' => (int) ($role === 'editor'), 'user_id' => $userId]);
    return $stmt->fetchAll();
}

function trip_reference_visible(array $reference, string $role, int $userId): bool
{
    return $role === 'owner' || $reference['visibility'] === 'members'
        || ($reference['visibility'] === 'editors' && $role === 'editor')
        || ($reference['visibility'] === 'private' && (int) $reference['created_by'] === $userId);
}

/** Reject arrays, invalid UTF-8, control characters, and oversized fields. */
function validate_trip_reference(array $input): array
{
    $values = [];
    $errors = [];
    $limits = ['title' => 150, 'provider' => 100, 'confirmation_code' => 150,
        'start_date' => 10, 'end_date' => 10, 'reference_url' => 2048,
        'kind' => 20, 'status' => 20, 'visibility' => 20];
    foreach ($limits as $field => $limit) {
        $value = $input[$field] ?? '';
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            $errors[] = 'Enter valid text for ' . str_replace('_', ' ', $field) . '.';
            $values[$field] = '';
            continue;
        }
        $values[$field] = trim($value);
        if (mb_strlen($values[$field]) > $limit || preg_match('/[\x00-\x1f\x7f]/', $values[$field])) {
            $errors[] = 'Check the length and format of ' . str_replace('_', ' ', $field) . '.';
        }
    }
    if ($values['title'] === '') {
        $errors[] = 'A booking title is required.';
    }
    if (!isset(REFERENCE_KIND_LABELS[$values['kind']])) {
        $errors[] = 'Choose a valid booking type.';
    }
    if (!isset(REFERENCE_VISIBILITY_LABELS[$values['visibility']])) {
        $errors[] = 'Choose who can view this booking.';
    }
    if (!in_array($values['status'], ['pending', 'confirmed'], true)) {
        $errors[] = 'Choose pending or confirmed.';
    }
    foreach (['start_date', 'end_date'] as $field) {
        if ($values[$field] === '') {
            continue;
        }
        $date = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $values[$field])
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $values[$field]) : false;
        if (!$date || $date->format('Y-m-d') !== $values[$field] || $values[$field] < '1000-01-01') {
            $errors[] = 'Enter a valid ' . str_replace('_', ' ', $field) . '.';
        }
    }
    if ($values['start_date'] !== '' && $values['end_date'] !== '' && $values['end_date'] < $values['start_date']) {
        $errors[] = 'End date cannot be before start date.';
    }
    $url = $values['reference_url'];
    if ($url !== '' && (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)
        || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
        || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null)) {
        $errors[] = 'Enter a valid HTTPS booking link without a username or password.';
    }
    return ['values' => $values, 'errors' => $errors];
}
