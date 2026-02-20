<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/utils.php';

ensure_schema();
if (!is_authenticated()) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

$siteId = current_site_id();
if ($siteId <= 0) {
    http_response_code(422);
    echo 'No active site configured';
    exit;
}

$location = trim((string)($_POST['location'] ?? ''));
$vehicleType = trim((string)($_POST['vehicle_type'] ?? ''));
$vehicleColor = trim((string)($_POST['vehicle_color'] ?? ''));
$direction = ($_POST['in_out'] ?? '') === 'Out' ? 'Out' : 'In';
$plate = trim((string)($_POST['plate'] ?? ''));
$notes = trim((string)($_POST['comments'] ?? ''));

if ($vehicleType === '' || $vehicleColor === '') {
    http_response_code(422);
    echo 'Missing required fields';
    exit;
}

$codeMap = [
    'Site 1' => 'CP1',
    'Site 2' => 'CP2',
    'Site 3' => 'CP3',
];
$targetCode = $codeMap[$location] ?? '';

$cpStmt = $targetCode !== ''
    ? db_prepare('SELECT id FROM checkpoints WHERE site_id = ? AND checkpoint_code = ? LIMIT 1')
    : db_prepare('SELECT id FROM checkpoints WHERE site_id = ? ORDER BY id ASC LIMIT 1');

if ($targetCode !== '') {
    $cpStmt->bind_param('is', $siteId, $targetCode);
} else {
    $cpStmt->bind_param('i', $siteId);
}
$cpStmt->execute();
$cp = $cpStmt->get_result()?->fetch_assoc();
$cpStmt->close();

$checkpointId = (int)($cp['id'] ?? 0);
if ($checkpointId <= 0) {
    http_response_code(422);
    echo 'No checkpoint found';
    exit;
}

$plateNorm = normalize_plate($plate);
$now = date('Y-m-d H:i:s');

$insert = db_prepare('INSERT INTO traffic_events (site_id, checkpoint_id, direction, plate_raw, plate_norm, vehicle_type, vehicle_color, notes, observer_name, event_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$observer = '';
$insert->bind_param('iissssssss', $siteId, $checkpointId, $direction, $plate, $plateNorm, $vehicleType, $vehicleColor, $notes, $observer, $now);
$ok = $insert->execute();
$insert->close();

echo $ok ? 'New record created successfully' : 'Error saving record';
