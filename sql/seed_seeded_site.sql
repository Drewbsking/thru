-- N-CAT demo seed for a brand-new site named "Seeded Site"
-- Run this in phpMyAdmin (SQL tab) against your N-CAT database.
-- It will:
-- 1) Remove any existing "Seeded Site"
-- 2) Create and activate a new "Seeded Site"
-- 3) Create 3 checkpoints (1, 2, 3)
-- 4) Create distances between checkpoint pairs
-- 5) Insert randomized AM/PM traffic events (matched + unmatched)

START TRANSACTION;

SET @seed_site_name := 'Seeded Site';

-- Remove existing seeded site (cascades checkpoints/distances/events).
DELETE FROM sites WHERE name = @seed_site_name;

-- Make this seeded site active so it appears immediately on dashboard/home.
UPDATE sites SET is_active = 0;
INSERT INTO sites (name, image_path, is_active) VALUES (@seed_site_name, NULL, 1);
SET @site_id := LAST_INSERT_ID();

-- Checkpoints are numeric by design: 1, 2, 3.
INSERT INTO checkpoints (site_id, checkpoint_code, display_name, collector_name, checkpoint_type, is_active)
VALUES
  (@site_id, '1', 'Checkpoint 1', NULL, 'Both', 1),
  (@site_id, '2', 'Checkpoint 2', NULL, 'Both', 1),
  (@site_id, '3', 'Checkpoint 3', NULL, 'Both', 1);

SELECT @cp1 := id FROM checkpoints WHERE site_id = @site_id AND checkpoint_code = '1' LIMIT 1;
SELECT @cp2 := id FROM checkpoints WHERE site_id = @site_id AND checkpoint_code = '2' LIMIT 1;
SELECT @cp3 := id FROM checkpoints WHERE site_id = @site_id AND checkpoint_code = '3' LIMIT 1;

-- Distances (miles). These define expected travel time for matching.
INSERT INTO checkpoint_distances (site_id, from_checkpoint_id, to_checkpoint_id, distance_miles)
VALUES
  (@site_id, @cp1, @cp2, 0.900),
  (@site_id, @cp2, @cp1, 0.900),
  (@site_id, @cp2, @cp3, 1.200),
  (@site_id, @cp3, @cp2, 1.200),
  (@site_id, @cp1, @cp3, 1.800),
  (@site_id, @cp3, @cp1, 1.800)
ON DUPLICATE KEY UPDATE distance_miles = VALUES(distance_miles);

