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

$studyDate = trim((string)($_GET['study_date'] ?? date('Y-m-d')));
$dateObj = DateTime::createFromFormat('Y-m-d', $studyDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $studyDate) {
    $studyDate = date('Y-m-d');
}

$periodStart = $studyPeriod === 'morning' ? '00:00:00' : '12:00:00';
$periodEnd = $studyPeriod === 'morning' ? '11:59:59' : '23:59:59';
$from = $studyDate . ' ' . $periodStart;
$to = $studyDate . ' ' . $periodEnd;

$stmt = db_prepare('SELECT e.id, e.event_time, s.name AS site_name, c.checkpoint_code, c.display_name AS checkpoint_name, e.direction, e.plate_raw, e.plate_norm, e.vehicle_type, e.vehicle_color, e.observer_name, e.notes
    FROM traffic_events e
    INNER JOIN sites s ON s.id = e.site_id
    INNER JOIN checkpoints c ON c.id = e.checkpoint_id
    WHERE e.site_id = ? AND e.event_time >= ? AND e.event_time <= ?
    ORDER BY e.event_time ASC, e.id ASC');
$stmt->bind_param('iss', $siteId, $from, $to);
$stmt->execute();
$rows = $stmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$safeSiteName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)(site_by_id($siteId)['name'] ?? ('site_' . $siteId))) ?? ('site_' . $siteId);
$fileName = sprintf(
    'all_events_%s_%s_%s.csv',
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

fputcsv($out, ['event_id', 'event_time_et', 'site', 'checkpoint_code', 'checkpoint_name', 'direction', 'plate_raw', 'plate_normalized', 'vehicle_type', 'vehicle_color', 'observer_username', 'notes']);
foreach ($rows as $row) {
    fputcsv($out, [
        (string)($row['id'] ?? ''),
        (string)($row['event_time'] ?? ''),
        (string)($row['site_name'] ?? ''),
        (string)($row['checkpoint_code'] ?? ''),
        (string)($row['checkpoint_name'] ?? ''),
        (string)($row['direction'] ?? ''),
        (string)($row['plate_raw'] ?? ''),
        (string)($row['plate_norm'] ?? ''),
        (string)($row['vehicle_type'] ?? ''),
        (string)($row['vehicle_color'] ?? ''),
        (string)($row['observer_name'] ?? ''),
        (string)($row['notes'] ?? ''),
    ]);
}
fclose($out);
exit;
