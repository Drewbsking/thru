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
    $plate = trim((string)($_POST['plate_raw'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $plate = normalize_plate($plate);
    $plateNorm = $plate;

    $stmt = db_prepare('UPDATE traffic_events SET plate_raw = ?, plate_norm = ?, notes = ? WHERE id = ? AND site_id = ? AND checkpoint_id = ?');
    $stmt->bind_param('sssiii', $plate, $plateNorm, $notes, $eventId, $siteId, $checkpointId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    json_response(['ok' => $affected >= 0]);
}

json_response(['ok' => false, 'error' => 'Unknown action'], 422);
