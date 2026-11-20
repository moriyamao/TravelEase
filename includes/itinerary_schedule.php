<?php
/**
 * Schedule warnings for already-authorized itinerary rows. No queries or output.
 * Missing or invalid times are not inferred; completed activities still count.
 *
 * @param list<array{id:int|string, item_date:string, item_time:?string, end_time:?string}> $items
 * @return array<int, list<array{type:string, other_id?:int, other_start?:string, other_end?:string}>>
 */
function itinerary_schedule_warnings(array $items, string $tripStart, string $tripEnd): array
{
    $warnings = [];
    $intervals = [];
    foreach ($items as $item) {
        $id = (int) $item['id'];
        $date = $item['item_date'];
        if ($date < $tripStart || $date > $tripEnd) {
            $warnings[$id][] = ['type' => 'outside_trip'];
        }

        $start = $item['item_time'] ?? '';
        $end = $item['end_time'] ?? '';
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $start)
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $end)) {
            continue;
        }
        // MySQL returns HH:MM:SS; forms and tests can also supply HH:MM.
        $start = strlen($start) === 5 ? $start . ':00' : $start;
        $end = strlen($end) === 5 ? $end . ':00' : $end;
        if ($start < $end) {
            $intervals[$date][] = ['id' => $id, 'start' => $start, 'end' => $end];
        }
    }

    foreach ($intervals as $day) {
        usort($day, fn($a, $b) => [$a['start'], $a['id']] <=> [$b['start'], $b['id']]);
        $count = count($day);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                // Sorted by start, so later activities cannot overlap once
                // this one ends. Equal boundaries are back-to-back, not overlap.
                if ($day[$j]['start'] >= $day[$i]['end']) {
                    break;
                }
                foreach ([[$i, $j], [$j, $i]] as [$from, $other]) {
                    $warnings[$day[$from]['id']][] = [
                        'type' => 'overlap',
                        'other_id' => $day[$other]['id'],
                        'other_start' => substr($day[$other]['start'], 0, 5),
                        'other_end' => substr($day[$other]['end'], 0, 5),
                    ];
                }
            }
        }
    }
    // Pair traversal follows start/id order, so each activity's overlap list
    // is deterministic even when the page has been manually reordered.
    ksort($warnings, SORT_NUMERIC);
    return $warnings;
}
