<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$siteId = (int)($_GET['site_id'] ?? current_site_id());
if ($siteId <= 0) {
    json_response(['ok' => false, 'error' => 'No active site configured.'], 422);
}
if (!can_access_site($siteId)) {
    json_response(['ok' => false, 'error' => 'Not authorized for this site.'], 403);
}

$studyPeriod = strtolower(trim((string)($_GET['study_period'] ?? 'morning')));
if (!in_array($studyPeriod, ['morning', 'afternoon'], true)) {
    $studyPeriod = 'morning';
}
$includeAllEvents = ((string)($_GET['include_all_events'] ?? '0')) === '1';

$studyDateProvided = isset($_GET['study_date']) && trim((string)$_GET['study_date']) !== '';
$requestedStudyDate = trim((string)($_GET['study_date'] ?? date('Y-m-d')));
$studyDate = $requestedStudyDate;
$dateObj = DateTime::createFromFormat('Y-m-d', $studyDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $studyDate) {
    $studyDate = date('Y-m-d');
}

$periodStart = $studyPeriod === 'morning' ? '00:00:00' : '12:00:00';
$periodEnd = $studyPeriod === 'morning' ? '11:59:59' : '23:59:59';
$from = $studyDate . ' ' . $periodStart;
$to = $studyDate . ' ' . $periodEnd;

$speedMph = (float)app_setting('speed_mph', '25');
$bufferMinutes = (float)app_setting('buffer_minutes', '1');
$minConfidence = (int)app_setting('min_confidence', '70');
$policyThreshold = (float)app_setting('policy_cut_through_percent', '25');

$checkpointStmt = db_prepare('SELECT id, checkpoint_code, display_name FROM checkpoints WHERE site_id = ? AND is_active = 1 ORDER BY CAST(checkpoint_code AS UNSIGNED) ASC, checkpoint_code ASC');
$checkpointStmt->bind_param('i', $siteId);
$checkpointStmt->execute();
$checkpointList = $checkpointStmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
$checkpointStmt->close();

$loadEvents = static function (int $siteId, string $from, string $to): array {
    $eventStmt = db_prepare('SELECT e.id, e.site_id, e.checkpoint_id, c.display_name AS checkpoint_name, c.checkpoint_code, e.direction, e.plate_raw, e.plate_norm, e.vehicle_type, e.vehicle_color, e.notes, e.observer_name, e.event_time FROM traffic_events e INNER JOIN checkpoints c ON c.id = e.checkpoint_id WHERE e.site_id = ? AND e.event_time >= ? AND e.event_time <= ? ORDER BY e.event_time ASC');
    $eventStmt->bind_param('iss', $siteId, $from, $to);
    $eventStmt->execute();
    $rows = $eventStmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
    $eventStmt->close();
    return $rows;
};

