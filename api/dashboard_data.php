<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$siteId = (int)($_GET['site_id'] ?? current_site_id());
if ($siteId <= 0) {
    json_response(['ok' => false, 'error' => 'No active site configured.'], 422);
}

$hours = max(1, min(168, (int)($_GET['hours'] ?? 24)));
$from = date('Y-m-d H:i:s', time() - ($hours * 3600));

$speedMph = (float)app_setting('speed_mph', '25');
$bufferMinutes = (float)app_setting('buffer_minutes', '1');
$minConfidence = (int)app_setting('min_confidence', '70');
$policyThreshold = (float)app_setting('policy_cut_through_percent', '25');

$eventStmt = db_prepare('SELECT e.id, e.site_id, e.checkpoint_id, c.display_name AS checkpoint_name, c.checkpoint_code, e.direction, e.plate_raw, e.plate_norm, e.vehicle_type, e.vehicle_color, e.notes, e.observer_name, e.event_time FROM traffic_events e INNER JOIN checkpoints c ON c.id = e.checkpoint_id WHERE e.site_id = ? AND e.event_time >= ? ORDER BY e.event_time ASC');
$eventStmt->bind_param('is', $siteId, $from);
$eventStmt->execute();
$events = $eventStmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
$eventStmt->close();

$distanceMap = distance_map_for_site($siteId);
$analysis = classify_events($events, $distanceMap, $speedMph, $bufferMinutes, $minConfidence);

$checkpointCounts = [];
foreach ($events as $event) {
    $cp = (string)$event['checkpoint_name'];
    if (!isset($checkpointCounts[$cp])) {
        $checkpointCounts[$cp] = ['in' => 0, 'out' => 0, 'total' => 0];
    }
    $dir = strtolower((string)$event['direction']);
    if ($dir === 'in') {
        $checkpointCounts[$cp]['in']++;
    } elseif ($dir === 'out') {
        $checkpointCounts[$cp]['out']++;
    }
    $checkpointCounts[$cp]['total']++;
}

$recent = array_reverse(array_slice($events, -50));

json_response([
    'ok' => true,
    'site_id' => $siteId,
    'hours' => $hours,
    'settings' => [
        'speed_mph' => $speedMph,
        'buffer_minutes' => $bufferMinutes,
        'min_confidence' => $minConfidence,
        'poll_seconds' => (int)app_setting('poll_seconds', '10'),
        'policy_cut_through_percent' => $policyThreshold,
    ],
    'summary' => [
        'event_count' => count($events),
        'total_volume' => $analysis['total_volume'],
        'cut_through_count' => $analysis['cut_through_count'],
        'cut_through_percent' => $analysis['cut_through_percent'],
        'meets_policy' => $analysis['cut_through_percent'] >= $policyThreshold,
        'local_arrivals_count' => count($analysis['unmatched_in']),
        'local_departures_count' => count($analysis['unmatched_out']),
    ],
    'checkpoint_counts' => $checkpointCounts,
    'matches' => $analysis['matches'],
    'unmatched_in' => $analysis['unmatched_in'],
    'unmatched_out' => $analysis['unmatched_out'],
    'recent_events' => $recent,
]);
