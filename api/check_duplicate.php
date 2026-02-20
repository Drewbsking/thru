<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$siteId = (int)($_POST['site_id'] ?? 0);
$checkpointId = (int)($_POST['checkpoint_id'] ?? 0);
$direction = ($_POST['direction'] ?? '') === 'Out' ? 'Out' : 'In';
$vehicleType = trim((string)($_POST['vehicle_type'] ?? ''));
$vehicleColor = trim((string)($_POST['vehicle_color'] ?? ''));
$plateNorm = normalize_plate((string)($_POST['plate'] ?? ''));

if ($siteId <= 0 || $checkpointId <= 0 || $vehicleType === '' || $vehicleColor === '') {
    json_response(['ok' => false, 'error' => 'Missing fields.'], 422);
}

$stmt = db_prepare('SELECT id, event_time, plate_raw FROM traffic_events WHERE site_id = ? AND checkpoint_id = ? AND direction = ? AND vehicle_type = ? AND vehicle_color = ? AND event_time >= DATE_SUB(NOW(), INTERVAL 20 SECOND) ORDER BY id DESC LIMIT 1');
$stmt->bind_param('iisss', $siteId, $checkpointId, $direction, $vehicleType, $vehicleColor);
$stmt->execute();
$row = $stmt->get_result()?->fetch_assoc();
$stmt->close();

if (!$row) {
    json_response(['ok' => true, 'duplicate' => false]);
}

$existingPlateNorm = normalize_plate((string)($row['plate_raw'] ?? ''));
$plateMatch = $plateNorm !== '' && $existingPlateNorm !== '' ? $plateNorm === $existingPlateNorm : true;

json_response([
    'ok' => true,
    'duplicate' => $plateMatch,
    'latest' => $row,
]);
