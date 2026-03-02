<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';

function plate_similarity_score(string $a, string $b): int
{
    if ($a === '' || $b === '') {
        return 0;
    }

    $na = fuzzy_plate_alias(normalize_plate($a));
    $nb = fuzzy_plate_alias(normalize_plate($b));

    if ($na === $nb) {
        return 100;
    }

    $maxLen = max(strlen($na), strlen($nb));
    if ($maxLen === 0) {
        return 0;
    }

    $distance = levenshtein($na, $nb);
    $pct = (int)round(max(0, (1 - ($distance / $maxLen)) * 100));
    return $pct;
}

function expected_minutes(float $distanceMiles, float $speedMph): float
{
    if ($speedMph <= 0.0) {
        return INF;
    }
    return ($distanceMiles / $speedMph) * 60.0;
}

function compute_match_score(array $inEvent, array $outEvent): int
{
    $components = compute_match_components($inEvent, $outEvent);
    return (int)$components['confidence'];
}

function compute_match_components(array $inEvent, array $outEvent): array
{
    $plate = plate_similarity_score((string)($inEvent['plate_norm'] ?? ''), (string)($outEvent['plate_norm'] ?? ''));
    $inType = trim((string)($inEvent['vehicle_type'] ?? ''));
    $outType = trim((string)($outEvent['vehicle_type'] ?? ''));
    $inColor = trim((string)($inEvent['vehicle_color'] ?? ''));
    $outColor = trim((string)($outEvent['vehicle_color'] ?? ''));
    $type = ($inType !== '' && $outType !== '' && strcasecmp($inType, $outType) === 0) ? 100 : 0;
    $color = ($inColor !== '' && $outColor !== '' && strcasecmp($inColor, $outColor) === 0) ? 100 : 0;

    // Weighted confidence: plate has highest influence, type/color stabilize noisy captures.
    $score = (int)round(($plate * 0.65) + ($type * 0.2) + ($color * 0.15));

    // If the normalized plate matches exactly, don't let type/color disagreement drop confidence too low.
    // This prevents perfect plate matches from being excluded when min_confidence is ~70.
    if ($plate === 100) {
        $score = max($score, 80);
    }

    if (($inEvent['plate_norm'] ?? '') === '' && ($outEvent['plate_norm'] ?? '')) {
        $score = max(0, $score - 20);
    }

    $score = max(0, min(100, $score));
    return [
        'plate_score' => $plate,
        'type_score' => $type,
        'color_score' => $color,
        'confidence' => $score,
    ];
}

function classify_events(array $events, array $distanceMap, float $speedMph, float $bufferMinutes, int $minConfidence): array
{
    $inEvents = [];
    $outEvents = [];

    foreach ($events as $event) {
        if (($event['direction'] ?? '') === 'In') {
            $inEvents[] = $event;
        } elseif (($event['direction'] ?? '') === 'Out') {
            $outEvents[] = $event;
        }
    }

    $candidates = [];
    foreach ($inEvents as $in) {
        foreach ($outEvents as $out) {
            if ((int)$in['checkpoint_id'] === (int)$out['checkpoint_id']) {
                continue;
            }

            $elapsed = (strtotime((string)$out['event_time']) - strtotime((string)$in['event_time'])) / 60;
            if ($elapsed <= 0) {
                continue;
            }

            $distKey = $in['checkpoint_id'] . ':' . $out['checkpoint_id'];
            if (!isset($distanceMap[$distKey])) {
                continue;
            }

            $expected = expected_minutes((float)$distanceMap[$distKey], $speedMph);
            $low = max(0.0, $expected - $bufferMinutes);
            $high = $expected + $bufferMinutes;
            if ($elapsed < $low || $elapsed > $high) {
                continue;
            }

            $scoreParts = compute_match_components($in, $out);
            $score = (int)$scoreParts['confidence'];
            $distanceMiles = (float)$distanceMap[$distKey];
            // Keep full precision for calculations; round only in UI display layers.
            $avgSpeedMph = $elapsed > 0 ? ($distanceMiles / ($elapsed / 60)) : 0.0;
            $candidates[] = [
                'in_id' => (int)$in['id'],
                'out_id' => (int)$out['id'],
                'elapsed_minutes' => round($elapsed, 2),
                'expected_minutes' => round($expected, 2),
                'distance_miles' => $distanceMiles,
                'avg_speed_mph' => $avgSpeedMph,
                'confidence' => $score,
                'plate_score' => (int)$scoreParts['plate_score'],
                'type_score' => (int)$scoreParts['type_score'],
                'color_score' => (int)$scoreParts['color_score'],
                'in_event' => $in,
                'out_event' => $out,
            ];
        }
    }

    usort($candidates, static function (array $a, array $b): int {
        if ($a['confidence'] !== $b['confidence']) {
            return $b['confidence'] <=> $a['confidence'];
        }
        return abs($a['elapsed_minutes'] - $a['expected_minutes']) <=> abs($b['elapsed_minutes'] - $b['expected_minutes']);
    });

    $usedIn = [];
    $usedOut = [];
    $matches = [];

    foreach ($candidates as $candidate) {
        if ($candidate['confidence'] < $minConfidence) {
            continue;
        }
        if (isset($usedIn[$candidate['in_id']]) || isset($usedOut[$candidate['out_id']])) {
            continue;
        }
        $usedIn[$candidate['in_id']] = true;
        $usedOut[$candidate['out_id']] = true;
        $matches[] = $candidate;
    }

    $unmatchedIn = array_values(array_filter($inEvents, static fn(array $e): bool => !isset($usedIn[(int)$e['id']])));
    $unmatchedOut = array_values(array_filter($outEvents, static fn(array $e): bool => !isset($usedOut[(int)$e['id']])));

    $totalVolume = count($matches) + count($unmatchedIn) + count($unmatchedOut);
    $cutThroughCount = count($matches);
    $cutThroughPercent = $totalVolume > 0 ? round(($cutThroughCount / $totalVolume) * 100, 2) : 0.0;

    return [
        'matches' => $matches,
        'unmatched_in' => $unmatchedIn,
        'unmatched_out' => $unmatchedOut,
        'total_volume' => $totalVolume,
        'cut_through_count' => $cutThroughCount,
        'cut_through_percent' => $cutThroughPercent,
    ];
}

