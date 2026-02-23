<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

require_admin_api();
require_csrf_api();

action_router($_POST['action'] ?? '');

function action_router(string $action): void
{
    switch ($action) {
        case 'save_settings':
            save_settings();
            return;
        case 'save_auth_password':
            save_auth_password();
            return;
        case 'create_collector':
            create_collector();
            return;
        case 'assign_collector_checkpoint':
            assign_collector_checkpoint();
            return;
        case 'remove_assignment':
            remove_assignment();
            return;
        case 'create_site':
            create_site();
            return;
        case 'set_active_site':
            set_active_site();
            return;
        case 'save_checkpoint':
            save_checkpoint();
            return;
        case 'delete_checkpoint':
            delete_checkpoint();
            return;
        case 'save_distance':
            save_distance();
            return;
        case 'upload_site_image':
            upload_site_image();
            return;
        default:
            json_response(['ok' => false, 'error' => 'Unknown action'], 422);
    }
}

function save_settings(): void
{
    $speed = (string)max(1, min(120, (float)($_POST['speed_mph'] ?? 25)));
    $buffer = (string)max(0.1, min(20, (float)($_POST['buffer_minutes'] ?? 1)));
    $confidence = (string)max(50, min(100, (int)($_POST['min_confidence'] ?? 70)));
    $poll = (string)max(5, min(60, (int)($_POST['poll_seconds'] ?? 10)));
    $policy = (string)max(1, min(100, (float)($_POST['policy_cut_through_percent'] ?? 25)));

    set_app_setting('speed_mph', $speed);
    set_app_setting('buffer_minutes', $buffer);
    set_app_setting('min_confidence', $confidence);
    set_app_setting('poll_seconds', $poll);
    set_app_setting('policy_cut_through_percent', $policy);

    json_response(['ok' => true]);
}

