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

function lower_bound_int(array $values, int $target): int
{
    $lo = 0;
    $hi = count($values);
    while ($lo < $hi) {
        $mid = (int)(($lo + $hi) / 2);
        if ((int)$values[$mid] < $target) {
            $lo = $mid + 1;
        } else {
            $hi = $mid;
        }
    }
    return $lo;
}

function upper_bound_int(array $values, int $target): int
{
    $lo = 0;
    $hi = count($values);
    while ($lo < $hi) {
        $mid = (int)(($lo + $hi) / 2);
        if ((int)$values[$mid] <= $target) {
            $lo = $mid + 1;
        } else {
            $hi = $mid;
        }
    }
    return $lo;
}

function build_route_windows(array $distanceMap, float $speedMph, float $bufferMinutes): array
{
    $routesByFrom = [];
    foreach ($distanceMap as $distKey => $distanceMiles) {
        $parts = explode(':', (string)$distKey, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $fromId = (int)$parts[0];
        $toId = (int)$parts[1];
        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            continue;
        }

        $distance = (float)$distanceMiles;
        if (!is_finite($distance) || $distance <= 0.0) {
            continue;
        }
        $expected = expected_minutes($distance, $speedMph);
        if (!is_finite($expected) || $expected <= 0.0) {
            continue;
        }

        $lowMinutes = max(0.0, $expected - $bufferMinutes);
        $highMinutes = $expected + $bufferMinutes;
        if ($highMinutes <= 0.0) {
            continue;
        }

        $routesByFrom[$fromId][] = [
            'to_checkpoint_id' => $toId,
            'distance_miles' => $distance,
            'expected_minutes' => $expected,
            'low_seconds' => (int)floor($lowMinutes * 60.0),
            'high_seconds' => (int)ceil($highMinutes * 60.0),
        ];
    }
    return $routesByFrom;
}

function classify_events(array $events, array $distanceMap, float $speedMph, float $bufferMinutes, int $minConfidence): array
{
    $inEvents = [];
    $allOutEvents = [];
    $outEventsByCheckpoint = [];
    $outTimesByCheckpoint = [];
    $routesByFrom = build_route_windows($distanceMap, $speedMph, $bufferMinutes);

    foreach ($events as $event) {
        $eventTs = strtotime((string)($event['event_time'] ?? ''));
        if ($eventTs === false) {
            continue;
        }
        $dir = (string)($event['direction'] ?? '');
        $wrapped = [
            'event' => $event,
            'ts' => (int)$eventTs,
        ];
        if ($dir === 'In') {
            $inEvents[] = $wrapped;
            continue;
        }
        if ($dir === 'Out') {
            $checkpointId = (int)($event['checkpoint_id'] ?? 0);
            $allOutEvents[] = $wrapped;
            if ($checkpointId > 0) {
                if (!isset($outEventsByCheckpoint[$checkpointId])) {
                    $outEventsByCheckpoint[$checkpointId] = [];
                    $outTimesByCheckpoint[$checkpointId] = [];
                }
                $outEventsByCheckpoint[$checkpointId][] = $wrapped;
                $outTimesByCheckpoint[$checkpointId][] = (int)$eventTs;
            }
        }
    }

    $candidates = [];
    foreach ($inEvents as $inWrap) {
        $in = $inWrap['event'];
        $inTs = (int)$inWrap['ts'];
        $inCheckpointId = (int)($in['checkpoint_id'] ?? 0);
        if ($inCheckpointId <= 0 || !isset($routesByFrom[$inCheckpointId])) {
            continue;
        }

        foreach ($routesByFrom[$inCheckpointId] as $route) {
            $outCheckpointId = (int)$route['to_checkpoint_id'];
            if (!isset($outEventsByCheckpoint[$outCheckpointId], $outTimesByCheckpoint[$outCheckpointId])) {
                continue;
            }
            $outList = $outEventsByCheckpoint[$outCheckpointId];
            $outTimes = $outTimesByCheckpoint[$outCheckpointId];
            $lowTs = $inTs + (int)$route['low_seconds'];
            $highTs = $inTs + (int)$route['high_seconds'];
            if ($highTs < $lowTs) {
                continue;
            }

            $startIdx = lower_bound_int($outTimes, $lowTs);
            $endIdx = upper_bound_int($outTimes, $highTs);
            for ($i = $startIdx; $i < $endIdx; $i++) {
                $out = $outList[$i]['event'];
                $outTs = (int)$outList[$i]['ts'];
                if ($inCheckpointId === (int)$out['checkpoint_id']) {
                    continue;
                }

                $elapsed = ($outTs - $inTs) / 60.0;
                if ($elapsed <= 0.0) {
                    continue;
                }

                $scoreParts = compute_match_components($in, $out);
                $score = (int)$scoreParts['confidence'];
                if ($score < $minConfidence) {
                    continue;
                }

                $distanceMiles = (float)$route['distance_miles'];
                // Keep full precision for calculations; round only in UI display layers.
                $avgSpeedMph = $elapsed > 0.0 ? ($distanceMiles / ($elapsed / 60.0)) : 0.0;
                $candidates[] = [
                    'in_id' => (int)$in['id'],
                    'out_id' => (int)$out['id'],
                    'elapsed_minutes' => round($elapsed, 2),
                    'expected_minutes' => round((float)$route['expected_minutes'], 2),
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

    $unmatchedIn = [];
    foreach ($inEvents as $inWrap) {
        $inEvent = $inWrap['event'];
        if (!isset($usedIn[(int)$inEvent['id']])) {
            $unmatchedIn[] = $inEvent;
        }
    }

    $unmatchedOut = [];
    foreach ($allOutEvents as $outWrap) {
        $outEvent = $outWrap['event'];
        if (!isset($usedOut[(int)$outEvent['id']])) {
            $unmatchedOut[] = $outEvent;
        }
    }

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
