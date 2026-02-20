<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function app_setting(string $key, string $fallback = ''): string
{
    $stmt = db_prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    return $row['setting_value'] ?? $fallback;
}

function set_app_setting(string $key, string $value): void
{
    $stmt = db_prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}

function normalize_plate(?string $plate): string
{
    $plate = strtoupper((string)$plate);
    $plate = preg_replace('/[^A-Z0-9]/', '', $plate) ?? '';
    $plate = strtr($plate, [
        'O' => '0',
        'I' => '1',
    ]);
    return $plate;
}

function fuzzy_plate_alias(string $plate): string
{
    return strtr($plate, [
        'O' => '0',
        'I' => '1',
        'L' => '1',
        'S' => '5',
        'Z' => '2',
        'B' => '8',
    ]);
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function current_site_id(): int
{
    $stmt = db_prepare('SELECT id FROM sites WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    return (int)($row['id'] ?? 0);
}

function all_sites(): array
{
    $res = db()->query('SELECT id, name, image_path, is_active FROM sites ORDER BY id ASC');
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function site_by_id(int $siteId): ?array
{
    $stmt = db_prepare('SELECT id, name, image_path, is_active FROM sites WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function checkpoints_for_site(int $siteId): array
{
    $stmt = db_prepare('SELECT id, checkpoint_code, display_name, collector_name, checkpoint_type, is_active FROM checkpoints WHERE site_id = ? ORDER BY checkpoint_code ASC');
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rows = $stmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function distance_map_for_site(int $siteId): array
{
    $stmt = db_prepare('SELECT from_checkpoint_id, to_checkpoint_id, distance_miles FROM checkpoint_distances WHERE site_id = ?');
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rows = $stmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $map = [];
    foreach ($rows as $row) {
        $from = (int)$row['from_checkpoint_id'];
        $to = (int)$row['to_checkpoint_id'];
        $dist = (float)$row['distance_miles'];
        $forwardKey = $from . ':' . $to;
        $reverseKey = $to . ':' . $from;
        $map[$forwardKey] = $dist;
        // Treat distances as bidirectional by default unless explicitly overridden.
        if (!isset($map[$reverseKey])) {
            $map[$reverseKey] = $dist;
        }
    }
    return $map;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function current_study_period(): string
{
    $hour = (int)date('H');
    return $hour < 12 ? 'morning' : 'afternoon';
}

function active_study_session_id(int $siteId, ?string $studyPeriod = null): ?int
{
    $studyPeriod = $studyPeriod ?: current_study_period();
    $stmt = db_prepare('SELECT id FROM study_sessions WHERE site_id = ? AND study_period = ? AND status = \'active\' ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('is', $siteId, $studyPeriod);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    return (int)$row['id'];
}
