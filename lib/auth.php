<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';

function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function current_script_basename(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    return basename($script);
}

function current_user(): ?array
{
    auth_session_start();
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0 && (int)($_SESSION['viewer_dashboard_only'] ?? 0) === 1) {
        return [
            'id' => 0,
            'username' => 'viewer',
            'role' => 'viewer',
        ];
    }
    if ($id <= 0) {
        return null;
    }
    return [
        'id' => $id,
        'username' => (string)($_SESSION['username'] ?? ''),
        'role' => (string)($_SESSION['role'] ?? ''),
    ];
}

function current_user_id(): int
{
    return (int)(current_user()['id'] ?? 0);
}

function current_username(): string
{
    return (string)(current_user()['username'] ?? '');
}

function current_user_role(): string
{
    return (string)(current_user()['role'] ?? '');
}

function is_admin(): bool
{
    return current_user_role() === 'admin';
}

function is_dashboard_viewer(): bool
{
    auth_session_start();
    return (int)($_SESSION['viewer_dashboard_only'] ?? 0) === 1;
}

function is_authenticated(): bool
{
    auth_session_start();
    return (int)($_SESSION['user_id'] ?? 0) > 0;
}

function login_dashboard_viewer_with_code(string $accessCode): bool
{
    $accessCode = trim($accessCode);
    if ($accessCode === '') {
        return false;
    }

    $storedHash = (string)app_setting('dashboard_view_pass_hash', '');
    if ($storedHash === '' || !password_verify($accessCode, $storedHash)) {
        return false;
    }

    auth_session_start();
    $_SESSION = [];
    $_SESSION['viewer_dashboard_only'] = 1;
    $_SESSION['auth_at'] = time();
    session_regenerate_id(true);
    return true;
}

function csrf_token(): string
{
    auth_session_start();
    $token = (string)($_SESSION['csrf_token'] ?? '');
    if ($token !== '') {
        return $token;
    }
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    return $token;
}

function verify_csrf_token(?string $token): bool
{
    auth_session_start();
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $token = (string)$token;
    if ($sessionToken === '' || $token === '') {
        return false;
    }
    return hash_equals($sessionToken, $token);
}

function login_with_credentials(string $username, string $password): bool
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return false;
    }

    $stmt = db_prepare('SELECT id, username, password_hash, role, is_active FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    if (!$row || (int)$row['is_active'] !== 1) {
        return false;
    }

    if (!password_verify($password, (string)$row['password_hash'])) {
        return false;
    }

    auth_session_start();
    unset($_SESSION['viewer_dashboard_only']);
    $_SESSION['user_id'] = (int)$row['id'];
    $_SESSION['username'] = (string)$row['username'];
    $_SESSION['role'] = (string)$row['role'];
    $_SESSION['auth_at'] = time();
    session_regenerate_id(true);
    return true;
}

function logout_user(): void
{
    auth_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

function require_auth_page(): void
{
    if (is_authenticated()) {
        return;
    }
    if (is_dashboard_viewer()) {
        $script = current_script_basename();
        if ($script === 'dashboard.php' || $script === 'logout.php') {
            return;
        }
        header('Location: dashboard.php', true, 302);
        exit;
    }

    $next = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: login.php?next=' . $next, true, 302);
    exit;
}

function require_admin_page(): void
{
    require_auth_page();
    if (is_admin()) {
        return;
    }
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function require_auth_api(): void
{
    if (is_authenticated()) {
        return;
    }
    if (is_dashboard_viewer()) {
        $script = current_script_basename();
        if ($script === 'dashboard_data.php') {
            return;
        }
        json_response(['ok' => false, 'error' => 'Dashboard viewer is read-only.'], 403);
    }

    json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

function require_admin_api(): void
{
    require_auth_api();
    if (is_admin()) {
        return;
    }
    json_response(['ok' => false, 'error' => 'Admin access required.'], 403);
}

function require_csrf_api(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!$token && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    if (verify_csrf_token($token)) {
        return;
    }
    json_response(['ok' => false, 'error' => 'Invalid CSRF token.'], 419);
}

function assigned_checkpoints_for_user(int $userId): array
{
    $stmt = db_prepare('SELECT a.id, a.user_id, a.site_id, a.checkpoint_id, s.name AS site_name, c.display_name AS checkpoint_name, c.checkpoint_code
        FROM checkpoint_assignments a
        INNER JOIN sites s ON s.id = a.site_id
        INNER JOIN checkpoints c ON c.id = a.checkpoint_id
        WHERE a.user_id = ? AND a.is_active = 1 AND c.is_active = 1
        ORDER BY s.name ASC, c.checkpoint_code ASC');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function can_access_checkpoint(int $siteId, int $checkpointId): bool
{
    if ($siteId <= 0 || $checkpointId <= 0) {
        return false;
    }
    if (is_admin()) {
        return true;
    }
    $userId = current_user_id();
    if ($userId <= 0) {
        return false;
    }

    $stmt = db_prepare('SELECT id FROM checkpoint_assignments WHERE user_id = ? AND site_id = ? AND checkpoint_id = ? AND is_active = 1 LIMIT 1');
    $stmt->bind_param('iii', $userId, $siteId, $checkpointId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    return (bool)$row;
}

function can_modify_event(int $eventUserId): bool
{
    if (is_admin()) {
        return true;
    }
    $userId = current_user_id();
    return $userId > 0 && $eventUserId > 0 && $eventUserId === $userId;
}

function scoped_sites_for_current_user(): array
{
    if (is_admin()) {
        $sites = all_sites();
        foreach ($sites as &$site) {
            $site['checkpoints'] = checkpoints_for_site((int)$site['id']);
        }
        unset($site);
        return $sites;
    }

    $userId = current_user_id();
    if ($userId <= 0) {
        return [];
    }

    $assignments = assigned_checkpoints_for_user($userId);
    $siteMap = [];
    foreach ($assignments as $row) {
        $siteId = (int)$row['site_id'];
        if (!isset($siteMap[$siteId])) {
            $siteMap[$siteId] = [
                'id' => $siteId,
                'name' => (string)$row['site_name'],
                'image_path' => null,
                'is_active' => 0,
                'checkpoints' => [],
            ];
        }
        $siteMap[$siteId]['checkpoints'][] = [
            'id' => (int)$row['checkpoint_id'],
            'checkpoint_code' => (string)$row['checkpoint_code'],
            'display_name' => (string)$row['checkpoint_name'],
            'collector_name' => null,
            'checkpoint_type' => 'Both',
            'is_active' => 1,
        ];
    }

    if (!$siteMap) {
        return [];
    }

    $siteIds = array_map('intval', array_keys($siteMap));
    $in = implode(',', $siteIds);
    $rows = [];
    if ($in !== '') {
        $res = db()->query("SELECT id, image_path, is_active FROM sites WHERE id IN ($in)");
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
    foreach ($rows as $row) {
        $siteId = (int)$row['id'];
        if (isset($siteMap[$siteId])) {
            $siteMap[$siteId]['image_path'] = $row['image_path'];
            $siteMap[$siteId]['is_active'] = (int)$row['is_active'];
        }
    }

    usort($siteMap, static function (array $a, array $b): int {
        return strcmp((string)$a['name'], (string)$b['name']);
    });
    return array_values($siteMap);
}
