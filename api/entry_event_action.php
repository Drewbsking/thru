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

    $allowedTypes = ['Sedan', 'SUV', 'Truck', 'Minivan', 'Trailer/Motorcycle'];
    $allowedColors = ['White', 'Black/Blue', 'Gray/Silver', 'Red', 'Green', 'Other'];
    if (!in_array($vehicleType, $allowedTypes, true)) {
        json_response(['ok' => false, 'error' => 'Invalid vehicle type.'], 422);
    }
    if (!in_array($vehicleColor, $allowedColors, true)) {
        json_response(['ok' => false, 'error' => 'Invalid vehicle color.'], 422);
    }

    $plate = normalize_plate($plate);
    $plateNorm = $plate;

    $stmt = db_prepare('UPDATE traffic_events SET direction = ?, plate_raw = ?, plate_norm = ?, vehicle_type = ?, vehicle_color = ?, notes = ? WHERE id = ? AND site_id = ? AND checkpoint_id = ?');
    $stmt->bind_param('ssssssiii', $direction, $plate, $plateNorm, $vehicleType, $vehicleColor, $notes, $eventId, $siteId, $checkpointId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    json_response(['ok' => $affected >= 0]);
}

json_response(['ok' => false, 'error' => 'Unknown action'], 422);
