<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$siteId = (int)($_GET['site_id'] ?? current_site_id());
if ($siteId <= 0) {
    json_response(['ok' => false, 'error' => 'No active site configured.'], 422);
}
if (!can_access_site($siteId)) {
    json_response(['ok' => false, 'error' => 'Not authorized for this site.'], 403);
}

$requestedStudyDate = trim((string)($_GET['study_date'] ?? date('Y-m-d')));
$studyDate = $requestedStudyDate;
$dateObj = DateTime::createFromFormat('Y-m-d', $studyDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $studyDate) {
    $studyDate = date('Y-m-d');
}

$speedMph = (float)app_setting('speed_mph', '25');
$bufferMinutes = (float)app_setting('buffer_minutes', '1');
$minConfidence = (int)app_setting('min_confidence', '70');

$loadEvents = static function (int $siteId, string $from, string $to): array {
    $eventStmt = db_prepare('SELECT e.id, e.site_id, e.checkpoint_id, c.display_name AS checkpoint_name, c.checkpoint_code, e.direction, e.plate_raw, e.plate_norm, e.vehicle_type, e.vehicle_color, e.notes, e.observer_name, e.event_time FROM traffic_events e INNER JOIN checkpoints c ON c.id = e.checkpoint_id WHERE e.site_id = ? AND e.event_time >= ? AND e.event_time <= ? ORDER BY e.event_time ASC');
    $eventStmt->bind_param('iss', $siteId, $from, $to);
    $eventStmt->execute();
    $rows = $eventStmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
    $eventStmt->close();
    return $rows;
};

$morningEvents = $loadEvents($siteId, $studyDate . ' 00:00:00', $studyDate . ' 11:59:59');
$afternoonEvents = $loadEvents($siteId, $studyDate . ' 12:00:00', $studyDate . ' 23:59:59');
$allEvents = array_merge($morningEvents, $afternoonEvents);
$distanceMap = distance_map_for_site($siteId);
$morningAnalysis = classify_events($morningEvents, $distanceMap, $speedMph, $bufferMinutes, $minConfidence);
$afternoonAnalysis = classify_events($afternoonEvents, $distanceMap, $speedMph, $bufferMinutes, $minConfidence);
$repeatAnalysis = analyze_repeat_cut_throughs($morningAnalysis['matches'] ?? [], $afternoonAnalysis['matches'] ?? [], $minConfidence);
$morningNonCutThrough = analyze_non_cut_through_in_out_pairs($morningAnalysis['unmatched_in'] ?? [], $morningAnalysis['unmatched_out'] ?? [], $distanceMap, $speedMph, $bufferMinutes, $minConfidence);
$afternoonNonCutThrough = analyze_non_cut_through_in_out_pairs($afternoonAnalysis['unmatched_in'] ?? [], $afternoonAnalysis['unmatched_out'] ?? [], $distanceMap, $speedMph, $bufferMinutes, $minConfidence);

$plateCounts = [];
foreach ($allEvents as $event) {
    $plateNorm = normalize_plate((string)($event['plate_norm'] ?? $event['plate_raw'] ?? ''));
    if ($plateNorm === '') {
        continue;
    }
    $plateCounts[$plateNorm] = ($plateCounts[$plateNorm] ?? 0) + 1;
}
$allDataPlate4xCount = 0;
foreach ($plateCounts as $count) {
    if ((int)$count >= 4) {
        $allDataPlate4xCount++;
    }
}

json_response([
    'ok' => true,
    'site_id' => $siteId,
    'requested_study_date' => $requestedStudyDate,
    'study_date' => $studyDate,
    'identity_basis' => 'cut_through_confidence_plate_type_color',
    'route_rule' => 'any_route',
    'repeat_match_min_confidence' => $minConfidence,
    'summary' => [
        'repeat_vehicle_count' => (int)($repeatAnalysis['repeat_vehicle_count'] ?? 0),
        'morning_unique_cut_through_vehicle_count' => (int)($repeatAnalysis['morning_unique_cut_through_vehicle_count'] ?? 0),
        'afternoon_unique_cut_through_vehicle_count' => (int)($repeatAnalysis['afternoon_unique_cut_through_vehicle_count'] ?? 0),
        'skipped_incomplete_match_count' => (int)($repeatAnalysis['skipped_incomplete_match_count'] ?? 0),
        'all_data_plate_4x_count' => $allDataPlate4xCount,
        'same_checkpoint_in_out_count' => (int)($morningNonCutThrough['same_checkpoint_count'] ?? 0) + (int)($afternoonNonCutThrough['same_checkpoint_count'] ?? 0),
        'different_checkpoint_outside_window_count' => (int)($morningNonCutThrough['different_checkpoint_outside_window_count'] ?? 0) + (int)($afternoonNonCutThrough['different_checkpoint_outside_window_count'] ?? 0),
    ],
    'rows' => array_values($repeatAnalysis['repeat_vehicle_rows'] ?? []),
]);
