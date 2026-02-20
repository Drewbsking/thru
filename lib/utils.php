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
    $stmt = db_prepare('SELECT id, checkpoint_code, display_name, checkpoint_type, is_active FROM checkpoints WHERE site_id = ? ORDER BY checkpoint_code ASC');
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
        $k = $row['from_checkpoint_id'] . ':' . $row['to_checkpoint_id'];
        $map[$k] = (float)$row['distance_miles'];
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
