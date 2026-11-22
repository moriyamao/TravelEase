<?php
/**
 * FR-34: export of a user's own data and the rules for deleting an account.
 *
 * The export contains what the user can already see in TravelEase: their
 * account and settings, the trips they own or belong to (as their role
 * shows them), and their own comments, votes, reminders, notifications,
 * invitations and support requests. It never includes password hashes,
 * Google account IDs, tokens, other members' email addresses, or booking
 * references and reminders the user could not see.
 */
require_once __DIR__ . '/trip_membership.php';
require_once __DIR__ . '/itinerary_validation.php';
require_once __DIR__ . '/trip_references.php';
require_once __DIR__ . '/trip_documents.php';
require_once __DIR__ . '/notifications.php';

function account_export_rows(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function account_export_money(?int $minor): ?string
{
    return $minor === null ? null : number_format($minor / 100, 2, '.', '');
}

function build_account_export(PDO $pdo, int $userId): array
{
    $user = account_export_rows($pdo,
        'SELECT name, email, role, created_at, email_verified_at, google_subject IS NOT NULL AS google_linked,
                profile_picture, notify_in_app, notify_email
         FROM users WHERE id = :id', ['id' => $userId])[0] ?? null;
    if ($user === null) {
        throw new RuntimeException('Account not found.');
    }

    $trips = [];
    $tripRows = account_export_rows($pdo,
        'SELECT t.id, t.name, t.destination, t.start_date, t.end_date, t.status, t.budget_currency, t.budget_amount,
                t.notes, t.time_zone, t.created_at
         FROM trips t
         WHERE t.user_id = :owner_id OR EXISTS (
             SELECT 1 FROM trip_memberships m WHERE m.trip_id = t.id AND m.user_id = :member_id)
         ORDER BY t.start_date, t.id', ['owner_id' => $userId, 'member_id' => $userId]);
    foreach ($tripRows as $trip) {
        $tripId = (int) $trip['id'];
        $role = trip_member_role($pdo, $tripId, $userId);
        if ($role === null) {
            continue;
        }
        $expenses = account_export_rows($pdo,
            'SELECT id, title, category, currency, amount_minor, payer_name, split_mode, receipt_url, created_at
             FROM trip_expenses WHERE trip_id = :trip ORDER BY created_at, id', ['trip' => $tripId]);
        foreach ($expenses as &$expense) {
            $expense['amount'] = account_export_money((int) $expense['amount_minor']);
            $expense['shares'] = array_map(fn($s) => [
                'traveler' => $s['traveler_name'], 'amount' => account_export_money((int) $s['amount_minor']),
                'settled_at' => $s['settled_at'],
            ], account_export_rows($pdo,
                'SELECT traveler_name, amount_minor, settled_at FROM trip_expense_shares WHERE expense_id = :id ORDER BY traveler_name',
                ['id' => $expense['id']]));
            unset($expense['id'], $expense['amount_minor']);
        }
        unset($expense);
        $visibleReferences = list_trip_references($pdo, $tripId, $role, $userId);
        $documents = $user['role'] === 'customer' ? list_reference_documents($pdo, $visibleReferences) : [];
        $references = array_map(fn($r) => array_intersect_key($r, array_flip([
            'title', 'kind', 'provider', 'confirmation_code', 'start_date', 'end_date', 'reference_url', 'status', 'visibility', 'created_at',
        ])) + ['attachments' => array_map(fn($d) => array_intersect_key($d, array_flip([
            'original_name', 'mime_type', 'byte_size', 'created_at',
        ])), $documents[$r['id']] ?? [])], $visibleReferences);
        $memberCount = (int) account_export_rows($pdo,
            'SELECT COUNT(*) AS c FROM trip_memberships WHERE trip_id = :trip', ['trip' => $tripId])[0]['c'];

        unset($trip['id']);
        $trips[] = $trip + [
            'your_role' => $role,
            'member_count' => max(1, $memberCount),
            'itinerary' => account_export_rows($pdo,
                'SELECT item_date, item_time, end_time, title, category, location, notes, estimated_cost, actual_cost, completed_at
                 FROM itinerary_items WHERE trip_id = :trip ORDER BY item_date, ' . ITINERARY_DAY_ORDER_SQL, ['trip' => $tripId]),
            'places' => account_export_rows($pdo,
                'SELECT name, address, latitude, longitude, category FROM trip_places WHERE trip_id = :trip ORDER BY name, id',
                ['trip' => $tripId]),
            'booking_references' => $references,
            'expenses' => $expenses,
        ];
    }

    return [
        'format' => 'TravelEase account export, version 1',
        'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'note' => 'Times are in UTC unless a trip time zone is shown. Other members\' email addresses and anything you cannot see in TravelEase are not included.',
        'account' => [
            'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role'],
            'created_at' => $user['created_at'], 'email_verified_at' => $user['email_verified_at'],
            'google_sign_in_linked' => (bool) $user['google_linked'], 'profile_picture' => $user['profile_picture'],
            'notifications' => ['in_app' => (bool) $user['notify_in_app'], 'reminder_email' => (bool) $user['notify_email']],
        ],
        'trips' => $trips,
        'share_links_created' => account_export_rows($pdo,
            'SELECT id, trip_id, kind, created_at, expires_at, revoked_at FROM trip_share_links WHERE created_by = :user ORDER BY id',
            ['user' => $userId]),
        'your_comments' => account_export_rows($pdo,
            'SELECT t.name AS trip, i.title AS activity, c.body, c.created_at
             FROM itinerary_item_comments c JOIN itinerary_items i ON i.id = c.itinerary_item_id JOIN trips t ON t.id = i.trip_id
             WHERE c.user_id = :user ORDER BY c.created_at, c.id', ['user' => $userId]),
        'your_votes' => array_map(fn($v) => ['trip' => $v['trip'], 'activity' => $v['activity'], 'vote' => (int) $v['vote'] > 0 ? 'up' : 'down'],
            account_export_rows($pdo,
                'SELECT t.name AS trip, i.title AS activity, v.vote
                 FROM itinerary_item_votes v JOIN itinerary_items i ON i.id = v.itinerary_item_id JOIN trips t ON t.id = i.trip_id
                 WHERE v.user_id = :user ORDER BY t.name, i.item_date', ['user' => $userId])),
        'your_reminders' => account_export_rows($pdo,
            'SELECT t.name AS trip, r.title, r.due_at, r.itinerary_item_id IS NOT NULL AS automatic, r.acknowledged_at, r.cancelled_at, r.email_status
             FROM trip_reminders r JOIN trips t ON t.id = r.trip_id WHERE r.user_id = :user ORDER BY r.due_at, r.id', ['user' => $userId]),
        'your_notifications' => array_map(fn($n) => [
                'message' => notification_sentence($n['event_type']), 'created_at' => $n['created_at'],
                'read_at' => $n['read_at'], 'dismissed_at' => $n['dismissed_at'],
            ], account_export_rows($pdo,
                'SELECT event_type, created_at, read_at, dismissed_at FROM notifications WHERE user_id = :user ORDER BY id', ['user' => $userId])),
        'invitations_received' => account_export_rows($pdo,
            'SELECT role, status, created_at, responded_at FROM trip_invitations WHERE invitee_id = :user ORDER BY created_at', ['user' => $userId]),
        'support_requests' => array_map(fn($e) => [
                'opened_at' => $e['created_at'], 'status' => $e['status'], 'resolved_at' => $e['resolved_at'],
                'messages' => account_export_rows($pdo,
                    'SELECT sender_type AS sender, message, created_at FROM escalation_messages WHERE escalation_id = :id ORDER BY created_at, id',
                    ['id' => $e['id']]),
            ], account_export_rows($pdo,
                'SELECT id, created_at, status, resolved_at FROM escalations WHERE user_id = :user ORDER BY created_at', ['user' => $userId])),
        'trips_shared_with_support' => account_export_rows($pdo,
            'SELECT t.name AS trip, s.shared_at, s.revoked_at FROM support_trip_shares s JOIN trips t ON t.id = s.trip_id
             WHERE s.shared_by = :user ORDER BY s.shared_at', ['user' => $userId]),
    ];
}

/**
 * Trips that block deleting this account: trips the user owns that have
 * other members. Pass $lock = true inside the deletion transaction.
 * @return array<int, string> trip id => name
 */
function account_deletion_blockers(PDO $pdo, int $userId, bool $lock = false): array
{
    $stmt = $pdo->prepare(
        'SELECT t.id, t.name FROM trips t
         WHERE t.user_id = :owner_id AND EXISTS (
             SELECT 1 FROM trip_memberships m WHERE m.trip_id = t.id AND m.user_id <> :member_id)
         ORDER BY t.start_date, t.id' . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute(['owner_id' => $userId, 'member_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}
