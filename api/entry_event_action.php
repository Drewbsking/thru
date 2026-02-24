<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$action = (string)($_POST['action'] ?? '');
$eventId = (int)($_POST['event_id'] ?? 0);
$siteId = (int)($_POST['site_id'] ?? 0);
$checkpointId = (int)($_POST['checkpoint_id'] ?? 0);

if ($eventId <= 0 || $siteId <= 0 || $checkpointId <= 0) {
    json_response(['ok' => false, 'error' => 'Missing ids.'], 422);
}
if (!can_access_checkpoint($siteId, $checkpointId)) {
    json_response(['ok' => false, 'error' => 'Not authorized for this checkpoint.'], 403);
}

$eventStmt = db_prepare('SELECT id, user_id FROM traffic_events WHERE id = ? AND site_id = ? AND checkpoint_id = ? LIMIT 1');
$eventStmt->bind_param('iii', $eventId, $siteId, $checkpointId);
$eventStmt->execute();
$eventRow = $eventStmt->get_result()?->fetch_assoc();
$eventStmt->close();
if (!$eventRow) {
    json_response(['ok' => false, 'error' => 'Event not found.'], 404);
}
$eventUserId = (int)($eventRow['user_id'] ?? 0);
if (!can_modify_event($eventUserId)) {
    json_response(['ok' => false, 'error' => 'You can only edit your own events.'], 403);
}

if ($action === 'delete') {
    $stmt = db_prepare('DELETE FROM traffic_events WHERE id = ? AND site_id = ? AND checkpoint_id = ? LIMIT 1');
    $stmt->bind_param('iii', $eventId, $siteId, $checkpointId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    json_response(['ok' => $affected > 0]);
}

if ($action === 'edit') {
    $direction = (($_POST['direction'] ?? 'In') === 'Out') ? 'Out' : 'In';
    $plate = trim((string)($_POST['plate_raw'] ?? ''));
    $vehicleType = trim((string)($_POST['vehicle_type'] ?? ''));
    $vehicleColor = trim((string)($_POST['vehicle_color'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

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
    if ($vehicleType !== '' && !isset($typeMap[$typeKey])) {
        json_response(['ok' => false, 'error' => 'Invalid vehicle type.'], 422);
    }
    if ($vehicleColor !== '' && !isset($colorMap[$colorKey])) {
        json_response(['ok' => false, 'error' => 'Invalid vehicle color.'], 422);
    }
    $vehicleType = $vehicleType !== '' ? $typeMap[$typeKey] : '';
    $vehicleColor = $vehicleColor !== '' ? $colorMap[$colorKey] : '';

    $plate = normalize_plate($plate);
    $plate = substr($plate, 0, 3);
    $plateLen = strlen($plate);
    if ($plateLen !== 3) {
        json_response(['ok' => false, 'error' => 'License plate (first 3 characters) is required.'], 422);
    }
    $plateNorm = $plate;

    $stmt = db_prepare('UPDATE traffic_events SET direction = ?, plate_raw = ?, plate_norm = ?, vehicle_type = ?, vehicle_color = ?, notes = ? WHERE id = ? AND site_id = ? AND checkpoint_id = ?');
    $stmt->bind_param('ssssssiii', $direction, $plate, $plateNorm, $vehicleType, $vehicleColor, $notes, $eventId, $siteId, $checkpointId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    json_response(['ok' => $affected >= 0]);
}

json_response(['ok' => false, 'error' => 'Unknown action'], 422);
