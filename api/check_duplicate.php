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
$plateNorm = substr($plateNorm, 0, 3);

if ($siteId <= 0 || $checkpointId <= 0 || $vehicleType === '' || $vehicleColor === '') {
    json_response(['ok' => false, 'error' => 'Missing fields.'], 422);
}
if (strlen($plateNorm) !== 3) {
    json_response(['ok' => false, 'error' => 'License plate (first 3 characters) is required.'], 422);
}
if (!can_access_checkpoint($siteId, $checkpointId)) {
    json_response(['ok' => false, 'error' => 'Not authorized for this checkpoint.'], 403);
}

$allowedTypes = ['Sedan', 'SUV', 'Pickup Truck', 'Truck', 'Minivan', 'Motorcycle', 'Other', 'Trailer', 'Trailer/Motorcycle'];
$allowedColors = ['White', 'Black/Blue', 'Gray/Silver', 'Red', 'Green', 'Other'];
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
if (!isset($typeMap[$typeKey]) || !isset($colorMap[$colorKey])) {
    json_response(['ok' => false, 'error' => 'Invalid vehicle type or color.'], 422);
}
$vehicleType = $typeMap[$typeKey];
$vehicleColor = $colorMap[$colorKey];

$threshold = date('Y-m-d H:i:s', time() - 20);
$stmt = db_prepare('SELECT id, event_time, plate_raw FROM traffic_events WHERE site_id = ? AND checkpoint_id = ? AND direction = ? AND vehicle_type = ? AND vehicle_color = ? AND event_time >= ? ORDER BY id DESC LIMIT 1');
$stmt->bind_param('iissss', $siteId, $checkpointId, $direction, $vehicleType, $vehicleColor, $threshold);
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
