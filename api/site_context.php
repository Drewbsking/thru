<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$sites = all_sites();
foreach ($sites as &$site) {
    $siteId = (int)$site['id'];
    $site['checkpoints'] = checkpoints_for_site($siteId);
}
unset($site);

json_response([
    'ok' => true,
    'sites' => $sites,
    'active_site_id' => current_site_id(),
    'settings' => [
        'speed_mph' => (float)app_setting('speed_mph', '25'),
        'buffer_minutes' => (float)app_setting('buffer_minutes', '1'),
        'min_confidence' => (int)app_setting('min_confidence', '70'),
        'poll_seconds' => (int)app_setting('poll_seconds', '10'),
        'policy_cut_through_percent' => (float)app_setting('policy_cut_through_percent', '25'),
    ],
]);