function save_auth_password(): void
{
    $password = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_new_password'] ?? '');
    if (strlen($password) < 10) {
        json_response(['ok' => false, 'error' => 'Password must be at least 10 characters.'], 422);
    }
    if ($password !== $confirm) {
        json_response(['ok' => false, 'error' => 'Passwords do not match.'], 422);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $userId = current_user_id();
    $stmt = db_prepare('UPDATE users SET password_hash = ? WHERE id = ? LIMIT 1');
    $stmt->bind_param('si', $hash, $userId);
    $stmt->execute();
    $stmt->close();
    json_response(['ok' => true]);
}

function create_collector(): void
{
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (!preg_match('/^[a-z0-9._-]{3,64}$/', $username)) {
        json_response(['ok' => false, 'error' => 'Username must be 3-64 chars: letters, numbers, dot, underscore, hyphen.'], 422);
    }
    if (strlen($password) < 10) {
        json_response(['ok' => false, 'error' => 'Password must be at least 10 characters.'], 422);
    }
    if ($password !== $confirm) {
        json_response(['ok' => false, 'error' => 'Passwords do not match.'], 422);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $role = 'collector';
    $stmt = db_prepare('INSERT INTO users (username, password_hash, role, is_active) VALUES (?, ?, ?, 1)');
    $stmt->bind_param('sss', $username, $hash, $role);
    $ok = false;
    try {
        $ok = $stmt->execute();
    } catch (Throwable $e) {
        $ok = false;
    }
    if (!$ok) {
        $stmt->close();
        json_response(['ok' => false, 'error' => 'Username already exists or could not be created.'], 422);
    }
    $userId = (int)$stmt->insert_id;
    $stmt->close();

    json_response(['ok' => true, 'user_id' => $userId]);
}

function assign_collector_checkpoint(): void
{
    $userId = (int)($_POST['user_id'] ?? 0);
    $siteId = (int)($_POST['site_id'] ?? 0);
    $checkpointId = (int)($_POST['checkpoint_id'] ?? 0);

    if ($userId <= 0 || $siteId <= 0 || $checkpointId <= 0) {
        json_response(['ok' => false, 'error' => 'User, site, and checkpoint are required.'], 422);
    }

    $roleStmt = db_prepare('SELECT role FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $roleStmt->bind_param('i', $userId);
    $roleStmt->execute();
    $user = $roleStmt->get_result()?->fetch_assoc();
    $roleStmt->close();
    if (!$user || (string)$user['role'] !== 'collector') {
        json_response(['ok' => false, 'error' => 'Only collector users can be assigned.'], 422);
    }

    $cpStmt = db_prepare('SELECT id FROM checkpoints WHERE id = ? AND site_id = ? AND is_active = 1 LIMIT 1');
    $cpStmt->bind_param('ii', $checkpointId, $siteId);
    $cpStmt->execute();
    $cp = $cpStmt->get_result()?->fetch_assoc();
    $cpStmt->close();
    if (!$cp) {
        json_response(['ok' => false, 'error' => 'Invalid checkpoint for selected site.'], 422);
    }

    $stmt = db_prepare('INSERT INTO checkpoint_assignments (user_id, site_id, checkpoint_id, is_active) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE is_active = 1, site_id = VALUES(site_id)');
    $stmt->bind_param('iii', $userId, $siteId, $checkpointId);
    $stmt->execute();
    $stmt->close();

    json_response(['ok' => true]);
}

function remove_assignment(): void
{
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    if ($assignmentId <= 0) {
        json_response(['ok' => false, 'error' => 'Assignment id is required.'], 422);
    }
    $stmt = db_prepare('DELETE FROM checkpoint_assignments WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $assignmentId);
    $stmt->execute();
    $stmt->close();
    json_response(['ok' => true]);
}

function create_site(): void
{
    $name = trim((string)($_POST['site_name'] ?? ''));
    if ($name === '') {
        json_response(['ok' => false, 'error' => 'Site name is required.'], 422);
    }

    $stmt = db_prepare('INSERT INTO sites (name, is_active) VALUES (?, 0)');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();

    json_response(['ok' => true, 'site_id' => $id]);
}

function set_active_site(): void
{
    $siteId = (int)($_POST['site_id'] ?? 0);
    if ($siteId <= 0) {
        json_response(['ok' => false, 'error' => 'Site id is required.'], 422);
    }

    db_exec('UPDATE sites SET is_active = 0');
    $stmt = db_prepare('UPDATE sites SET is_active = 1 WHERE id = ?');
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $stmt->close();

    json_response(['ok' => true]);
}

function save_checkpoint(): void
{
    $siteId = (int)($_POST['site_id'] ?? 0);
    $checkpointId = (int)($_POST['checkpoint_id'] ?? 0);
    $name = trim((string)($_POST['display_name'] ?? ''));
    $type = (string)($_POST['checkpoint_type'] ?? 'Both');
    if (!in_array($type, ['Entrance', 'Exit', 'Both'], true)) {
        $type = 'Both';
    }

    if ($siteId <= 0 || $name === '') {
        json_response(['ok' => false, 'error' => 'Display name is required.'], 422);
    }

    if ($checkpointId > 0) {
        $stmt = db_prepare('UPDATE checkpoints SET display_name = ?, checkpoint_type = ? WHERE id = ? AND site_id = ?');
        $stmt->bind_param('ssii', $name, $type, $checkpointId, $siteId);
        $stmt->execute();
        $stmt->close();
    } else {
        $codesStmt = db_prepare('SELECT checkpoint_code FROM checkpoints WHERE site_id = ?');
        $codesStmt->bind_param('i', $siteId);
        $codesStmt->execute();
        $codeRows = $codesStmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
        $codesStmt->close();

        $used = [];
        foreach ($codeRows as $row) {
            $code = trim((string)($row['checkpoint_code'] ?? ''));
            if (ctype_digit($code)) {
                $num = (int)$code;
                if ($num > 0) {
                    $used[$num] = true;
                }
            }
        }
        $next = 1;
        while (isset($used[$next])) {
            $next++;
        }
        $nextCode = (string)$next;

        $stmt = db_prepare('INSERT INTO checkpoints (site_id, checkpoint_code, display_name, collector_name, checkpoint_type, is_active) VALUES (?, ?, ?, NULL, ?, 1)');
        $stmt->bind_param('isss', $siteId, $nextCode, $name, $type);
        $stmt->execute();
        $checkpointId = $stmt->insert_id;
        $stmt->close();
    }

    json_response(['ok' => true, 'checkpoint_id' => $checkpointId]);
}

function delete_checkpoint(): void
{
    $siteId = (int)($_POST['site_id'] ?? 0);
    $checkpointId = (int)($_POST['checkpoint_id'] ?? 0);
    if ($siteId <= 0 || $checkpointId <= 0) {
        json_response(['ok' => false, 'error' => 'Checkpoint id is required.'], 422);
    }

    $stmt = db_prepare('DELETE FROM checkpoints WHERE id = ? AND site_id = ?');
    $stmt->bind_param('ii', $checkpointId, $siteId);
    $stmt->execute();
    $stmt->close();

    json_response(['ok' => true]);
}

function save_distance(): void
{
    $siteId = (int)($_POST['site_id'] ?? 0);
    $fromId = (int)($_POST['from_checkpoint_id'] ?? 0);
    $toId = (int)($_POST['to_checkpoint_id'] ?? 0);
    $distance = (float)($_POST['distance_miles'] ?? 0);

    if ($siteId <= 0 || $fromId <= 0 || $toId <= 0 || $fromId === $toId || $distance <= 0) {
        json_response(['ok' => false, 'error' => 'Distance values are invalid.'], 422);
    }

    $stmt = db_prepare('INSERT INTO checkpoint_distances (site_id, from_checkpoint_id, to_checkpoint_id, distance_miles) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE distance_miles = VALUES(distance_miles)');
    $stmt->bind_param('iiid', $siteId, $fromId, $toId, $distance);
    $stmt->execute();
    $stmt->close();

    json_response(['ok' => true]);
}

function upload_site_image(): void
{
    $siteId = (int)($_POST['site_id'] ?? 0);
    if ($siteId <= 0) {
        json_response(['ok' => false, 'error' => 'Site id is required.'], 422);
    }
    if (!isset($_FILES['site_image']) || ($_FILES['site_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['ok' => false, 'error' => 'Image upload failed.'], 422);
    }

    $tmp = $_FILES['site_image']['tmp_name'];
    $mime = mime_content_type($tmp) ?: '';
    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        json_response(['ok' => false, 'error' => 'Only PNG/JPG/WEBP allowed.'], 422);
    }

    $cfg = app_config();
    if (!is_dir($cfg['upload_dir'])) {
        mkdir($cfg['upload_dir'], 0775, true);
    }

    $ext = $allowed[$mime];
    $fileName = 'site_' . $siteId . '_' . time() . '.' . $ext;
    $target = $cfg['upload_dir'] . '/' . $fileName;
    if (!move_uploaded_file($tmp, $target)) {
        json_response(['ok' => false, 'error' => 'Failed to store image.'], 500);
    }

    $webPath = $cfg['upload_web_path'] . '/' . $fileName;
    $stmt = db_prepare('UPDATE sites SET image_path = ? WHERE id = ?');
    $stmt->bind_param('si', $webPath, $siteId);
    $stmt->execute();
    $stmt->close();

    json_response(['ok' => true, 'image_path' => $webPath]);
}
