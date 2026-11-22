<?php
/** Separate, defined counts; hidden references never affect visible progress. */
function get_trip_progress(PDO $pdo, int $tripId, array $visibleReferences, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM itinerary_items WHERE trip_id = :activity_trip) AS activity_total,
            (SELECT COUNT(*) FROM itinerary_items WHERE trip_id = :completed_trip AND completed_at IS NOT NULL) AS activity_done,
            (SELECT COUNT(*) FROM trip_expense_shares s JOIN trip_expenses e ON e.id = s.expense_id
             WHERE e.trip_id = :expense_trip AND s.traveler_id <> e.payer_id) AS expense_total,
            (SELECT COUNT(*) FROM trip_expense_shares s JOIN trip_expenses e ON e.id = s.expense_id
             WHERE e.trip_id = :settled_trip AND s.traveler_id <> e.payer_id AND s.settled_at IS NOT NULL) AS expense_done,
            (SELECT COUNT(*) FROM trip_reminders WHERE trip_id = :reminder_trip AND user_id = :reminder_user
             AND cancelled_at IS NULL AND due_at <= UTC_TIMESTAMP()) AS reminder_total,
            (SELECT COUNT(*) FROM trip_reminders WHERE trip_id = :ack_trip AND user_id = :ack_user
             AND cancelled_at IS NULL AND due_at <= UTC_TIMESTAMP() AND acknowledged_at IS NOT NULL) AS reminder_done'
    );
    $stmt->execute(['activity_trip' => $tripId, 'completed_trip' => $tripId,
        'expense_trip' => $tripId, 'settled_trip' => $tripId, 'reminder_trip' => $tripId,
        'reminder_user' => $userId, 'ack_trip' => $tripId, 'ack_user' => $userId]);
    $row = $stmt->fetch();
    return [
        'activities' => ['done' => (int) $row['activity_done'], 'total' => (int) $row['activity_total']],
        'references' => ['done' => count(array_filter($visibleReferences, static fn(array $r): bool => $r['status'] === 'confirmed')),
            'total' => count($visibleReferences)],
        'expenses' => ['done' => (int) $row['expense_done'], 'total' => (int) $row['expense_total']],
        'reminders' => ['done' => (int) $row['reminder_done'], 'total' => (int) $row['reminder_total']],
    ];
}
