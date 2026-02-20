<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$siteId = (int)($_GET['site_id'] ?? 0);
$checkpointId = (int)($_GET['checkpoint_id'] ?? 0);
$limit = max(1, min(10, (int)($_GET['limit'] ?? 5)));

if ($siteId <= 0 || $checkpointId <= 0) {
    json_response(['ok' => false, 'error' => 'Site and checkpoint are required.'], 422);
}

$sql = "SELECT id, event_time, direction, plate_raw, vehicle_type, vehicle_color, notes
        FROM traffic_events
        WHERE site_id = ? AND checkpoint_id = ?
        ORDER BY id DESC
        LIMIT {$limit}";
$stmt = db_prepare($sql);
$stmt->bind_param('ii', $siteId, $checkpointId);
$stmt->execute();
$rows = $stmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

json_response(['ok' => true, 'events' => $rows]);
