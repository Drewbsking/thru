<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }

    $cfg = app_config();
    $conn = new mysqli($cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name']);
    if ($conn->connect_error) {
        http_response_code(500);
        die('Database connection failed.');
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function db_exec(string $sql): void
{
    $conn = db();
    if (!$conn->query($sql)) {
        throw new RuntimeException('DB query failed: ' . $conn->error);
    }
}

function db_prepare(string $sql): mysqli_stmt
{
    $stmt = db()->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare statement.');
    }
    return $stmt;
}

function db_fetch_all_assoc(mysqli_stmt $stmt): array
{
    $res = $stmt->get_result();
    if (!$res) {
        return [];
    }
    return $res->fetch_all(MYSQLI_ASSOC);
}