function match_first_non_empty_string(array $values): string
{
    foreach ($values as $value) {
        $text = trim((string)$value);
        if ($text !== '') {
            return $text;
        }
    }
    return '';
}

function cut_through_vehicle_from_match(array $match): ?array
{
    $inEvent = is_array($match['in_event'] ?? null) ? $match['in_event'] : [];
    $outEvent = is_array($match['out_event'] ?? null) ? $match['out_event'] : [];

    $plateNorm = normalize_plate(match_first_non_empty_string([
        $inEvent['plate_norm'] ?? '',
        $outEvent['plate_norm'] ?? '',
        $inEvent['plate_raw'] ?? '',
        $outEvent['plate_raw'] ?? '',
    ]));
    if ($plateNorm === '') {
        return null;
    }

    $plateLabel = strtoupper(match_first_non_empty_string([
        $inEvent['plate_raw'] ?? '',
        $outEvent['plate_raw'] ?? '',
        $plateNorm,
    ]));
    $vehicleTypeLabel = match_first_non_empty_string([
        $inEvent['vehicle_type'] ?? '',
        $outEvent['vehicle_type'] ?? '',
    ]);
    $vehicleColorLabel = match_first_non_empty_string([
        $inEvent['vehicle_color'] ?? '',
        $outEvent['vehicle_color'] ?? '',
    ]);

    $inCheckpointLabel = match_first_non_empty_string([
        $inEvent['checkpoint_name'] ?? '',
        $inEvent['checkpoint_code'] ?? '',
        'In',
    ]);
    $outCheckpointLabel = match_first_non_empty_string([
        $outEvent['checkpoint_name'] ?? '',
        $outEvent['checkpoint_code'] ?? '',
        'Out',
    ]);

    return [
        'vehicle_key' => ((string)($match['in_id'] ?? $inEvent['id'] ?? 0)) . ':' . ((string)($match['out_id'] ?? $outEvent['id'] ?? 0)),
        'vehicle_event' => [
            'plate_norm' => $plateNorm,
            'vehicle_type' => $vehicleTypeLabel,
            'vehicle_color' => $vehicleColorLabel,
        ],
        'vehicle_label' => ($plateLabel !== '' ? $plateLabel : $plateNorm)
            . ' / ' . ($vehicleTypeLabel !== '' ? $vehicleTypeLabel : '--')
            . ' / ' . ($vehicleColorLabel !== '' ? $vehicleColorLabel : '--'),
        'route_label' => $inCheckpointLabel . ' to ' . $outCheckpointLabel,
        'in_time' => trim((string)($inEvent['event_time'] ?? '')),
        'out_time' => trim((string)($outEvent['event_time'] ?? '')),
    ];
}

