<?php
require_once __DIR__ . '/trip_reminders.php';
require_once __DIR__ . '/trip_membership.php';
require_once __DIR__ . '/transactional_mail.php';

function reminder_activity_start(array $item, string $zone): DateTimeImmutable
{
    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D', (string) $item['item_time'])) {
        throw new InvalidArgumentException('Activity has no valid start time.');
    }
    return reminder_local_to_utc($item['item_date'] . 'T' . substr($item['item_time'], 0, 5), $zone)
        ->modify('+' . (int) substr($item['item_time'], 6, 2) . ' seconds');
}

/** Seed the next 48 hours. Repeated runs skip unchanged rows without extra writes. */
function sync_automatic_reminders(PDO $pdo, DateTimeImmutable $now, float $deadline): int
{
    $from = $now->modify('-1 day')->format('Y-m-d');
    $to = $now->modify('+3 days')->format('Y-m-d');
    $stmt = $pdo->prepare('SELECT DISTINCT trip_id FROM itinerary_items WHERE item_date BETWEEN :from_date AND :to_date AND item_time IS NOT NULL AND completed_at IS NULL ORDER BY trip_id');
    $stmt->execute(['from_date' => $from, 'to_date' => $to]);
    $trips = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $changed = 0;
    foreach ($trips as $tripId) {
        if (microtime(true) >= $deadline) { break; }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT time_zone, status FROM trips WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $tripId]);
        $trip = $stmt->fetch();
        if (!$trip || !in_array($trip['status'], ['planning', 'confirmed'], true)) {
            $pdo->commit(); continue;
        }
        $stmt = $pdo->prepare('SELECT id, title, item_date, item_time FROM itinerary_items WHERE trip_id = :trip AND item_date BETWEEN :from_date AND :to_date AND item_time IS NOT NULL AND completed_at IS NULL');
        $stmt->execute(['trip' => $tripId, 'from_date' => $from, 'to_date' => $to]);
        $items = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT u.id FROM users u JOIN trips t ON t.id = :trip
            WHERE u.role = \'customer\' AND (u.id = t.user_id OR EXISTS (
                SELECT 1 FROM trip_memberships m WHERE m.trip_id = t.id AND m.user_id = u.id
                AND m.role IN (\'editor\', \'commenter\', \'viewer\'))) LOCK IN SHARE MODE');
        $stmt->execute(['trip' => $tripId]);
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare('SELECT * FROM trip_reminders WHERE trip_id = :trip AND itinerary_item_id IS NOT NULL FOR UPDATE');
        $stmt->execute(['trip' => $tripId]);
        $existing = [];
        foreach ($stmt->fetchAll() as $r) { $existing[$r['itinerary_item_id']][$r['user_id']] = $r; }
        foreach ($items as $item) {
            try { $start = reminder_activity_start($item, $trip['time_zone']); }
            catch (InvalidArgumentException $e) { continue; }
            if ($start <= $now || $start > $now->modify('+48 hours')) { continue; }
            $due = $start->modify('-30 minutes')->format('Y-m-d H:i:s');
            $startText = $start->format('Y-m-d H:i:s');
            foreach ($users as $userId) {
                if (microtime(true) >= $deadline) { break 2; }
                $old = $existing[$item['id']][$userId] ?? null;
                if ($old) {
                    if ($old['cancelled_at'] !== null || $old['acknowledged_at'] !== null
                        || $old['in_app_processed_at'] !== null || $old['email_status'] !== 'pending'
                        || ($old['due_at'] === $due && $old['title'] === $item['title'] && $old['activity_start_at'] === $startText)) { continue; }
                    $stmt = $pdo->prepare('UPDATE trip_reminders SET title = :title, due_at = :due, activity_start_at = :start, version = version + 1 WHERE id = :id');
                    $stmt->execute(['title' => $item['title'], 'due' => $due, 'start' => $startText, 'id' => $old['id']]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO trip_reminders (trip_id, user_id, itinerary_item_id, title, due_at, activity_start_at) VALUES (:trip, :user, :item, :title, :due, :start)');
                    $stmt->execute(['trip' => $tripId, 'user' => $userId, 'item' => $item['id'], 'title' => $item['title'], 'due' => $due, 'start' => $startText]);
                }
                $changed++;
            }
        }
        $pdo->commit();
    }
    return $changed;
}

