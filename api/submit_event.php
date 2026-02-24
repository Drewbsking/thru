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
$observer = current_username();
$userId = current_user_id();

if ($siteId <= 0 || $checkpointId <= 0 || $vehicleType === '' || $vehicleColor === '') {
    json_response(['ok' => false, 'error' => 'Missing required fields.'], 422);
}
$allowedTypes = ['Sedan', 'SUV', 'Pickup Truck', 'Truck', 'Minivan', 'Motorcycle', 'Other', 'Trailer', 'Trailer/Motorcycle'];
$allowedColors = ['White', 'Black/Blue', 'Gray/Silver', 'Red', 'Green', 'Other'];
// Accept case-insensitive values from clients and store canonical labels.
$typeMap = [];
foreach ($allowedTypes as $type) {
    $typeMap[strtolower($type)] = $type;
}
$colorMap = [];
foreach ($allowedColors as $color) {
    $colorMap[strtolower($color)] = $color;
}
$typeKey = strtolower($vehicleType);
$colorKey = strtolower($vehicleColor);
if (!isset($typeMap[$typeKey])) {
    json_response(['ok' => false, 'error' => 'Invalid vehicle type.'], 422);
}
if (!isset($colorMap[$colorKey])) {
    json_response(['ok' => false, 'error' => 'Invalid vehicle color.'], 422);
}
$vehicleType = $typeMap[$typeKey];
$vehicleColor = $colorMap[$colorKey];
if (!can_access_checkpoint($siteId, $checkpointId)) {
    json_response(['ok' => false, 'error' => 'Not authorized for this checkpoint.'], 403);
}

// Event time is always server-side save timestamp.
$eventTime = date('Y-m-d H:i:s');

$plateRaw = normalize_plate($plateRaw);
$plateRaw = substr($plateRaw, 0, 3);
$plateLen = strlen($plateRaw);
if ($plateLen !== 3) {
    json_response(['ok' => false, 'error' => 'License plate (first 3 characters) is required.'], 422);
}
$plateNorm = $plateRaw;

$checkStmt = db_prepare('SELECT c.id FROM checkpoints c WHERE c.id = ? AND c.site_id = ? AND c.is_active = 1 LIMIT 1');
$checkStmt->bind_param('ii', $checkpointId, $siteId);
$checkStmt->execute();
$exists = $checkStmt->get_result()?->fetch_assoc();
$checkStmt->close();

if (!$exists) {
    json_response(['ok' => false, 'error' => 'Invalid site/checkpoint.'], 422);
}

$insert = db_prepare('INSERT INTO traffic_events (site_id, checkpoint_id, user_id, direction, plate_raw, plate_norm, vehicle_type, vehicle_color, notes, observer_name, event_time) VALUES (?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?)');
$insert->bind_param('iiissssssss', $siteId, $checkpointId, $userId, $direction, $plateRaw, $plateNorm, $vehicleType, $vehicleColor, $notes, $observer, $eventTime);
$ok = $insert->execute();
$newId = $insert->insert_id;
$insert->close();

if (!$ok) {
    json_response(['ok' => false, 'error' => 'Failed to save event.'], 500);
}

json_response(['ok' => true, 'id' => $newId, 'event_time' => $eventTime]);