DROP TEMPORARY TABLE IF EXISTS tmp_seed_numbers;
CREATE TEMPORARY TABLE tmp_seed_numbers (
  n INT NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

INSERT INTO tmp_seed_numbers (n)
VALUES
  (1),(2),(3),(4),(5),(6),(7),(8),(9),(10),
  (11),(12),(13),(14),(15),(16),(17),(18),(19),(20),
  (21),(22),(23),(24),(25),(26),(27),(28),(29),(30),
  (31),(32),(33),(34),(35),(36),(37),(38),(39),(40),
  (41),(42),(43),(44),(45),(46),(47),(48),(49),(50),
  (51),(52),(53),(54),(55),(56),(57),(58),(59),(60),
  (61),(62),(63),(64),(65),(66),(67),(68),(69),(70),
  (71),(72),(73),(74),(75),(76),(77),(78),(79),(80);

DROP TEMPORARY TABLE IF EXISTS tmp_seed_pairs;
CREATE TEMPORARY TABLE tmp_seed_pairs (
  n INT NOT NULL PRIMARY KEY,
  route_idx INT NOT NULL,
  from_checkpoint_id INT NOT NULL,
  to_checkpoint_id INT NOT NULL,
  plate_raw VARCHAR(16) NOT NULL,
  vehicle_type VARCHAR(50) NOT NULL,
  vehicle_color VARCHAR(50) NOT NULL,
  observer_in VARCHAR(80) NOT NULL,
  observer_out VARCHAR(80) NOT NULL,
  in_time DATETIME NOT NULL,
  out_time DATETIME NOT NULL
) ENGINE=MEMORY;

-- 80 matched pairs = 160 events (40 morning, 40 afternoon).
INSERT INTO tmp_seed_pairs (
  n, route_idx, from_checkpoint_id, to_checkpoint_id, plate_raw, vehicle_type, vehicle_color, observer_in, observer_out, in_time, out_time
)
SELECT
  base.n,
  base.route_idx,
  CASE base.route_idx
    WHEN 1 THEN @cp1
    WHEN 2 THEN @cp2
    WHEN 3 THEN @cp2
    WHEN 4 THEN @cp3
    WHEN 5 THEN @cp1
    ELSE @cp3
  END AS from_checkpoint_id,
  CASE base.route_idx
    WHEN 1 THEN @cp2
    WHEN 2 THEN @cp1
    WHEN 3 THEN @cp3
    WHEN 4 THEN @cp2
    WHEN 5 THEN @cp3
    ELSE @cp1
  END AS to_checkpoint_id,
  CONCAT(
    SUBSTRING('ABCDEFGHJKLMNPRSTUVWXYZ', 1 + MOD(base.n * 7, 22), 1),
    SUBSTRING('ABCDEFGHJKLMNPRSTUVWXYZ', 1 + MOD(base.n * 11, 22), 1),
    SUBSTRING('0123456789', 1 + MOD(base.n * 13, 10), 1)
  ) AS plate_raw,
  ELT(1 + MOD(base.n + 1, 5), 'Sedan', 'SUV', 'Truck', 'Minivan', 'Trailer/Motorcycle') AS vehicle_type,
  ELT(1 + MOD(base.n - 1, 6), 'White', 'Black/Blue', 'Gray/Silver', 'Red', 'Green', 'Other') AS vehicle_color,
  ELT(1 + MOD(base.n - 1, 4), 'Alex', 'Blair', 'Casey', 'Drew') AS observer_in,
  ELT(1 + MOD(base.n + 1, 4), 'Alex', 'Blair', 'Casey', 'Drew') AS observer_out,
  TIMESTAMP(
    CURDATE(),
    ADDTIME(
      IF(base.n <= 40, '08:00:00', '13:00:00'),
      SEC_TO_TIME(FLOOR(RAND(base.n * 97) * 12600))
    )
  ) AS in_time,
  DATE_ADD(
    TIMESTAMP(
      CURDATE(),
      ADDTIME(
        IF(base.n <= 40, '08:00:00', '13:00:00'),
        SEC_TO_TIME(FLOOR(RAND(base.n * 97) * 12600))
      )
    ),
    INTERVAL GREATEST(
      60,
      CASE base.route_idx
        WHEN 1 THEN 130
        WHEN 2 THEN 130
        WHEN 3 THEN 173
        WHEN 4 THEN 173
        WHEN 5 THEN 259
        ELSE 259
      END + (FLOOR(RAND(base.n * 131) * 70) - 35)
    ) SECOND
  ) AS out_time
FROM (
  SELECT
    n,
    1 + MOD(n - 1, 6) AS route_idx
  FROM tmp_seed_numbers
) AS base;

INSERT INTO traffic_events (
  site_id, checkpoint_id, user_id, direction, plate_raw, plate_norm, vehicle_type, vehicle_color, notes, observer_name, event_time
)
SELECT
  @site_id,
  from_checkpoint_id,
  NULL,
  'In',
  plate_raw,
  REPLACE(REPLACE(UPPER(plate_raw), 'O', '0'), 'I', '1'),
  vehicle_type,
  vehicle_color,
  CONCAT('Seeded matched pair #', n),
  observer_in,
  in_time
FROM tmp_seed_pairs
ORDER BY in_time, n;

INSERT INTO traffic_events (
  site_id, checkpoint_id, user_id, direction, plate_raw, plate_norm, vehicle_type, vehicle_color, notes, observer_name, event_time
)
SELECT
  @site_id,
  to_checkpoint_id,
  NULL,
  'Out',
  plate_raw,
  REPLACE(REPLACE(UPPER(plate_raw), 'O', '0'), 'I', '1'),
  vehicle_type,
  vehicle_color,
  CONCAT('Seeded matched pair #', n),
  observer_out,
  out_time
FROM tmp_seed_pairs
ORDER BY out_time, n;

DROP TEMPORARY TABLE IF EXISTS tmp_seed_unmatched;
CREATE TEMPORARY TABLE tmp_seed_unmatched (
  n INT NOT NULL PRIMARY KEY,
  checkpoint_id INT NOT NULL,
  direction ENUM('In', 'Out') NOT NULL,
  plate_raw VARCHAR(16) NOT NULL,
  vehicle_type VARCHAR(50) NOT NULL,
  vehicle_color VARCHAR(50) NOT NULL,
  observer_name VARCHAR(80) NOT NULL,
  event_time DATETIME NOT NULL
) ENGINE=MEMORY;

-- 24 unmatched events (12 In + 12 Out) for local arrivals/departures KPI.
INSERT INTO tmp_seed_unmatched (
  n, checkpoint_id, direction, plate_raw, vehicle_type, vehicle_color, observer_name, event_time
)
SELECT
  n,
  CASE MOD(n - 1, 3)
    WHEN 0 THEN @cp1
    WHEN 1 THEN @cp2
    ELSE @cp3
  END AS checkpoint_id,
  IF(n <= 12, 'In', 'Out') AS direction,
  CONCAT('ZZ', LPAD(n, 2, '0')) AS plate_raw,
  ELT(1 + MOD(n + 2, 5), 'Sedan', 'SUV', 'Truck', 'Minivan', 'Trailer/Motorcycle') AS vehicle_type,
  ELT(1 + MOD(n + 2, 6), 'White', 'Black/Blue', 'Gray/Silver', 'Red', 'Green', 'Other') AS vehicle_color,
  ELT(1 + MOD(n, 4), 'Alex', 'Blair', 'Casey', 'Drew') AS observer_name,
  TIMESTAMP(
    CURDATE(),
    ADDTIME(
      IF(MOD(n, 2) = 0, '09:00:00', '14:00:00'),
      SEC_TO_TIME(FLOOR(RAND(n * 157) * 9000))
    )
  ) AS event_time
FROM tmp_seed_numbers
WHERE n <= 24;

INSERT INTO traffic_events (
  site_id, checkpoint_id, user_id, direction, plate_raw, plate_norm, vehicle_type, vehicle_color, notes, observer_name, event_time
)
SELECT
  @site_id,
  checkpoint_id,
  NULL,
  direction,
  plate_raw,
  REPLACE(REPLACE(UPPER(plate_raw), 'O', '0'), 'I', '1'),
  vehicle_type,
  vehicle_color,
  'Seeded unmatched local event',
  observer_name,
  event_time
FROM tmp_seed_unmatched
ORDER BY event_time, n;

DROP TEMPORARY TABLE IF EXISTS tmp_seed_pairs;
DROP TEMPORARY TABLE IF EXISTS tmp_seed_unmatched;
DROP TEMPORARY TABLE IF EXISTS tmp_seed_numbers;

COMMIT;

-- Quick verification queries.
SELECT id, name, is_active FROM sites WHERE id = @site_id;
SELECT checkpoint_code, display_name FROM checkpoints WHERE site_id = @site_id ORDER BY checkpoint_code ASC;
SELECT COUNT(*) AS seeded_events FROM traffic_events WHERE site_id = @site_id;