function analyze_repeat_cut_throughs(array $morningMatches, array $afternoonMatches, int $minConfidence): array
{
    $skippedIncompleteMatchCount = 0;
    $buildPeriodVehicles = static function (array $matches, string $period, int &$skippedIncompleteMatchCount): array {
        $vehicles = [];
        foreach ($matches as $match) {
            $vehicle = cut_through_vehicle_from_match($match);
            if ($vehicle === null) {
                $skippedIncompleteMatchCount++;
                continue;
            }
            $vehicle['period'] = $period;
            $vehicles[] = $vehicle;
        }
        return $vehicles;
    };

    $morningVehicles = $buildPeriodVehicles($morningMatches, 'am', $skippedIncompleteMatchCount);
    $afternoonVehicles = $buildPeriodVehicles($afternoonMatches, 'pm', $skippedIncompleteMatchCount);

    $candidates = [];
    foreach ($morningVehicles as $morningVehicle) {
        foreach ($afternoonVehicles as $afternoonVehicle) {
            $scoreParts = compute_match_components(
                (array)($morningVehicle['vehicle_event'] ?? []),
                (array)($afternoonVehicle['vehicle_event'] ?? [])
            );
            $candidates[] = [
                'am_key' => (string)($morningVehicle['vehicle_key'] ?? ''),
                'pm_key' => (string)($afternoonVehicle['vehicle_key'] ?? ''),
                'am_vehicle_label' => (string)($morningVehicle['vehicle_label'] ?? '--'),
                'pm_vehicle_label' => (string)($afternoonVehicle['vehicle_label'] ?? '--'),
                'am_route_label' => (string)($morningVehicle['route_label'] ?? '--'),
                'pm_route_label' => (string)($afternoonVehicle['route_label'] ?? '--'),
                'am_in_time' => (string)($morningVehicle['in_time'] ?? ''),
                'am_out_time' => (string)($morningVehicle['out_time'] ?? ''),
                'pm_in_time' => (string)($afternoonVehicle['in_time'] ?? ''),
                'pm_out_time' => (string)($afternoonVehicle['out_time'] ?? ''),
                'confidence' => (int)($scoreParts['confidence'] ?? 0),
                'plate_score' => (int)($scoreParts['plate_score'] ?? 0),
                'type_score' => (int)($scoreParts['type_score'] ?? 0),
                'color_score' => (int)($scoreParts['color_score'] ?? 0),
            ];
        }
    }

    usort($candidates, static function (array $a, array $b): int {
        if ((int)$a['confidence'] !== (int)$b['confidence']) {
            return (int)$b['confidence'] <=> (int)$a['confidence'];
        }
        if ((int)$a['plate_score'] !== (int)$b['plate_score']) {
            return (int)$b['plate_score'] <=> (int)$a['plate_score'];
        }
        $aSupport = (int)$a['type_score'] + (int)$a['color_score'];
        $bSupport = (int)$b['type_score'] + (int)$b['color_score'];
        if ($aSupport !== $bSupport) {
            return $bSupport <=> $aSupport;
        }
        $labelCmp = strnatcasecmp((string)($a['am_vehicle_label'] ?? ''), (string)($b['am_vehicle_label'] ?? ''));
        if ($labelCmp !== 0) {
            return $labelCmp;
        }
        return strnatcasecmp((string)($a['pm_vehicle_label'] ?? ''), (string)($b['pm_vehicle_label'] ?? ''));
    });

    $usedMorning = [];
    $usedAfternoon = [];
    $repeatVehicleRows = [];
    foreach ($candidates as $candidate) {
        if ((int)$candidate['confidence'] < $minConfidence) {
            continue;
        }
        $amKey = (string)($candidate['am_key'] ?? '');
        $pmKey = (string)($candidate['pm_key'] ?? '');
        if ($amKey === '' || $pmKey === '' || isset($usedMorning[$amKey]) || isset($usedAfternoon[$pmKey])) {
            continue;
        }
        $usedMorning[$amKey] = true;
        $usedAfternoon[$pmKey] = true;
        $candidate['score_detail'] = 'P:' . (int)$candidate['plate_score']
            . ' T:' . (int)$candidate['type_score']
            . ' C:' . (int)$candidate['color_score'];
        $repeatVehicleRows[] = $candidate;
    }

    return [
        'repeat_vehicle_count' => count($repeatVehicleRows),
        'morning_unique_cut_through_vehicle_count' => count($morningVehicles),
        'afternoon_unique_cut_through_vehicle_count' => count($afternoonVehicles),
        'repeat_vehicle_rows' => $repeatVehicleRows,
        'skipped_incomplete_match_count' => $skippedIncompleteMatchCount,
    ];
}
