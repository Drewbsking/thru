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

function cut_through_signature_from_match(array $match): ?array
{
    $inEvent = is_array($match['in_event'] ?? null) ? $match['in_event'] : [];
    $outEvent = is_array($match['out_event'] ?? null) ? $match['out_event'] : [];

    $plateKey = normalize_plate(match_first_non_empty_string([
        $inEvent['plate_norm'] ?? '',
        $outEvent['plate_norm'] ?? '',
        $inEvent['plate_raw'] ?? '',
        $outEvent['plate_raw'] ?? '',
    ]));
    $vehicleTypeLabel = match_first_non_empty_string([
        $inEvent['vehicle_type'] ?? '',
        $outEvent['vehicle_type'] ?? '',
    ]);
    $vehicleColorLabel = match_first_non_empty_string([
        $inEvent['vehicle_color'] ?? '',
        $outEvent['vehicle_color'] ?? '',
    ]);
    $vehicleTypeKey = strtolower($vehicleTypeLabel);
    $vehicleColorKey = strtolower($vehicleColorLabel);

    if ($plateKey === '' || $vehicleTypeKey === '' || $vehicleColorKey === '') {
        return null;
    }

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
        'signature_key' => $plateKey . '|' . $vehicleTypeKey . '|' . $vehicleColorKey,
        'plate_key' => $plateKey,
        'vehicle_type_key' => $vehicleTypeKey,
        'vehicle_color_key' => $vehicleColorKey,
        'signature_label' => $plateKey . ' / ' . $vehicleTypeLabel . ' / ' . $vehicleColorLabel,
        'route_label' => $inCheckpointLabel . ' to ' . $outCheckpointLabel,
        'in_time' => trim((string)($inEvent['event_time'] ?? '')),
        'out_time' => trim((string)($outEvent['event_time'] ?? '')),
    ];
}

function analyze_repeat_cut_throughs(array $morningMatches, array $afternoonMatches): array
{
    $skippedIncompleteMatchCount = 0;

    $buildPeriodGroups = static function (array $matches, int &$skippedIncompleteMatchCount): array {
        $groups = [];
        foreach ($matches as $match) {
            $signature = cut_through_signature_from_match($match);
            if ($signature === null) {
                $skippedIncompleteMatchCount++;
                continue;
            }

            $signatureKey = (string)$signature['signature_key'];
            if (!isset($groups[$signatureKey])) {
                $groups[$signatureKey] = [
                    'signature_key' => $signatureKey,
                    'signature_label' => (string)$signature['signature_label'],
                    'count' => 0,
                    'route_set' => [],
                    'first_in_time' => '',
                    'last_out_time' => '',
                ];
            }

            $groups[$signatureKey]['count']++;
            $groups[$signatureKey]['route_set'][(string)$signature['route_label']] = true;

            $inTime = (string)$signature['in_time'];
            $outTime = (string)$signature['out_time'];
            if ($inTime !== '' && ($groups[$signatureKey]['first_in_time'] === '' || strcmp($inTime, (string)$groups[$signatureKey]['first_in_time']) < 0)) {
                $groups[$signatureKey]['first_in_time'] = $inTime;
            }
            if ($outTime !== '' && ($groups[$signatureKey]['last_out_time'] === '' || strcmp($outTime, (string)$groups[$signatureKey]['last_out_time']) > 0)) {
                $groups[$signatureKey]['last_out_time'] = $outTime;
            }
        }

        foreach ($groups as &$group) {
            $routes = array_keys($group['route_set']);
            usort($routes, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
            $group['routes'] = $routes;
            unset($group['route_set']);
        }
        unset($group);

        return $groups;
    };

    $morningGroups = $buildPeriodGroups($morningMatches, $skippedIncompleteMatchCount);
    $afternoonGroups = $buildPeriodGroups($afternoonMatches, $skippedIncompleteMatchCount);

    $repeatVehicleRows = [];
    foreach ($morningGroups as $signatureKey => $morningGroup) {
        if (!isset($afternoonGroups[$signatureKey])) {
            continue;
        }

        $afternoonGroup = $afternoonGroups[$signatureKey];
        $repeatVehicleRows[] = [
            'signature_key' => (string)$signatureKey,
            'signature_label' => (string)$morningGroup['signature_label'],
            'am_count' => (int)$morningGroup['count'],
            'am_routes' => array_values($morningGroup['routes'] ?? []),
            'am_first_in_time' => (string)($morningGroup['first_in_time'] ?? ''),
            'am_last_out_time' => (string)($morningGroup['last_out_time'] ?? ''),
            'pm_count' => (int)$afternoonGroup['count'],
            'pm_routes' => array_values($afternoonGroup['routes'] ?? []),
            'pm_first_in_time' => (string)($afternoonGroup['first_in_time'] ?? ''),
            'pm_last_out_time' => (string)($afternoonGroup['last_out_time'] ?? ''),
        ];
    }

    usort($repeatVehicleRows, static function (array $a, array $b): int {
        $aTotal = (int)($a['am_count'] ?? 0) + (int)($a['pm_count'] ?? 0);
        $bTotal = (int)($b['am_count'] ?? 0) + (int)($b['pm_count'] ?? 0);
        if ($aTotal !== $bTotal) {
            return $bTotal <=> $aTotal;
        }
        return strnatcasecmp((string)($a['signature_label'] ?? ''), (string)($b['signature_label'] ?? ''));
    });

    return [
        'repeat_vehicle_count' => count($repeatVehicleRows),
        'morning_unique_cut_through_vehicle_count' => count($morningGroups),
        'afternoon_unique_cut_through_vehicle_count' => count($afternoonGroups),
        'repeat_vehicle_rows' => $repeatVehicleRows,
        'skipped_incomplete_match_count' => $skippedIncompleteMatchCount,
    ];
}
