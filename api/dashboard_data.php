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
        'avg_match_confidence' => $avgMatchConfidence,
        'meets_policy' => $analysis['cut_through_percent'] >= $policyThreshold,
        'local_arrivals_count' => count($analysis['unmatched_in']),
        'local_departures_count' => count($analysis['unmatched_out']),
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
