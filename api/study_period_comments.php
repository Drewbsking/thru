<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

function normalize_study_period(string $value): string
{
    $period = strtolower(trim($value));
    return in_array($period, ['morning', 'afternoon'], true) ? $period : 'morning';
}

function normalize_study_date(string $value): ?string
{
    $studyDate = trim($value);
    if ($studyDate === '') {
        return null;
    }
    $dateObj = DateTime::createFromFormat('Y-m-d', $studyDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $studyDate) {
        return null;
    }
    return $studyDate;
}

function period_time_bounds(string $studyPeriod): array
{
    if ($studyPeriod === 'afternoon') {
        return ['12:00:00', '23:59:59'];
    }
    return ['00:00:00', '11:59:59'];
}

function load_period_comments_rows(int $siteId, string $studyDate, string $studyPeriod, int $checkpointId = 0): array
{
    if ($checkpointId > 0) {
        $stmt = db_prepare('SELECT spc.id, spc.site_id, spc.checkpoint_id, spc.user_id, spc.study_date, spc.study_period, spc.comment_text, spc.created_at, spc.updated_at,
            c.checkpoint_code, c.display_name AS checkpoint_name, u.username AS collector_username
            FROM study_period_comments spc
            INNER JOIN checkpoints c ON c.id = spc.checkpoint_id
            INNER JOIN users u ON u.id = spc.user_id
            WHERE spc.site_id = ? AND spc.study_date = ? AND spc.study_period = ? AND spc.checkpoint_id = ?
            ORDER BY CAST(c.checkpoint_code AS UNSIGNED) ASC, c.checkpoint_code ASC, u.username ASC');
        $stmt->bind_param('issi', $siteId, $studyDate, $studyPeriod, $checkpointId);
    } else {
        $stmt = db_prepare('SELECT spc.id, spc.site_id, spc.checkpoint_id, spc.user_id, spc.study_date, spc.study_period, spc.comment_text, spc.created_at, spc.updated_at,
            c.checkpoint_code, c.display_name AS checkpoint_name, u.username AS collector_username
            FROM study_period_comments spc
            INNER JOIN checkpoints c ON c.id = spc.checkpoint_id
            INNER JOIN users u ON u.id = spc.user_id
            WHERE spc.site_id = ? AND spc.study_date = ? AND spc.study_period = ?
            ORDER BY CAST(c.checkpoint_code AS UNSIGNED) ASC, c.checkpoint_code ASC, u.username ASC');
        $stmt->bind_param('iss', $siteId, $studyDate, $studyPeriod);
    }
    $stmt->execute();
    $rows = $stmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    foreach ($rows as &$row) {
        $checkpointName = trim((string)($row['checkpoint_name'] ?? ''));
        $checkpointCode = trim((string)($row['checkpoint_code'] ?? ''));
        $row['checkpoint_label'] = $checkpointName !== '' && $checkpointCode !== ''
            ? $checkpointName . ' (' . $checkpointCode . ')'
            : ($checkpointName !== '' ? $checkpointName : $checkpointCode);
        $row['id'] = (int)($row['id'] ?? 0);
        $row['site_id'] = (int)($row['site_id'] ?? 0);
        $row['checkpoint_id'] = (int)($row['checkpoint_id'] ?? 0);
        $row['user_id'] = (int)($row['user_id'] ?? 0);
    }
    unset($row);
    return $rows;
}

function latest_period_date_with_data(int $siteId, string $studyPeriod, int $checkpointId = 0): ?string
{
    $eventSql = 'SELECT MAX(DATE(event_time)) AS latest_date
        FROM traffic_events
        WHERE site_id = ?
          AND TIME(event_time) >= ?
          AND TIME(event_time) <= ?';
    $commentSql = 'SELECT MAX(study_date) AS latest_date
        FROM study_period_comments
        WHERE site_id = ?
          AND study_period = ?';

    if ($checkpointId > 0) {
        $eventSql .= ' AND checkpoint_id = ?';
        $commentSql .= ' AND checkpoint_id = ?';
    }

    [$periodStart, $periodEnd] = period_time_bounds($studyPeriod);
    if ($checkpointId > 0) {
        $eventStmt = db_prepare($eventSql);
        $eventStmt->bind_param('issi', $siteId, $periodStart, $periodEnd, $checkpointId);
        $commentStmt = db_prepare($commentSql);
        $commentStmt->bind_param('isi', $siteId, $studyPeriod, $checkpointId);
    } else {
        $eventStmt = db_prepare($eventSql);
        $eventStmt->bind_param('iss', $siteId, $periodStart, $periodEnd);
        $commentStmt = db_prepare($commentSql);
        $commentStmt->bind_param('is', $siteId, $studyPeriod);
    }

    $eventStmt->execute();
    $eventLatest = trim((string)($eventStmt->get_result()?->fetch_assoc()['latest_date'] ?? ''));
    $eventStmt->close();

    $commentStmt->execute();
    $commentLatest = trim((string)($commentStmt->get_result()?->fetch_assoc()['latest_date'] ?? ''));
    $commentStmt->close();

    if ($eventLatest === '' && $commentLatest === '') {
        return null;
    }
    if ($eventLatest === '') {
        return $commentLatest;
    }
    if ($commentLatest === '') {
        return $eventLatest;
    }
    return strcmp($eventLatest, $commentLatest) >= 0 ? $eventLatest : $commentLatest;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    $siteId = (int)($_GET['site_id'] ?? current_site_id());
    if ($siteId <= 0) {
        json_response(['ok' => false, 'error' => 'No active site configured.'], 422);
    }
    if (!can_access_site($siteId)) {
        json_response(['ok' => false, 'error' => 'Not authorized for this site.'], 403);
    }

    $checkpointId = (int)($_GET['checkpoint_id'] ?? 0);
    if ($checkpointId > 0) {
        if (!is_admin() && !can_access_checkpoint($siteId, $checkpointId)) {
            json_response(['ok' => false, 'error' => 'Not authorized for this checkpoint.'], 403);
        }
        $checkStmt = db_prepare('SELECT id FROM checkpoints WHERE id = ? AND site_id = ? LIMIT 1');
        $checkStmt->bind_param('ii', $checkpointId, $siteId);
        $checkStmt->execute();
        $checkpointExists = (bool)$checkStmt->get_result()?->fetch_assoc();
        $checkStmt->close();
        if (!$checkpointExists) {
            json_response(['ok' => false, 'error' => 'Invalid site/checkpoint.'], 422);
        }
    }

    $studyPeriod = normalize_study_period((string)($_GET['study_period'] ?? 'morning'));
    $studyDateProvided = isset($_GET['study_date']) && trim((string)$_GET['study_date']) !== '';
    $requestedStudyDate = normalize_study_date((string)($_GET['study_date'] ?? date('Y-m-d'))) ?? date('Y-m-d');
    $studyDate = $requestedStudyDate;
    $comments = load_period_comments_rows($siteId, $studyDate, $studyPeriod, $checkpointId);

    if (!$studyDateProvided && count($comments) === 0) {
        [$periodStart, $periodEnd] = period_time_bounds($studyPeriod);
        $from = $studyDate . ' ' . $periodStart;
        $to = $studyDate . ' ' . $periodEnd;
        if ($checkpointId > 0) {
            $eventCheckStmt = db_prepare('SELECT id FROM traffic_events WHERE site_id = ? AND checkpoint_id = ? AND event_time >= ? AND event_time <= ? LIMIT 1');
            $eventCheckStmt->bind_param('iiss', $siteId, $checkpointId, $from, $to);
        } else {
            $eventCheckStmt = db_prepare('SELECT id FROM traffic_events WHERE site_id = ? AND event_time >= ? AND event_time <= ? LIMIT 1');
            $eventCheckStmt->bind_param('iss', $siteId, $from, $to);
        }
        $eventCheckStmt->execute();
        $hasEventsForDate = (bool)$eventCheckStmt->get_result()?->fetch_assoc();
        $eventCheckStmt->close();

        if (!$hasEventsForDate) {
            $latestDate = latest_period_date_with_data($siteId, $studyPeriod, $checkpointId);
            if ($latestDate !== null && $latestDate !== $studyDate) {
                $studyDate = $latestDate;
                $comments = load_period_comments_rows($siteId, $studyDate, $studyPeriod, $checkpointId);
            }
        }
    }

    json_response([
        'ok' => true,
        'site_id' => $siteId,
        'checkpoint_id' => $checkpointId,
        'study_period' => $studyPeriod,
        'requested_study_date' => $requestedStudyDate,
        'study_date' => $studyDate,
        'comments' => $comments,
    ]);
}

if ($method === 'POST') {
    require_csrf_api();

    $siteId = (int)($_POST['site_id'] ?? 0);
    $checkpointId = (int)($_POST['checkpoint_id'] ?? 0);
    $studyPeriodRaw = strtolower(trim((string)($_POST['study_period'] ?? '')));
    $studyPeriod = $studyPeriodRaw;
    $studyDate = normalize_study_date((string)($_POST['study_date'] ?? ''));
    $commentText = trim((string)($_POST['comment_text'] ?? ''));
    $userId = current_user_id();

    if ($siteId <= 0 || $checkpointId <= 0 || $studyDate === null) {
        json_response(['ok' => false, 'error' => 'Missing required fields.'], 422);
    }
    if ($userId <= 0) {
        json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
    if (!in_array($studyPeriodRaw, ['morning', 'afternoon'], true)) {
        json_response(['ok' => false, 'error' => 'Invalid study period.'], 422);
    }
    if (!can_access_checkpoint($siteId, $checkpointId)) {
        json_response(['ok' => false, 'error' => 'Not authorized for this checkpoint.'], 403);
    }
    $commentLength = function_exists('mb_strlen') ? (int)mb_strlen($commentText, 'UTF-8') : strlen($commentText);
    if ($commentLength > 1000) {
        json_response(['ok' => false, 'error' => 'Comment must be 1000 characters or less.'], 422);
    }

    $checkStmt = db_prepare('SELECT id FROM checkpoints WHERE id = ? AND site_id = ? LIMIT 1');
    $checkStmt->bind_param('ii', $checkpointId, $siteId);
    $checkStmt->execute();
    $checkpointExists = (bool)$checkStmt->get_result()?->fetch_assoc();
    $checkStmt->close();
    if (!$checkpointExists) {
        json_response(['ok' => false, 'error' => 'Invalid site/checkpoint.'], 422);
    }

    if ($commentText === '') {
        $deleteStmt = db_prepare('DELETE FROM study_period_comments WHERE site_id = ? AND checkpoint_id = ? AND user_id = ? AND study_date = ? AND study_period = ?');
        $deleteStmt->bind_param('iiiss', $siteId, $checkpointId, $userId, $studyDate, $studyPeriod);
        $deleteStmt->execute();
        $deletedRows = (int)$deleteStmt->affected_rows;
        $deleteStmt->close();

        json_response([
            'ok' => true,
            'deleted' => true,
            'deleted_rows' => $deletedRows,
            'site_id' => $siteId,
            'checkpoint_id' => $checkpointId,
            'user_id' => $userId,
            'study_date' => $studyDate,
            'study_period' => $studyPeriod,
        ]);
    }

    $upsertStmt = db_prepare('INSERT INTO study_period_comments (site_id, checkpoint_id, user_id, study_date, study_period, comment_text)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE comment_text = VALUES(comment_text), updated_at = CURRENT_TIMESTAMP');
    $upsertStmt->bind_param('iiisss', $siteId, $checkpointId, $userId, $studyDate, $studyPeriod, $commentText);
    $ok = $upsertStmt->execute();
    $upsertStmt->close();
    if (!$ok) {
        json_response(['ok' => false, 'error' => 'Failed to save session comment.'], 500);
    }

    $savedStmt = db_prepare('SELECT spc.id, spc.site_id, spc.checkpoint_id, spc.user_id, spc.study_date, spc.study_period, spc.comment_text, spc.created_at, spc.updated_at,
        c.checkpoint_code, c.display_name AS checkpoint_name, u.username AS collector_username
        FROM study_period_comments spc
        INNER JOIN checkpoints c ON c.id = spc.checkpoint_id
        INNER JOIN users u ON u.id = spc.user_id
        WHERE spc.site_id = ? AND spc.checkpoint_id = ? AND spc.user_id = ? AND spc.study_date = ? AND spc.study_period = ?
        LIMIT 1');
    $savedStmt->bind_param('iiiss', $siteId, $checkpointId, $userId, $studyDate, $studyPeriod);
    $savedStmt->execute();
    $saved = $savedStmt->get_result()?->fetch_assoc() ?: null;
    $savedStmt->close();

    if (!$saved) {
        json_response(['ok' => false, 'error' => 'Session comment saved but could not be reloaded.'], 500);
    }

    $checkpointName = trim((string)($saved['checkpoint_name'] ?? ''));
    $checkpointCode = trim((string)($saved['checkpoint_code'] ?? ''));
    $saved['checkpoint_label'] = $checkpointName !== '' && $checkpointCode !== ''
        ? $checkpointName . ' (' . $checkpointCode . ')'
        : ($checkpointName !== '' ? $checkpointName : $checkpointCode);
    $saved['id'] = (int)($saved['id'] ?? 0);
    $saved['site_id'] = (int)($saved['site_id'] ?? 0);
    $saved['checkpoint_id'] = (int)($saved['checkpoint_id'] ?? 0);
    $saved['user_id'] = (int)($saved['user_id'] ?? 0);

    json_response([
        'ok' => true,
        'deleted' => false,
        'comment' => $saved,
    ]);
}

json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
