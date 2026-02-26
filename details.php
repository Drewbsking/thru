<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

$sites = scoped_sites_for_current_user();
$defaultSiteId = is_admin() ? current_site_id() : (int)($sites[0]['id'] ?? 0);
$siteId = (int)($_GET['site_id'] ?? $defaultSiteId);
if ($siteId <= 0) {
    $siteId = $defaultSiteId;
}
if (!is_admin()) {
    $allowedSiteIds = array_map(static fn(array $site): int => (int)$site['id'], $sites);
    if (!in_array($siteId, $allowedSiteIds, true)) {
        $siteId = (int)($sites[0]['id'] ?? 0);
    }
}
render_head('Cut-Through Details');
?>
<section class="card">
  <p class="small">High confidence matches are paired one-to-one. Unmatched In events are treated as local arrivals, unmatched Out events as local departures.</p>
  <p class="small">All times shown in Eastern Time (ET).</p>
  <?php if (count($sites) === 0): ?>
    <p class="status warn">No checkpoint assignment found for your account.</p>
  <?php endif; ?>
  <div class="form-row">
    <div>
      <label>Site</label>
      <select id="site_id">
        <?php foreach ($sites as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $siteId ? 'selected' : '' ?>><?= h($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Study Period</label>
      <select id="study_period">
        <option value="morning" selected>Morning Study</option>
        <option value="afternoon">Afternoon Study</option>
      </select>
    </div>
  </div>
</section>

<section class="card" style="margin-top:1rem;">
  <h2>Matched Cut-Through Events</h2>
  <p class="small">Expand each route heading to view its matched rows.</p>
  <div id="matchesSections"></div>
</section>

<section class="grid two" style="margin-top:1rem;">
  <article class="card">
    <details class="section-collapse">
      <summary id="arrivalsSummary">Local Arrivals (In only)</summary>
      <div class="section-collapse-body">
        <table><thead><tr><th>Time</th><th>Checkpoint</th><th>Plate</th><th>Vehicle</th></tr></thead><tbody id="arrivalsBody"></tbody></table>
      </div>
    </details>
  </article>
  <article class="card">
    <details class="section-collapse">
      <summary id="departuresSummary">Local Departures (Out only)</summary>
      <div class="section-collapse-body">
        <table><thead><tr><th>Time</th><th>Checkpoint</th><th>Plate</th><th>Vehicle</th></tr></thead><tbody id="departuresBody"></tbody></table>
      </div>
    </details>
  </article>
</section>

<section class="card" style="margin-top:1rem;">
  <h2>Session Observations</h2>
  <details class="section-collapse">
    <summary id="sessionCommentsSummary">Session Observations (0)</summary>
    <div class="section-collapse-body">
      <table>
        <thead><tr><th>Checkpoint</th><th>Collector</th><th>Period</th><th>Last Updated (ET)</th><th>Observation Comment</th></tr></thead>
        <tbody id="sessionCommentsBody"></tbody>
      </table>
    </div>
  </details>
</section>

<section class="card" style="margin-top:1rem;">
  <h2>Data Collection Details</h2>
  <p class="small">Complete list of raw recordings for the selected site and study period.</p>
  <details class="section-collapse">
    <summary id="allEventsSummary">All Recordings (Newest First)</summary>
    <div class="section-collapse-body">
      <table>
        <thead>
          <tr><th>Event #</th><th>Time</th><th>Checkpoint</th><th>Dir</th><th>Plate</th><th>Vehicle</th><th>Observer</th><th>Comments</th></tr>
        </thead>
        <tbody id="allEventsBody"></tbody>
      </table>
    </div>
  </details>
  <details class="section-collapse" style="margin-top:0.75rem;">
    <summary id="byLocationSummary">Recordings by Location</summary>
    <div class="section-collapse-body">
      <div id="eventsByLocation"></div>
    </div>
  </details>
</section>

<script>
let matchesCache = [];
const siteSelect = document.getElementById('site_id');
const studyPeriodSelect = document.getElementById('study_period');

function currentStudyPeriod() {
  const hourEt = Number(new Intl.DateTimeFormat('en-US', {
    hour: 'numeric',
    hour12: false,
    timeZone: 'America/New_York'
  }).format(new Date()));
  return hourEt < 12 ? 'morning' : 'afternoon';
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function formatWholeSpeed(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0';
  return String(Math.round(n));
}

function matchRouteKey(match) {
  const inName = String(match?.in_event?.checkpoint_name || match?.in_event?.checkpoint_code || 'In').trim();
  const outName = String(match?.out_event?.checkpoint_name || match?.out_event?.checkpoint_code || 'Out').trim();
  return `${inName} to ${outName}`;
}

function groupMatchesByRoute(matches) {
  const grouped = new Map();
  for (const match of (matches || [])) {
    const key = matchRouteKey(match);
    if (!grouped.has(key)) grouped.set(key, []);
    grouped.get(key).push(match);
  }
  return Array.from(grouped.entries()).sort((a, b) => a[0].localeCompare(b[0], undefined, { numeric: true, sensitivity: 'base' }));
}

function formatEtDateTime(value) {
  const raw = String(value || '').trim();
  if (!raw) return '--';
  const m = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?$/);
  if (!m) return raw;
  const year = Number(m[1]);
  const month = Number(m[2]);
  const day = Number(m[3]);
  const hour24 = Number(m[4]);
  const minute = m[5];
  const monthName = new Intl.DateTimeFormat('en-US', { month: 'short' }).format(new Date(year, month - 1, day));
  const ampm = hour24 >= 12 ? 'PM' : 'AM';
  const hour12 = (hour24 % 12) || 12;
  return `${monthName} ${day}, ${year}, ${hour12}:${minute} ${ampm} ET`;
}

function formatStudyPeriodLabel(period) {
  return String(period || '').toLowerCase() === 'afternoon' ? 'Afternoon Study' : 'Morning Study';
}

function commentCellHtml(value) {
  return escapeHtml(String(value ?? '')).replace(/\n/g, '<br>');
}

function eventVehicleLabel(event) {
  const type = String(event?.vehicle_type || '-');
  const color = String(event?.vehicle_color || '-');
  return `${type} / ${color}`;
}

function eventCheckpointLabel(event) {
  const name = String(event?.checkpoint_name || '').trim();
  if (name !== '') return name;
  const code = String(event?.checkpoint_code || '').trim();
  if (code !== '') return code;
  return 'Unknown';
}

function groupEventsByLocation(events, checkpoints) {
  const checkpointOrder = new Map();
  for (let i = 0; i < (checkpoints || []).length; i++) {
    const checkpointId = Number(checkpoints[i]?.id || 0);
    if (checkpointId > 0) checkpointOrder.set(checkpointId, i);
  }

  const groups = new Map();
  for (const event of (events || [])) {
    const checkpointId = Number(event?.checkpoint_id || 0);
    const name = eventCheckpointLabel(event);
    const key = checkpointId > 0 ? `id:${checkpointId}` : `name:${name.toLowerCase()}`;
    if (!groups.has(key)) {
      groups.set(key, { checkpointId, name, events: [] });
    }
    groups.get(key).events.push(event);
  }

  return Array.from(groups.values()).sort((a, b) => {
    const aOrder = checkpointOrder.has(a.checkpointId) ? checkpointOrder.get(a.checkpointId) : Number.MAX_SAFE_INTEGER;
    const bOrder = checkpointOrder.has(b.checkpointId) ? checkpointOrder.get(b.checkpointId) : Number.MAX_SAFE_INTEGER;
    if (aOrder !== bOrder) return aOrder - bOrder;
    return a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' });
  });
}

function compareDateStringsDesc(a, b) {
  const aValue = String(a || '').trim();
  const bValue = String(b || '').trim();
  if (aValue === bValue) return 0;
  if (aValue === '') return 1;
  if (bValue === '') return -1;
  return aValue < bValue ? 1 : -1;
}

function renderAllEvents(events, allEventsBody, allEventsSummary) {
  allEventsBody.innerHTML = '';
  if (allEventsSummary) allEventsSummary.textContent = `All Recordings (Newest First) (${events.length})`;
  if (events.length === 0) {
    allEventsBody.innerHTML = '<tr><td colspan="8">No recordings in this period.</td></tr>';
    return;
  }
  for (const event of events) {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${escapeHtml(event.id)}</td><td>${escapeHtml(formatEtDateTime(event.event_time))}</td><td>${escapeHtml(eventCheckpointLabel(event))}</td><td>${escapeHtml(event.direction || '')}</td><td>${escapeHtml(event.plate_raw || '')}</td><td>${escapeHtml(eventVehicleLabel(event))}</td><td>${escapeHtml(event.observer_name || '')}</td><td>${escapeHtml(event.notes || '')}</td>`;
    allEventsBody.appendChild(tr);
  }
}

function renderEventsByLocation(events, checkpoints, eventsByLocation, byLocationSummary) {
  eventsByLocation.innerHTML = '';
  const groups = groupEventsByLocation(events, checkpoints);
  if (byLocationSummary) byLocationSummary.textContent = `Recordings by Location (${groups.length} checkpoints)`;
  if (groups.length === 0) {
    eventsByLocation.innerHTML = '<div class="small">No recordings in this period.</div>';
    return;
  }

  for (const group of groups) {
    const section = document.createElement('details');
    section.className = 'section-collapse';

    const summary = document.createElement('summary');
    summary.textContent = `${group.name} (${group.events.length})`;
    section.appendChild(summary);

    const body = document.createElement('div');
    body.className = 'section-collapse-body';

    const table = document.createElement('table');
    table.innerHTML = '<thead><tr><th>Event #</th><th>Time</th><th>Dir</th><th>Plate</th><th>Vehicle</th><th>Observer</th><th>Comments</th></tr></thead>';
    const tbody = document.createElement('tbody');
    for (const event of group.events) {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${escapeHtml(event.id)}</td><td>${escapeHtml(formatEtDateTime(event.event_time))}</td><td>${escapeHtml(event.direction || '')}</td><td>${escapeHtml(event.plate_raw || '')}</td><td>${escapeHtml(eventVehicleLabel(event))}</td><td>${escapeHtml(event.observer_name || '')}</td><td>${escapeHtml(event.notes || '')}</td>`;
      tbody.appendChild(tr);
    }
    table.appendChild(tbody);
    body.appendChild(table);
    section.appendChild(body);
    eventsByLocation.appendChild(section);
  }
}

async function loadDetails() {
  const siteId = Number(siteSelect?.value || 0);
  const studyPeriod = studyPeriodSelect?.value || 'morning';
  const matchesSections = document.getElementById('matchesSections');
  const arrivals = document.getElementById('arrivalsBody');
  const dep = document.getElementById('departuresBody');
  const sessionCommentsBody = document.getElementById('sessionCommentsBody');
  const allEventsBody = document.getElementById('allEventsBody');
  const eventsByLocation = document.getElementById('eventsByLocation');
  const arrivalsSummary = document.getElementById('arrivalsSummary');
  const departuresSummary = document.getElementById('departuresSummary');
  const sessionCommentsSummary = document.getElementById('sessionCommentsSummary');
  const allEventsSummary = document.getElementById('allEventsSummary');
  const byLocationSummary = document.getElementById('byLocationSummary');
  if (!matchesSections || !arrivals || !dep || !sessionCommentsBody || !allEventsBody || !eventsByLocation) return;
  if (!siteId) {
    matchesCache = [];
    matchesSections.innerHTML = '<div class="small">No accessible site is assigned.</div>';
    arrivals.innerHTML = '<tr><td colspan="4">No accessible site is assigned.</td></tr>';
    dep.innerHTML = '<tr><td colspan="4">No accessible site is assigned.</td></tr>';
    sessionCommentsBody.innerHTML = '<tr><td colspan="5">No accessible site is assigned.</td></tr>';
    allEventsBody.innerHTML = '<tr><td colspan="8">No accessible site is assigned.</td></tr>';
    eventsByLocation.innerHTML = '<div class="small">No accessible site is assigned.</div>';
    if (arrivalsSummary) arrivalsSummary.textContent = 'Local Arrivals (In only) (0)';
    if (departuresSummary) departuresSummary.textContent = 'Local Departures (Out only) (0)';
    if (sessionCommentsSummary) sessionCommentsSummary.textContent = 'Session Observations (0)';
    if (allEventsSummary) allEventsSummary.textContent = 'All Recordings (Newest First) (0)';
    if (byLocationSummary) byLocationSummary.textContent = 'Recordings by Location (0 checkpoints)';
    return;
  }

  let data = null;
  try {
    const res = await fetch(`api/dashboard_data.php?site_id=${siteId}&study_period=${studyPeriod}&include_all_events=1`);
    data = await res.json().catch(() => ({ ok: false, error: 'Invalid server response.' }));
  } catch (err) {
    data = { ok: false, error: 'Unable to load details.' };
  }
  if (!data.ok) {
    matchesCache = [];
    matchesSections.innerHTML = `<div class="small">${escapeHtml(data.error || 'Unable to load details.')}</div>`;
    arrivals.innerHTML = `<tr><td colspan="4">${escapeHtml(data.error || 'Unable to load arrivals.')}</td></tr>`;
    dep.innerHTML = `<tr><td colspan="4">${escapeHtml(data.error || 'Unable to load departures.')}</td></tr>`;
    sessionCommentsBody.innerHTML = `<tr><td colspan="5">${escapeHtml(data.error || 'Unable to load session observations.')}</td></tr>`;
    allEventsBody.innerHTML = `<tr><td colspan="8">${escapeHtml(data.error || 'Unable to load recordings.')}</td></tr>`;
    eventsByLocation.innerHTML = `<div class="small">${escapeHtml(data.error || 'Unable to load recordings by location.')}</div>`;
    if (arrivalsSummary) arrivalsSummary.textContent = 'Local Arrivals (In only) (0)';
    if (departuresSummary) departuresSummary.textContent = 'Local Departures (Out only) (0)';
    if (sessionCommentsSummary) sessionCommentsSummary.textContent = 'Session Observations (0)';
    if (allEventsSummary) allEventsSummary.textContent = 'All Recordings (Newest First) (0)';
    if (byLocationSummary) byLocationSummary.textContent = 'Recordings by Location (0 checkpoints)';
    return;
  }

  matchesCache = (data.matches || []).slice().sort((a, b) => compareDateStringsDesc(
    a?.out_event?.event_time || a?.in_event?.event_time,
    b?.out_event?.event_time || b?.in_event?.event_time
  ));

  matchesSections.innerHTML = '';
  const routeGroups = groupMatchesByRoute(matchesCache);
  if (routeGroups.length === 0) {
    matchesSections.innerHTML = '<div class="small">No cut-through matches in this period.</div>';
  } else {
    const totalVolume = Number(data?.summary?.total_volume || 0);
    for (const [route, matches] of routeGroups) {
      const legPercent = totalVolume > 0 ? ((matches.length / totalVolume) * 100).toFixed(2) : '0.00';
      const legAvgSpeed = matches.length > 0
        ? (matches.reduce((acc, m) => acc + Number(m?.avg_speed_mph || 0), 0) / matches.length).toFixed(2)
        : '0.00';
      const section = document.createElement('details');
      section.className = 'section-collapse';
      const summary = document.createElement('summary');
      summary.innerHTML = `${escapeHtml(route)} (${escapeHtml(matches.length)}, ${escapeHtml(legPercent)}% of total volume, avg ${escapeHtml(legAvgSpeed)} mph)`;
      section.appendChild(summary);

      const body = document.createElement('div');
      body.className = 'section-collapse-body';
      const table = document.createElement('table');
      table.innerHTML = '<thead><tr><th>In Event #</th><th>Out Event #</th><th>In Time</th><th>In CP</th><th>Out Time</th><th>Out CP</th><th>Plate In</th><th>Plate Out</th><th>Distance</th><th>Elapsed</th><th>Expected</th><th>Avg Speed</th><th>Confidence</th><th>Score Detail</th><th>Vehicle In</th><th>Vehicle Out</th></tr></thead>';
      const tbody = document.createElement('tbody');
      for (const m of matches) {
        const plateIn = m.in_event.plate_raw || '';
        const plateOut = m.out_event.plate_raw || '';
        const vehicleIn = `${m.in_event.vehicle_type || '-'} / ${m.in_event.vehicle_color || '-'}`;
        const vehicleOut = `${m.out_event.vehicle_type || '-'} / ${m.out_event.vehicle_color || '-'}`;
        const scoreDetail = `P:${m.plate_score ?? 0} T:${m.type_score ?? 0} C:${m.color_score ?? 0}`;
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${escapeHtml(m.in_event.id)}</td><td>${escapeHtml(m.out_event.id)}</td><td>${escapeHtml(formatEtDateTime(m.in_event.event_time))}</td><td>${escapeHtml(m.in_event.checkpoint_name)}</td>
          <td>${escapeHtml(formatEtDateTime(m.out_event.event_time))}</td><td>${escapeHtml(m.out_event.checkpoint_name)}</td><td>${escapeHtml(plateIn)}</td><td>${escapeHtml(plateOut)}</td><td>${escapeHtml(m.distance_miles)}</td>
          <td>${escapeHtml(m.elapsed_minutes)}</td><td>${escapeHtml(m.expected_minutes)}</td><td>${escapeHtml(formatWholeSpeed(m.avg_speed_mph))} mph</td><td>${escapeHtml(m.confidence)}</td><td>${escapeHtml(scoreDetail)}</td><td>${escapeHtml(vehicleIn)}</td><td>${escapeHtml(vehicleOut)}</td>`;
        tbody.appendChild(tr);
      }
      table.appendChild(tbody);
      body.appendChild(table);
      section.appendChild(body);
      matchesSections.appendChild(section);
    }
  }

  arrivals.innerHTML = '';
  const arrivalsData = (data.unmatched_in || []).slice().sort((a, b) => compareDateStringsDesc(a?.event_time, b?.event_time));
  const depData = (data.unmatched_out || []).slice().sort((a, b) => compareDateStringsDesc(a?.event_time, b?.event_time));
  if (arrivalsSummary) arrivalsSummary.textContent = `Local Arrivals (In only) (${arrivalsData.length})`;
  if (departuresSummary) departuresSummary.textContent = `Local Departures (Out only) (${depData.length})`;

  if (arrivalsData.length === 0) {
    arrivals.innerHTML = '<tr><td colspan="4">No local arrivals in this period.</td></tr>';
  }
  for (const e of arrivalsData) {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${escapeHtml(formatEtDateTime(e.event_time))}</td><td>${escapeHtml(e.checkpoint_name)}</td><td>${escapeHtml(e.plate_raw || '')}</td><td>${escapeHtml(e.vehicle_type)} / ${escapeHtml(e.vehicle_color)}</td>`;
    arrivals.appendChild(tr);
  }

  dep.innerHTML = '';
  if (depData.length === 0) {
    dep.innerHTML = '<tr><td colspan="4">No local departures in this period.</td></tr>';
  }
  for (const e of depData) {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${escapeHtml(formatEtDateTime(e.event_time))}</td><td>${escapeHtml(e.checkpoint_name)}</td><td>${escapeHtml(e.plate_raw || '')}</td><td>${escapeHtml(e.vehicle_type)} / ${escapeHtml(e.vehicle_color)}</td>`;
    dep.appendChild(tr);
  }

  sessionCommentsBody.innerHTML = '';
  const sessionComments = (data.session_comments || []).slice().sort((a, b) => compareDateStringsDesc(a?.updated_at, b?.updated_at));
  if (sessionCommentsSummary) sessionCommentsSummary.textContent = `Session Observations (${sessionComments.length})`;
  if (sessionComments.length === 0) {
    sessionCommentsBody.innerHTML = '<tr><td colspan="5">No session observations in this period/date.</td></tr>';
  } else {
    for (const row of sessionComments) {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${escapeHtml(row.checkpoint_label || row.checkpoint_name || row.checkpoint_code || '--')}</td>
        <td>${escapeHtml(row.collector_username || '--')}</td>
        <td>${escapeHtml(formatStudyPeriodLabel(row.study_period || studyPeriod))}</td>
        <td>${escapeHtml(formatEtDateTime(row.updated_at || ''))}</td>
        <td>${commentCellHtml(row.comment_text || '')}</td>`;
      sessionCommentsBody.appendChild(tr);
    }
  }

  const allEvents = (data.all_events || []).slice().sort((a, b) => compareDateStringsDesc(a?.event_time, b?.event_time));
  renderAllEvents(allEvents, allEventsBody, allEventsSummary);
  renderEventsByLocation(allEvents, data.checkpoints || [], eventsByLocation, byLocationSummary);
}

if (siteSelect) siteSelect.addEventListener('change', loadDetails);
if (studyPeriodSelect) {
  studyPeriodSelect.addEventListener('change', loadDetails);
  studyPeriodSelect.value = currentStudyPeriod();
}
loadDetails();
</script>
<?php render_foot(); ?>
