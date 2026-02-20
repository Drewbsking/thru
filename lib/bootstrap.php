<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function ensure_schema(): void
{
    db_exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_exec("CREATE TABLE IF NOT EXISTS sites (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        image_path VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_exec("CREATE TABLE IF NOT EXISTS checkpoints (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        site_id INT UNSIGNED NOT NULL,
        checkpoint_code VARCHAR(32) NOT NULL,
        display_name VARCHAR(120) NOT NULL,
        collector_name VARCHAR(80) NULL,
        checkpoint_type ENUM('Entrance', 'Exit', 'Both') NOT NULL DEFAULT 'Both',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_site_code (site_id, checkpoint_code),
        CONSTRAINT fk_checkpoint_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $colRes = db()->query("SHOW COLUMNS FROM checkpoints LIKE 'collector_name'");
    if ($colRes && $colRes->num_rows === 0) {
        db_exec("ALTER TABLE checkpoints ADD COLUMN collector_name VARCHAR(80) NULL AFTER display_name");
    }

    db_exec("CREATE TABLE IF NOT EXISTS checkpoint_distances (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        site_id INT UNSIGNED NOT NULL,
        from_checkpoint_id INT UNSIGNED NOT NULL,
        to_checkpoint_id INT UNSIGNED NOT NULL,
        distance_miles DECIMAL(8,3) NOT NULL,
        UNIQUE KEY uniq_distance_pair (site_id, from_checkpoint_id, to_checkpoint_id),
        CONSTRAINT fk_distance_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
        CONSTRAINT fk_distance_from FOREIGN KEY (from_checkpoint_id) REFERENCES checkpoints(id) ON DELETE CASCADE,
        CONSTRAINT fk_distance_to FOREIGN KEY (to_checkpoint_id) REFERENCES checkpoints(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_exec("CREATE TABLE IF NOT EXISTS traffic_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        site_id INT UNSIGNED NOT NULL,
        checkpoint_id INT UNSIGNED NOT NULL,
        study_session_id BIGINT UNSIGNED NULL,
        direction ENUM('In', 'Out') NOT NULL,
        plate_raw VARCHAR(32) NULL,
        plate_norm VARCHAR(32) NULL,
        vehicle_type VARCHAR(50) NOT NULL,
        vehicle_color VARCHAR(50) NOT NULL,
        notes VARCHAR(255) NULL,
        observer_name VARCHAR(80) NULL,
        event_time DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_event_time (event_time),
        KEY idx_site_time (site_id, event_time),
        KEY idx_plate_norm (plate_norm),
        KEY idx_study_session_id (study_session_id),
        CONSTRAINT fk_event_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
        CONSTRAINT fk_event_checkpoint FOREIGN KEY (checkpoint_id) REFERENCES checkpoints(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $sessionColRes = db()->query("SHOW COLUMNS FROM traffic_events LIKE 'study_session_id'");
    if ($sessionColRes && $sessionColRes->num_rows === 0) {
        db_exec("ALTER TABLE traffic_events ADD COLUMN study_session_id BIGINT UNSIGNED NULL AFTER checkpoint_id");
        db_exec("ALTER TABLE traffic_events ADD KEY idx_study_session_id (study_session_id)");
    }

    db_exec("CREATE TABLE IF NOT EXISTS study_sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        site_id INT UNSIGNED NOT NULL,
        study_period ENUM('morning', 'afternoon') NOT NULL,
        status ENUM('active', 'ended') NOT NULL DEFAULT 'active',
        started_at DATETIME NOT NULL,
        ended_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_site_period_status (site_id, study_period, status),
        CONSTRAINT fk_study_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    seed_defaults();
}

function seed_defaults(): void
{
    $initialPassword = getenv('THRU_APP_PASSWORD') ?: 'change-me-now';
    $initialHash = password_hash($initialPassword, PASSWORD_DEFAULT);

    $defaults = [
        'speed_mph' => '25',
        'buffer_minutes' => '1',
        'min_confidence' => '70',
        'poll_seconds' => '10',
        'policy_cut_through_percent' => '25',
        'auth_password_hash' => $initialHash,
    ];

    $stmt = db_prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = setting_value');
    foreach ($defaults as $k => $v) {
        $stmt->bind_param('ss', $k, $v);
        $stmt->execute();
    }
    $stmt->close();

    $res = db()->query('SELECT COUNT(*) AS c FROM sites');
    $count = (int)($res->fetch_assoc()['c'] ?? 0);
    if ($count > 0) {
        return;
    }

    db_exec("INSERT INTO sites (name, is_active) VALUES ('Primary Study Site', 1)");
    $siteId = (int)db()->insert_id;

    $cpStmt = db_prepare('INSERT INTO checkpoints (site_id, checkpoint_code, display_name, collector_name, checkpoint_type, is_active) VALUES (?, ?, ?, NULL, ?, 1)');
    $entries = [
        [$siteId, 'CP1', 'Checkpoint 1', 'Both'],
        [$siteId, 'CP2', 'Checkpoint 2', 'Both'],
    ];
    foreach ($entries as $entry) {
        [$sid, $code, $name, $type] = $entry;
        $cpStmt->bind_param('isss', $sid, $code, $name, $type);
        $cpStmt->execute();
    }
    $cpStmt->close();
}
