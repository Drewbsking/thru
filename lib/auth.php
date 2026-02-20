<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';

function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function auth_password_hash(): string
{
    return app_setting('auth_password_hash', '');
}

function is_authenticated(): bool
{
    auth_session_start();
    return !empty($_SESSION['auth_ok']);
}

function login_with_password(string $password): bool
{
    $hash = auth_password_hash();
    if ($hash === '' || !password_verify($password, $hash)) {
        return false;
    }

    auth_session_start();
    $_SESSION['auth_ok'] = true;
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

    $next = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: login.php?next=' . $next, true, 302);
    exit;
}

function require_auth_api(): void
{
    if (is_authenticated()) {
        return;
    }

    json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}
