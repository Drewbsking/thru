<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        get_session_state();
    }
    if ($method === 'POST') {
        post_session_action();
    }
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Study session service error.'], 500);
}

function post_session_action(): void
{
    $siteId = (int)($_POST['site_id'] ?? 0);
    $studyPeriod = normalize_period((string)($_POST['study_period'] ?? current_study_period()));
    $action = (string)($_POST['action'] ?? '');

    if ($siteId <= 0) {
        json_response(['ok' => false, 'error' => 'Site is required.'], 422);
    }

    if ($action === 'start') {
        $active = find_active_session($siteId, $studyPeriod);
        if ($active) {
            json_response(['ok' => true, 'session' => $active, 'message' => 'Study already active.']);
        }

        $now = date('Y-m-d H:i:s');
        $status = 'active';
        $stmt = db_prepare('INSERT INTO study_sessions (site_id, study_period, status, started_at) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('isss', $siteId, $studyPeriod, $status, $now);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();

        $created = find_session_by_id($id);
        json_response(['ok' => true, 'session' => $created, 'message' => 'Study started.']);
    }

    if ($action === 'end') {
        $active = find_active_session($siteId, $studyPeriod);
        if (!$active) {
            json_response(['ok' => false, 'error' => 'No active study to end.'], 422);
        }

        $now = date('Y-m-d H:i:s');
        $id = (int)$active['id'];
        $endedStatus = 'ended';
        $stmt = db_prepare('UPDATE study_sessions SET status = ?, ended_at = ? WHERE id = ?');
        $stmt->bind_param('ssi', $endedStatus, $now, $id);
        $stmt->execute();
        $stmt->close();

        $ended = find_session_by_id($id);
        json_response(['ok' => true, 'session' => $ended, 'message' => 'Study ended.']);
    }

    json_response(['ok' => false, 'error' => 'Unknown action.'], 422);
}

function get_session_state(): void
{
    $siteId = (int)($_GET['site_id'] ?? 0);
    $studyPeriod = normalize_period((string)($_GET['study_period'] ?? current_study_period()));
    if ($siteId <= 0) {
        json_response(['ok' => false, 'error' => 'Site is required.'], 422);
    }

    $active = find_active_session($siteId, $studyPeriod);
    json_response([
        'ok' => true,
        'site_id' => $siteId,
        'study_period' => $studyPeriod,
        'active_session' => $active,
    ]);
}

function normalize_period(string $period): string
{
    $period = strtolower(trim($period));
    return $period === 'afternoon' ? 'afternoon' : 'morning';
}

function find_active_session(int $siteId, string $studyPeriod): ?array
{
    $activeStatus = 'active';
    $stmt = db_prepare('SELECT id, site_id, study_period, status, started_at, ended_at FROM study_sessions WHERE site_id = ? AND study_period = ? AND status = ? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('iss', $siteId, $studyPeriod, $activeStatus);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function find_session_by_id(int $id): ?array
{
    $stmt = db_prepare('SELECT id, site_id, study_period, status, started_at, ended_at FROM study_sessions WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
