<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$sites = scoped_sites_for_current_user();
$activeSiteId = current_site_id();
if (!is_admin() && count($sites) > 0) {
    $activeSiteId = (int)$sites[0]['id'];
}

$users = [];
$assignments = [];
if (is_admin()) {
    $uRes = db()->query("SELECT id, username, role, is_active FROM users ORDER BY role DESC, username ASC");
    $users = $uRes ? $uRes->fetch_all(MYSQLI_ASSOC) : [];
    $aRes = db()->query("SELECT a.id, a.user_id, a.site_id, a.checkpoint_id, u.username, s.name AS site_name, c.display_name AS checkpoint_name, c.checkpoint_code
        FROM checkpoint_assignments a
        INNER JOIN users u ON u.id = a.user_id
        INNER JOIN sites s ON s.id = a.site_id
        INNER JOIN checkpoints c ON c.id = a.checkpoint_id
        WHERE a.is_active = 1
        ORDER BY u.username ASC, s.name ASC, c.checkpoint_code ASC");
    $assignments = $aRes ? $aRes->fetch_all(MYSQLI_ASSOC) : [];
}

json_response([
    'ok' => true,
    'sites' => $sites,
    'active_site_id' => $activeSiteId,
    'viewer' => [
        'id' => current_user_id(),
        'username' => current_username(),
        'role' => current_user_role(),
    ],
    'users' => $users,
    'assignments' => $assignments,
    'settings' => [
        'speed_mph' => (float)app_setting('speed_mph', '25'),
        'buffer_minutes' => (float)app_setting('buffer_minutes', '1'),
        'min_confidence' => (int)app_setting('min_confidence', '70'),
        'poll_seconds' => (int)app_setting('poll_seconds', '10'),
        'policy_cut_through_percent' => (float)app_setting('policy_cut_through_percent', '25'),
        'dashboard_view_enabled' => app_setting('dashboard_view_pass_hash', '') !== '',
    ],
]);
