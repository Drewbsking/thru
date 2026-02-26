<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

$appTimezone = (string)(getenv('THRU_APP_TIMEZONE') ?: 'America/New_York');
if (!date_default_timezone_set($appTimezone)) {
    date_default_timezone_set('America/New_York');
}

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

    db_exec("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin', 'collector') NOT NULL DEFAULT 'collector',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_username (username)
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
        user_id INT UNSIGNED NULL,
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
        KEY idx_user_id (user_id),
        CONSTRAINT fk_event_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
        CONSTRAINT fk_event_checkpoint FOREIGN KEY (checkpoint_id) REFERENCES checkpoints(id) ON DELETE CASCADE,
        CONSTRAINT fk_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $eventUserColRes = db()->query("SHOW COLUMNS FROM traffic_events LIKE 'user_id'");
    if ($eventUserColRes && $eventUserColRes->num_rows === 0) {
        db_exec("ALTER TABLE traffic_events ADD COLUMN user_id INT UNSIGNED NULL AFTER checkpoint_id");
        db_exec("ALTER TABLE traffic_events ADD KEY idx_user_id (user_id)");
        db_exec("ALTER TABLE traffic_events ADD CONSTRAINT fk_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
    }

    $legacySessionColRes = db()->query("SHOW COLUMNS FROM traffic_events LIKE 'study_session_id'");
    if ($legacySessionColRes && $legacySessionColRes->num_rows > 0) {
        db_exec("ALTER TABLE traffic_events DROP COLUMN study_session_id");
    }

    db_exec("DROP TABLE IF EXISTS study_sessions");

    db_exec("CREATE TABLE IF NOT EXISTS checkpoint_assignments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        site_id INT UNSIGNED NOT NULL,
        checkpoint_id INT UNSIGNED NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_checkpoint (user_id, checkpoint_id),
        KEY idx_assignment_site_checkpoint (site_id, checkpoint_id),
        CONSTRAINT fk_assignment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_assignment_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
        CONSTRAINT fk_assignment_checkpoint FOREIGN KEY (checkpoint_id) REFERENCES checkpoints(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_exec("CREATE TABLE IF NOT EXISTS study_period_comments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        site_id INT UNSIGNED NOT NULL,
        checkpoint_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        study_date DATE NOT NULL,
        study_period ENUM('morning', 'afternoon') NOT NULL,
        comment_text VARCHAR(1000) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_comment_scope (site_id, checkpoint_id, user_id, study_date, study_period),
        KEY idx_comment_site_period_date (site_id, study_date, study_period),
        KEY idx_comment_user_date_period (user_id, study_date, study_period),
        CONSTRAINT fk_period_comment_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
        CONSTRAINT fk_period_comment_checkpoint FOREIGN KEY (checkpoint_id) REFERENCES checkpoints(id) ON DELETE CASCADE,
        CONSTRAINT fk_period_comment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    seed_defaults();
    normalize_checkpoint_codes();
}

function seed_defaults(): void
{
    $initialAdminUser = getenv('THRU_APP_ADMIN_USER') ?: 'admin';
    $initialAdminPassword = getenv('THRU_APP_ADMIN_PASSWORD') ?: 'T-CAT2026';
    $initialAdminHash = password_hash($initialAdminPassword, PASSWORD_DEFAULT);

    $defaults = [
        'speed_mph' => '25',
        'buffer_minutes' => '1',
        'min_confidence' => '70',
        'poll_seconds' => '10',
        'policy_cut_through_percent' => '25',
    ];

    $stmt = db_prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = setting_value');
    foreach ($defaults as $k => $v) {
        $stmt->bind_param('ss', $k, $v);
        $stmt->execute();
    }
    $stmt->close();

    $adminRole = 'admin';
    $userStmt = db_prepare('INSERT INTO users (username, password_hash, role, is_active) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE id = id');
    $userStmt->bind_param('sss', $initialAdminUser, $initialAdminHash, $adminRole);
    $userStmt->execute();
    $userStmt->close();

    $res = db()->query('SELECT COUNT(*) AS c FROM sites');
    $count = (int)($res->fetch_assoc()['c'] ?? 0);
    if ($count > 0) {
        return;
    }

    db_exec("INSERT INTO sites (name, is_active) VALUES ('Primary Study Site', 1)");
    $siteId = (int)db()->insert_id;

    $cpStmt = db_prepare('INSERT INTO checkpoints (site_id, checkpoint_code, display_name, collector_name, checkpoint_type, is_active) VALUES (?, ?, ?, NULL, ?, 1)');
    $entries = [
        [$siteId, '1', 'Checkpoint 1', 'Both'],
        [$siteId, '2', 'Checkpoint 2', 'Both'],
    ];
    foreach ($entries as $entry) {
        [$sid, $code, $name, $type] = $entry;
        $cpStmt->bind_param('isss', $sid, $code, $name, $type);
        $cpStmt->execute();
    }
    $cpStmt->close();
}

function normalize_checkpoint_codes(): void
{
    $res = db()->query('SELECT id, site_id, checkpoint_code FROM checkpoints ORDER BY site_id ASC, id ASC');
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    if (count($rows) === 0) {
        return;
    }

    $bySite = [];
    foreach ($rows as $row) {
        $siteId = (int)$row['site_id'];
        if (!isset($bySite[$siteId])) {
            $bySite[$siteId] = [];
        }
        $bySite[$siteId][] = [
            'id' => (int)$row['id'],
            'code' => (string)$row['checkpoint_code'],
        ];
    }

    $plans = [];
    $needsNormalization = false;
    foreach ($bySite as $siteId => $siteRows) {
        $expected = 1;
        foreach ($siteRows as $row) {
            $target = (string)$expected;
            if ($row['code'] !== $target) {
                $needsNormalization = true;
            }
            $plans[$siteId][] = [
                'id' => (int)$row['id'],
                'target_code' => $target,
            ];
            $expected++;
        }
    }

    if (!$needsNormalization) {
        return;
    }

    $conn = db();
    $conn->begin_transaction();
    try {
        $tmpStmt = db_prepare('UPDATE checkpoints SET checkpoint_code = ? WHERE id = ?');
        foreach ($plans as $sitePlan) {
            foreach ($sitePlan as $row) {
                $tmpCode = 'TMP' . $row['id'];
                $id = (int)$row['id'];
                $tmpStmt->bind_param('si', $tmpCode, $id);
                $tmpStmt->execute();
            }
        }
        $tmpStmt->close();

        $finalStmt = db_prepare('UPDATE checkpoints SET checkpoint_code = ? WHERE id = ?');
        foreach ($plans as $sitePlan) {
            foreach ($sitePlan as $row) {
                $targetCode = (string)$row['target_code'];
                $id = (int)$row['id'];
                $finalStmt->bind_param('si', $targetCode, $id);
                $finalStmt->execute();
            }
        }
        $finalStmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
