<?php
require_once __DIR__ . '/trip_validation.php';
require_once __DIR__ . '/itinerary_validation.php';

function expense_amount_minor(mixed $value): int
{
    if (!is_string($value) || !preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/D', trim($value), $parts)) {
        throw new InvalidArgumentException('Enter a positive amount with up to two decimal places.');
    }
    $minor = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    if ($minor === 0) {
        throw new InvalidArgumentException('Every amount must be greater than zero.');
    }
    return $minor;
}

function expense_id(mixed $value): int
{
    if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', $value) || (int) $value > 4294967295) {
        throw new InvalidArgumentException('Choose a valid expense or traveler.');
    }
    return (int) $value;
}

/** @return array<int, int> Traveler ID => amount in hundredths. */
function expense_split(int $total, string $mode, array $ids, array $custom): array
{
    $count = count($ids);
    if ($count === 0 || $count !== count(array_unique($ids))) {
        throw new InvalidArgumentException('Select at least one traveler without duplicates.');
    }
    sort($ids, SORT_NUMERIC);
    if ($mode === 'equal') {
        if ($total < $count) {
            throw new InvalidArgumentException('The expense is too small to give each traveler a positive share.');
        }
        $base = intdiv($total, $count);
        $remainder = $total % $count;
        $shares = [];
        foreach ($ids as $index => $id) {
            $shares[$id] = $base + ($index < $remainder ? 1 : 0);
        }
        return $shares;
    }
    if ($mode !== 'custom') {
        throw new InvalidArgumentException('Choose an equal or custom split.');
    }
    $shares = [];
    foreach ($ids as $id) {
        $shares[$id] = expense_amount_minor($custom[$id] ?? null);
    }
    if (array_sum($shares) !== $total) {
        throw new InvalidArgumentException('Custom shares must add up to the expense amount.');
    }
    return $shares;
}

function expense_travelers(PDO $pdo, int $tripId): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name FROM trips t
         JOIN users u ON u.id = t.user_id OR EXISTS (
             SELECT 1 FROM trip_memberships m
             WHERE m.trip_id = t.id AND m.user_id = u.id AND m.role <> \'owner\'
         )
         WHERE t.id = :trip_id AND u.role = \'customer\'
         ORDER BY u.name, u.id'
    );
    $stmt->execute(['trip_id' => $tripId]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Outstanding amounts only. Positive means receivable, negative means owed. */
function expense_balances(array $joinedRows): array
{
    $balances = [];
    foreach ($joinedRows as $row) {
        $currency = $row['currency'];
        $payer = (int) $row['payer_id'];
        $traveler = (int) $row['traveler_id'];
        $balances[$currency][$payer] ??= ['name' => $row['payer_name'], 'net_minor' => 0];
        $balances[$currency][$traveler] ??= ['name' => $row['traveler_name'], 'net_minor' => 0];
        if ($payer === $traveler || $row['settled_at'] !== null) {
            continue;
        }
        $amount = (int) $row['amount_minor'];
        $balances[$currency][$payer]['net_minor'] += $amount;
        $balances[$currency][$traveler]['net_minor'] -= $amount;
    }
    ksort($balances);
    return $balances;
}