$loadSessionComments = static function (int $siteId, string $studyDate, string $studyPeriod): array {
    $commentStmt = db_prepare('SELECT spc.id, spc.site_id, spc.checkpoint_id, spc.user_id, spc.study_date, spc.study_period, spc.comment_text, spc.created_at, spc.updated_at,
        c.checkpoint_code, c.display_name AS checkpoint_name, u.username AS collector_username
        FROM study_period_comments spc
        INNER JOIN checkpoints c ON c.id = spc.checkpoint_id
        INNER JOIN users u ON u.id = spc.user_id
        WHERE spc.site_id = ? AND spc.study_date = ? AND spc.study_period = ?
        ORDER BY CAST(c.checkpoint_code AS UNSIGNED) ASC, c.checkpoint_code ASC, u.username ASC');
    $commentStmt->bind_param('iss', $siteId, $studyDate, $studyPeriod);
    $commentStmt->execute();
    $rows = $commentStmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
    $commentStmt->close();

    foreach ($rows as &$row) {
        $checkpointName = trim((string)($row['checkpoint_name'] ?? ''));
        $checkpointCode = trim((string)($row['checkpoint_code'] ?? ''));
        $row['checkpoint_label'] = $checkpointName !== '' && $checkpointCode !== ''
            ? $checkpointName . ' (' . $checkpointCode . ')'
            : ($checkpointName !== '' ? $checkpointName : $checkpointCode);
        $row['id'] = (int)($row['id'] ?? 0);
        $row['site_id'] = (int)($row['site_id'] ?? 0);
        $row['checkpoint_id'] = (int)($row['checkpoint_id'] ?? 0);
        $row['user_id'] = (int)($row['user_id'] ?? 0);
    }
    unset($row);

    return $rows;
};

$latestDateWithPeriodData = static function (int $siteId, string $studyPeriod): ?string {
    $periodStart = $studyPeriod === 'morning' ? '00:00:00' : '12:00:00';
    $periodEnd = $studyPeriod === 'morning' ? '11:59:59' : '23:59:59';

    $eventLatestStmt = db_prepare('SELECT MAX(DATE(event_time)) AS latest_date
        FROM traffic_events
        WHERE site_id = ? AND TIME(event_time) >= ? AND TIME(event_time) <= ?');
    $eventLatestStmt->bind_param('iss', $siteId, $periodStart, $periodEnd);
    $eventLatestStmt->execute();
    $eventLatest = trim((string)($eventLatestStmt->get_result()?->fetch_assoc()['latest_date'] ?? ''));
    $eventLatestStmt->close();

    $commentLatestStmt = db_prepare('SELECT MAX(study_date) AS latest_date
        FROM study_period_comments
        WHERE site_id = ? AND study_period = ?');
    $commentLatestStmt->bind_param('is', $siteId, $studyPeriod);
    $commentLatestStmt->execute();
    $commentLatest = trim((string)($commentLatestStmt->get_result()?->fetch_assoc()['latest_date'] ?? ''));
    $commentLatestStmt->close();

    if ($eventLatest === '' && $commentLatest === '') {
        return null;
    }
    if ($eventLatest === '') {
        return $commentLatest;
    }
    if ($commentLatest === '') {
        return $eventLatest;
    }
    return strcmp($eventLatest, $commentLatest) >= 0 ? $eventLatest : $commentLatest;
};

$events = $loadEvents($siteId, $from, $to);
$sessionComments = $loadSessionComments($siteId, $studyDate, $studyPeriod);
if (!$studyDateProvided && count($events) === 0 && count($sessionComments) === 0) {
    $latestDate = $latestDateWithPeriodData($siteId, $studyPeriod);
    if ($latestDate !== null && $latestDate !== $studyDate) {
        $studyDate = $latestDate;
        $from = $studyDate . ' ' . $periodStart;
        $to = $studyDate . ' ' . $periodEnd;
        $events = $loadEvents($siteId, $from, $to);
        $sessionComments = $loadSessionComments($siteId, $studyDate, $studyPeriod);
    }
}

$distanceMap = distance_map_for_site($siteId);
$analysis = classify_events($events, $distanceMap, $speedMph, $bufferMinutes, $minConfidence);
$nonCutThroughAnalysis = analyze_non_cut_through_in_out_pairs(
    $analysis['unmatched_in'] ?? [],
    $analysis['unmatched_out'] ?? [],
    $distanceMap,
    $speedMph,
    $bufferMinutes,
    $minConfidence
);
$avgMatchConfidence = 0.0;
if (count($analysis['matches']) > 0) {
    $confidenceSum = 0.0;
    foreach ($analysis['matches'] as $match) {
        $confidenceSum += (float)($match['confidence'] ?? 0);
    }
    $avgMatchConfidence = round($confidenceSum / count($analysis['matches']), 2);
}

$checkpointCounts = [];
$checkpointCountsById = [];
foreach ($events as $event) {
    $cpId = (int)$event['checkpoint_id'];
    $cp = (string)$event['checkpoint_name'];
    if (!isset($checkpointCounts[$cp])) {
        $checkpointCounts[$cp] = ['in' => 0, 'out' => 0, 'total' => 0];
    }
    if (!isset($checkpointCountsById[$cpId])) {
        $checkpointCountsById[$cpId] = [
            'checkpoint_id' => $cpId,
            'checkpoint_name' => $cp,
            'in' => 0,
            'out' => 0,
            'total' => 0,
        ];
    }
    $dir = strtolower((string)$event['direction']);
    if ($dir === 'in') {
        $checkpointCounts[$cp]['in']++;
        $checkpointCountsById[$cpId]['in']++;
    } elseif ($dir === 'out') {
        $checkpointCounts[$cp]['out']++;
        $checkpointCountsById[$cpId]['out']++;
    }
    $checkpointCounts[$cp]['total']++;
    $checkpointCountsById[$cpId]['total']++;
}

$checkpointTotalsById = [];
$highestCheckpointTwoWay = 0;
foreach ($checkpointCountsById as $cpId => $row) {
    $total = (int)($row['total'] ?? 0);
    $checkpointTotalsById[(int)$cpId] = $total;
    if ($total > $highestCheckpointTwoWay) {
        $highestCheckpointTwoWay = $total;
    }
}

$cutThroughOverHighestTwoWayPercent = $highestCheckpointTwoWay > 0
    ? round((((int)$analysis['cut_through_count']) / $highestCheckpointTwoWay) * 100, 2)
    : 0.0;

$legGroups = [];
foreach ($analysis['matches'] as $match) {
    $inEvent = is_array($match['in_event'] ?? null) ? $match['in_event'] : [];
    $outEvent = is_array($match['out_event'] ?? null) ? $match['out_event'] : [];
    $inCheckpointId = (int)($inEvent['checkpoint_id'] ?? 0);
    $outCheckpointId = (int)($outEvent['checkpoint_id'] ?? 0);
    $inLabel = trim((string)($inEvent['checkpoint_name'] ?? $inEvent['checkpoint_code'] ?? 'In'));
    $outLabel = trim((string)($outEvent['checkpoint_name'] ?? $outEvent['checkpoint_code'] ?? 'Out'));
    if ($inLabel === '') {
        $inLabel = 'In';
    }
    if ($outLabel === '') {
        $outLabel = 'Out';
    }
    $routeLabel = $inLabel . ' to ' . $outLabel;
    $groupKey = $inCheckpointId . ':' . $outCheckpointId . ':' . $routeLabel;
    if (!isset($legGroups[$groupKey])) {
        $legGroups[$groupKey] = [
            'route' => $routeLabel,
            'in_checkpoint_id' => $inCheckpointId,
            'out_checkpoint_id' => $outCheckpointId,
            'count' => 0,
        ];
    }
    $legGroups[$groupKey]['count']++;
}

$maxLegPolicyPercent = 0.0;
$maxLegPolicyRoute = '';
$maxLegPolicyCount = 0;
$maxLegPolicyDenominator = 0;
foreach ($legGroups as $leg) {
    $legCount = (int)$leg['count'];
    $inTotal = (int)($checkpointTotalsById[(int)$leg['in_checkpoint_id']] ?? 0);
    $outTotal = (int)($checkpointTotalsById[(int)$leg['out_checkpoint_id']] ?? 0);
    $legDenominator = max($inTotal, $outTotal);
    $legPercent = $legDenominator > 0 ? round(($legCount / $legDenominator) * 100, 2) : 0.0;
    $isHigherPercent = $legPercent > $maxLegPolicyPercent;
    $isEqualPercent = $legPercent === $maxLegPolicyPercent;
    $isHigherCount = $legCount > $maxLegPolicyCount;
    $isLexicographicallyFirstRoute = strcmp((string)$leg['route'], $maxLegPolicyRoute) < 0;
    if ($isHigherPercent || ($isEqualPercent && ($isHigherCount || ($legCount === $maxLegPolicyCount && $isLexicographicallyFirstRoute)))) {
        $maxLegPolicyPercent = $legPercent;
        $maxLegPolicyRoute = (string)$leg['route'];
        $maxLegPolicyCount = $legCount;
        $maxLegPolicyDenominator = $legDenominator;
    }
}

$recent = array_reverse(array_slice($events, -50));
$firstEventTime = count($events) > 0 ? (string)$events[0]['event_time'] : null;
$lastEventTime = count($events) > 0 ? (string)$events[count($events) - 1]['event_time'] : null;
$vehiclesPerHour = 0.0;
$studyDurationMinutes = 0.0;
if ($firstEventTime !== null && $lastEventTime !== null) {
    $firstTs = strtotime($firstEventTime);
    $lastTs = strtotime($lastEventTime);
    if ($firstTs !== false && $lastTs !== false && $lastTs > $firstTs) {
        $studyDurationMinutes = round(($lastTs - $firstTs) / 60, 2);
        $hours = ($lastTs - $firstTs) / 3600;
        if ($hours > 0) {
            $vehiclesPerHour = round(((float)$analysis['total_volume']) / $hours, 2);
        }
    }
}

json_response([
    'ok' => true,
    'site_id' => $siteId,
    'study_period' => $studyPeriod,
    'requested_study_date' => $requestedStudyDate,
    'study_date' => $studyDate,
    'settings' => [
        'speed_mph' => $speedMph,
        'buffer_minutes' => $bufferMinutes,
        'min_confidence' => $minConfidence,
        'poll_seconds' => (int)app_setting('poll_seconds', '10'),
        'policy_cut_through_percent' => $policyThreshold,
    ],
    'summary' => [
        'event_count' => count($events),
        'start_time' => $firstEventTime,
        'end_time' => $lastEventTime,
        'total_volume' => $analysis['total_volume'],
        'vehicles_per_hour' => $vehiclesPerHour,
        'study_duration_minutes' => $studyDurationMinutes,
        'cut_through_count' => $analysis['cut_through_count'],
        'cut_through_percent' => $analysis['cut_through_percent'],
        'highest_checkpoint_two_way' => $highestCheckpointTwoWay,
        'cut_through_over_highest_two_way_percent' => $cutThroughOverHighestTwoWayPercent,
        'max_leg_policy_percent' => $maxLegPolicyPercent,
        'max_leg_policy_route' => $maxLegPolicyRoute,
        'max_leg_policy_count' => $maxLegPolicyCount,
        'max_leg_policy_denominator' => $maxLegPolicyDenominator,
        'policy_basis' => 'max_leg_endpoint_ratio',
        'avg_match_confidence' => $avgMatchConfidence,
        'meets_policy' => $maxLegPolicyPercent >= $policyThreshold,
        'local_arrivals_count' => count($analysis['unmatched_in']),
        'local_departures_count' => count($analysis['unmatched_out']),
        'same_checkpoint_in_out_count' => (int)($nonCutThroughAnalysis['same_checkpoint_count'] ?? 0),
        'different_checkpoint_outside_window_count' => (int)($nonCutThroughAnalysis['different_checkpoint_outside_window_count'] ?? 0),
    ],
    'checkpoints' => $checkpointList,
    'checkpoint_counts' => $checkpointCounts,
    'checkpoint_counts_by_id' => array_values($checkpointCountsById),
    'matches' => $analysis['matches'],
    'unmatched_in' => $analysis['unmatched_in'],
    'unmatched_out' => $analysis['unmatched_out'],
    'recent_events' => $recent,
    'all_events' => $includeAllEvents ? $events : [],
    'session_comments' => $sessionComments,
]);