/** One process per database server. No database locks are held during HTTPS calls. */
function run_due_reminders(PDO $pdo, ?DateTimeImmutable $now = null): array
{
    $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    $result = ['busy' => false, 'automatic' => 0, 'processed' => 0, 'emails' => 0, 'email_failures' => 0];
    if ((int) $pdo->query("SELECT GET_LOCK('travelease_reminders', 0)")->fetchColumn() !== 1) {
        $result['busy'] = true; return $result;
    }
    try {
        $deadline = microtime(true) + 20;
        $result['automatic'] = sync_automatic_reminders($pdo, $now, microtime(true) + 8);
        $config = transactional_mail_config();
        $nowText = $now->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare('SELECT id, trip_id FROM trip_reminders
            WHERE due_at <= :now_time AND cancelled_at IS NULL AND acknowledged_at IS NULL
            AND (in_app_processed_at IS NULL OR (email_status = \'pending\' AND :mail_ready = 1))
            ORDER BY due_at, id LIMIT 100');
        $stmt->execute(['now_time' => $nowText, 'mail_ready' => (int) ($config !== null)]);
        $candidates = $stmt->fetchAll();
        $emailClaims = 0;
        foreach ($candidates as $candidate) {
            if (microtime(true) >= $deadline) { break; }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT time_zone, status FROM trips WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $candidate['trip_id']]);
            $trip = $stmt->fetch();
            $stmt = $pdo->prepare('SELECT * FROM trip_reminders WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $candidate['id']]);
            $r = $stmt->fetch();
            if (!$trip || !$r || $r['cancelled_at'] !== null || $r['acknowledged_at'] !== null || $r['due_at'] > $nowText) {
                $pdo->commit(); continue;
            }
            $stmt = $pdo->prepare('SELECT role, email, name, email_verified_at, notify_in_app, notify_email FROM users WHERE id = :id LOCK IN SHARE MODE');
            $stmt->execute(['id' => $r['user_id']]);
            $user = $stmt->fetch();
            $eligible = $user && $user['role'] === 'customer'
                && in_array($trip['status'], ['planning', 'confirmed'], true)
                && trip_member_role($pdo, (int) $r['trip_id'], (int) $r['user_id']) !== null;
            if ($eligible && $r['itinerary_item_id'] !== null) {
                $stmt = $pdo->prepare('SELECT item_date, item_time, title, completed_at FROM itinerary_items WHERE id = :id AND trip_id = :trip');
                $stmt->execute(['id' => $r['itinerary_item_id'], 'trip' => $r['trip_id']]);
                $item = $stmt->fetch();
                try { $start = $item ? reminder_activity_start($item, $trip['time_zone']) : null; }
                catch (InvalidArgumentException $e) { $start = null; }
                $eligible = $item && $item['completed_at'] === null && $start !== null && $start > $now;
                if ($eligible && $start->format('Y-m-d H:i:s') !== $r['activity_start_at']) {
                    if ($r['in_app_processed_at'] !== null) {
                        $stmt = $pdo->prepare('UPDATE trip_reminders SET email_status = \'skipped\', version = version + 1 WHERE id = :id');
                        $stmt->execute(['id' => $r['id']]);
                        $pdo->commit(); continue;
                    }
                    $r['due_at'] = $start->modify('-30 minutes')->format('Y-m-d H:i:s');
                    $stmt = $pdo->prepare('UPDATE trip_reminders SET due_at = :due, activity_start_at = :start, title = :title, version = version + 1 WHERE id = :id');
                    $stmt->execute(['due' => $r['due_at'], 'start' => $start->format('Y-m-d H:i:s'), 'title' => $item['title'], 'id' => $r['id']]);
                    if ($r['due_at'] > $nowText) { $pdo->commit(); continue; }
                }
            }
            if (!$eligible) {
                $stmt = $pdo->prepare('UPDATE trip_reminders SET cancelled_at = :now_time, version = version + 1 WHERE id = :id');
                $stmt->execute(['now_time' => $nowText, 'id' => $r['id']]);
                $pdo->commit(); continue;
            }
            if ($r['in_app_processed_at'] === null) {
                if ((int) $user['notify_in_app'] === 1) {
                    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, trip_id, event_type, reminder_id)
                        VALUES (:user, :trip, \'reminder_due\', :reminder)
                        ON DUPLICATE KEY UPDATE reminder_id = VALUES(reminder_id)');
                    $stmt->execute(['user' => $r['user_id'], 'trip' => $r['trip_id'], 'reminder' => $r['id']]);
                }
                $stmt = $pdo->prepare('UPDATE trip_reminders SET in_app_processed_at = :processed,
                    in_app_delivered_at = :delivered, version = version + 1 WHERE id = :id');
                $stmt->execute(['processed' => $nowText, 'delivered' => (int) $user['notify_in_app'] === 1 ? $nowText : null, 'id' => $r['id']]);
            }
            $send = false;
            $attempt = (int) $r['email_attempts'] + 1;
            if ($r['email_status'] === 'pending') {
                if (!(int) $user['notify_email'] || $user['email_verified_at'] === null) {
                    $stmt = $pdo->prepare('UPDATE trip_reminders SET email_status = \'skipped\', version = version + 1 WHERE id = :id');
                    $stmt->execute(['id' => $r['id']]);
                } elseif ($config !== null && $emailClaims < 3 && $attempt <= 3) {
                    // Durable claim before HTTPS: after a crash, do not retry
                    // automatically and risk sending the same email twice.
                    $stmt = $pdo->prepare('UPDATE trip_reminders SET email_status = \'unknown\', email_attempts = :attempt,
                        email_attempted_at = :now_time, version = version + 1 WHERE id = :id');
                    $stmt->execute(['attempt' => $attempt, 'now_time' => $nowText, 'id' => $r['id']]);
                    $send = true; $emailClaims++;
                }
            }
            $pdo->commit();
            $result['processed']++;
            if ($send) {
                $status = send_transactional_email($user['email'], $user['name'], 'Your TravelEase reminder is due',
                    "A personal trip reminder is due. Sign in to TravelEase to review it:\n"
                    . $config['base_url'] . '/customer/trips/reminders.php?id=' . (int) $r['trip_id'] . "\n", 5);
                $stmt = $pdo->prepare('UPDATE trip_reminders SET email_status = :status, version = version + 1
                    WHERE id = :id AND email_status = \'unknown\' AND email_attempts = :attempt');
                $stmt->execute(['status' => $status, 'id' => $r['id'], 'attempt' => $attempt]);
                $result[$status === 'sent' ? 'emails' : 'email_failures']++;
            }
        }
        return $result;
    } finally {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $pdo->query("SELECT RELEASE_LOCK('travelease_reminders')")->fetchColumn();
    }
}
