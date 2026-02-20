<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$res = db()->query('SELECT id, site_id, from_checkpoint_id, to_checkpoint_id, distance_miles FROM checkpoint_distances ORDER BY site_id, from_checkpoint_id, to_checkpoint_id');
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

json_response(['ok' => true, 'distances' => $rows]);
