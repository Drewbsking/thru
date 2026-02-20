<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$siteId = (int)($_POST['site_id'] ?? 0);
$checkpointId = (int)($_POST['checkpoint_id'] ?? 0);
$direction = ($_POST['direction'] ?? '') === 'Out' ? 'Out' : 'In';
$plateRaw = trim((string)($_POST['plate'] ?? ''));
$vehicleType = trim((string)($_POST['vehicle_type'] ?? ''));
$vehicleColor = trim((string)($_POST['vehicle_color'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$observer = trim((string)($_POST['observer_name'] ?? ''));
$eventTime = trim((string)($_POST['event_time'] ?? ''));

if ($siteId <= 0 || $checkpointId <= 0 || $vehicleType === '' || $vehicleColor === '') {
    json_response(['ok' => false, 'error' => 'Missing required fields.'], 422);
}

if ($eventTime === '') {
    $eventTime = date('Y-m-d H:i:s');
} else {
    $parsed = strtotime($eventTime);
    if ($parsed === false) {
        json_response(['ok' => false, 'error' => 'Invalid event time.'], 422);
    }
    $eventTime = date('Y-m-d H:i:s', $parsed);
}

$plateNorm = normalize_plate($plateRaw);

$checkStmt = db_prepare('SELECT c.id FROM checkpoints c INNER JOIN sites s ON s.id = c.site_id WHERE c.id = ? AND c.site_id = ? AND c.is_active = 1 AND s.is_active = 1 LIMIT 1');
$checkStmt->bind_param('ii', $checkpointId, $siteId);
$checkStmt->execute();
$exists = $checkStmt->get_result()?->fetch_assoc();
$checkStmt->close();

if (!$exists) {
    json_response(['ok' => false, 'error' => 'Invalid site/checkpoint.'], 422);
}

$insert = db_prepare('INSERT INTO traffic_events (site_id, checkpoint_id, direction, plate_raw, plate_norm, vehicle_type, vehicle_color, notes, observer_name, event_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insert->bind_param('iissssssss', $siteId, $checkpointId, $direction, $plateRaw, $plateNorm, $vehicleType, $vehicleColor, $notes, $observer, $eventTime);
$ok = $insert->execute();
$newId = $insert->insert_id;
$insert->close();

if (!$ok) {
    json_response(['ok' => false, 'error' => 'Failed to save event.'], 500);
}

json_response(['ok' => true, 'id' => $newId]);
