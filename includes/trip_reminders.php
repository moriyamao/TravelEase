<?php
require_once __DIR__ . '/trip_validation.php';

/** Reject DST gaps; choose the earlier instant when a wall time repeats. */
function reminder_local_to_utc(string $local, string $zone): DateTimeImmutable
{
    if (validate_trip_timezone($zone) !== null || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D', $local)) {
        throw new InvalidArgumentException('Enter a valid reminder date, time and trip time zone.');
    }
    $utc = new DateTimeZone('UTC');
    $wall = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, $utc);
    if (!$wall || $wall->format('Y-m-d\TH:i') !== $local || $local < '1000-01-01T00:00') {
        throw new InvalidArgumentException('Enter a valid reminder date and time.');
    }
    $tz = new DateTimeZone($zone);
    $offsets = array_column($tz->getTransitions($wall->getTimestamp() - 172800, $wall->getTimestamp() + 172800), 'offset');
    $matches = [];
    foreach (array_unique($offsets) as $offset) {
        $candidate = $wall->modify(sprintf('%+d seconds', -$offset));
        if ($candidate->setTimezone($tz)->format('Y-m-d\TH:i') === $local) {
            $matches[] = $candidate->getTimestamp();
        }
    }
    if (!$matches) {
        throw new InvalidArgumentException('That local time does not exist because the clocks change. Choose another time.');
    }
    return $wall->setTimestamp(min($matches));
}

/** Trip membership must be checked by the caller; even owners see only their own. */
function list_trip_reminders(PDO $pdo, int $tripId, int $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM trip_reminders WHERE trip_id = :trip AND user_id = :user ORDER BY due_at DESC, id DESC LIMIT 100');
    $stmt->execute(['trip' => $tripId, 'user' => $userId]);
    return $stmt->fetchAll();
}
