<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$siteId = (int)($_GET['site_id'] ?? current_site_id());
if ($siteId <= 0) {
    json_response(['ok' => false, 'error' => 'No active site configured.'], 422);
}

$studyPeriod = strtolower(trim((string)($_GET['study_period'] ?? 'morning')));
if (!in_array($studyPeriod, ['morning', 'afternoon'], true)) {
    $studyPeriod = 'morning';
}

$studyDate = trim((string)($_GET['study_date'] ?? date('Y-m-d')));
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

$eventStmt = db_prepare('SELECT e.id, e.site_id, e.checkpoint_id, c.display_name AS checkpoint_name, c.checkpoint_code, e.direction, e.plate_raw, e.plate_norm, e.vehicle_type, e.vehicle_color, e.notes, e.observer_name, e.event_time FROM traffic_events e INNER JOIN checkpoints c ON c.id = e.checkpoint_id WHERE e.site_id = ? AND e.event_time >= ? AND e.event_time <= ? ORDER BY e.event_time ASC');
$eventStmt->bind_param('iss', $siteId, $from, $to);
$eventStmt->execute();
$events = $eventStmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
$eventStmt->close();

$distanceMap = distance_map_for_site($siteId);
$analysis = classify_events($events, $distanceMap, $speedMph, $bufferMinutes, $minConfidence);

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

json_response([
    'ok' => true,
    'site_id' => $siteId,
    'study_period' => $studyPeriod,
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
        'cut_through_count' => $analysis['cut_through_count'],
        'cut_through_percent' => $analysis['cut_through_percent'],
        'meets_policy' => $analysis['cut_through_percent'] >= $policyThreshold,
        'local_arrivals_count' => count($analysis['unmatched_in']),
        'local_departures_count' => count($analysis['unmatched_out']),
    ],
    'checkpoint_counts' => $checkpointCounts,
    'checkpoint_counts_by_id' => array_values($checkpointCountsById),
    'matches' => $analysis['matches'],
    'unmatched_in' => $analysis['unmatched_in'],
    'unmatched_out' => $analysis['unmatched_out'],
    'recent_events' => $recent,
]);
