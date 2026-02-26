CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sites (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  image_path VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin', 'collector') NOT NULL DEFAULT 'collector',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checkpoints (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checkpoint_distances (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  site_id INT UNSIGNED NOT NULL,
  from_checkpoint_id INT UNSIGNED NOT NULL,
  to_checkpoint_id INT UNSIGNED NOT NULL,
  distance_miles DECIMAL(8,3) NOT NULL,
  UNIQUE KEY uniq_distance_pair (site_id, from_checkpoint_id, to_checkpoint_id),
  CONSTRAINT fk_distance_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_distance_from FOREIGN KEY (from_checkpoint_id) REFERENCES checkpoints(id) ON DELETE CASCADE,
  CONSTRAINT fk_distance_to FOREIGN KEY (to_checkpoint_id) REFERENCES checkpoints(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS traffic_events (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checkpoint_assignments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS study_period_comments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
