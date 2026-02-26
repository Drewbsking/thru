<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$siteId = (int)($_GET['site_id'] ?? current_site_id());
if ($siteId <= 0) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No valid site selected.';
    exit;
}
if (!can_access_site($siteId)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not authorized for this site.';
    exit;
}

$studyPeriod = strtolower(trim((string)($_GET['study_period'] ?? 'morning')));
if (!in_array($studyPeriod, ['morning', 'afternoon'], true)) {
    $studyPeriod = 'morning';
}

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

$loadEvents = static function (int $siteId, string $from, string $to): array {
    $eventStmt = db_prepare('SELECT e.id, e.site_id, e.checkpoint_id, c.display_name AS checkpoint_name, c.checkpoint_code, e.direction, e.plate_raw, e.plate_norm, e.vehicle_type, e.vehicle_color, e.notes, e.observer_name, e.event_time
        FROM traffic_events e
        INNER JOIN checkpoints c ON c.id = e.checkpoint_id
        WHERE e.site_id = ? AND e.event_time >= ? AND e.event_time <= ?
        ORDER BY e.event_time ASC');
    $eventStmt->bind_param('iss', $siteId, $from, $to);
    $eventStmt->execute();
    $rows = $eventStmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
    $eventStmt->close();
    return $rows;
};

$events = $loadEvents($siteId, $from, $to);
if (!$studyDateProvided && count($events) === 0) {
    $latestStmt = db_prepare('SELECT DATE(MAX(event_time)) AS latest_date FROM traffic_events WHERE site_id = ?');
    $latestStmt->bind_param('i', $siteId);
    $latestStmt->execute();
    $latestRow = $latestStmt->get_result()?->fetch_assoc() ?: [];
    $latestStmt->close();
    $latestDate = trim((string)($latestRow['latest_date'] ?? ''));
    if ($latestDate !== '' && $latestDate !== $studyDate) {
        $studyDate = $latestDate;
        $from = $studyDate . ' ' . $periodStart;
        $to = $studyDate . ' ' . $periodEnd;
        $events = $loadEvents($siteId, $from, $to);
    }
}

$distanceMap = distance_map_for_site($siteId);
$speedMph = (float)app_setting('speed_mph', '25');
$bufferMinutes = (float)app_setting('buffer_minutes', '1');
$minConfidence = (int)app_setting('min_confidence', '70');
$analysis = classify_events($events, $distanceMap, $speedMph, $bufferMinutes, $minConfidence);

$safeSiteName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)(site_by_id($siteId)['name'] ?? ('site_' . $siteId))) ?? ('site_' . $siteId);
$fileName = sprintf(
    'matches_%s_%s_%s.csv',
    $safeSiteName,
    $studyDate,
    $studyPeriod
);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');

$out = fopen('php://output', 'wb');
if ($out === false) {
    http_response_code(500);
    exit;
}

fputcsv($out, [
    'in_event_id',
    'out_event_id',
    'in_time_et',
    'in_checkpoint',
    'out_time_et',
    'out_checkpoint',
    'plate_in',
    'plate_out',
    'distance_miles',
    'elapsed_minutes',
    'expected_minutes',
    'avg_speed_mph',
    'confidence',
    'plate_score',
    'type_score',
    'color_score',
    'vehicle_type_in',
    'vehicle_color_in',
    'vehicle_type_out',
    'vehicle_color_out',
]);

foreach (($analysis['matches'] ?? []) as $match) {
    $in = (array)($match['in_event'] ?? []);
    $outEvent = (array)($match['out_event'] ?? []);
    fputcsv($out, [
        (string)($in['id'] ?? ''),
        (string)($outEvent['id'] ?? ''),
        (string)($in['event_time'] ?? ''),
        (string)($in['checkpoint_name'] ?? ''),
        (string)($outEvent['event_time'] ?? ''),
        (string)($outEvent['checkpoint_name'] ?? ''),
        (string)($in['plate_raw'] ?? ''),
        (string)($outEvent['plate_raw'] ?? ''),
        (string)($match['distance_miles'] ?? ''),
        (string)($match['elapsed_minutes'] ?? ''),
        (string)($match['expected_minutes'] ?? ''),
        (string)($match['avg_speed_mph'] ?? ''),
        (string)($match['confidence'] ?? ''),
        (string)($match['plate_score'] ?? ''),
        (string)($match['type_score'] ?? ''),
        (string)($match['color_score'] ?? ''),
        (string)($in['vehicle_type'] ?? ''),
        (string)($in['vehicle_color'] ?? ''),
        (string)($outEvent['vehicle_type'] ?? ''),
        (string)($outEvent['vehicle_color'] ?? ''),
    ]);
}

fclose($out);
exit;
