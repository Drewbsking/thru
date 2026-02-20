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
    $plate = plate_similarity_score((string)($inEvent['plate_norm'] ?? ''), (string)($outEvent['plate_norm'] ?? ''));
    $type = strcasecmp((string)$inEvent['vehicle_type'], (string)$outEvent['vehicle_type']) === 0 ? 100 : 0;
    $color = strcasecmp((string)$inEvent['vehicle_color'], (string)$outEvent['vehicle_color']) === 0 ? 100 : 0;

    // Weighted confidence: plate has highest influence, type/color stabilize noisy captures.
    $score = (int)round(($plate * 0.65) + ($type * 0.2) + ($color * 0.15));

    if (($inEvent['plate_norm'] ?? '') === '' && ($outEvent['plate_norm'] ?? '')) {
        $score = max(0, $score - 20);
    }

    return max(0, min(100, $score));
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

            $score = compute_match_score($in, $out);
            $distanceMiles = (float)$distanceMap[$distKey];
            $avgSpeedMph = $elapsed > 0 ? round($distanceMiles / ($elapsed / 60), 2) : 0.0;
            $candidates[] = [
                'in_id' => (int)$in['id'],
                'out_id' => (int)$out['id'],
                'elapsed_minutes' => round($elapsed, 2),
                'expected_minutes' => round($expected, 2),
                'distance_miles' => $distanceMiles,
                'avg_speed_mph' => $avgSpeedMph,
                'confidence' => $score,
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
