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
    <div>
      <label>&nbsp;</label>
      <button type="button" id="exportBtn" class="secondary">Export Matches CSV</button>
      <button type="button" id="exportAllBtn" class="secondary">Export All Events CSV</button>
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

function csvEscape(v) {
  const s = String(v ?? '');
  return `"${s.replaceAll('"', '""')}"`;
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

function exportCsv() {
  const rows = [['in_event_id','out_event_id','in_time','in_checkpoint','out_time','out_checkpoint','distance_miles','elapsed_minutes','expected_minutes','avg_speed_mph','confidence','plate_score','type_score','color_score','plate_in','plate_out','vehicle_type_in','vehicle_color_in','vehicle_type_out','vehicle_color_out']];
  for (const m of matchesCache) {
    rows.push([
      m.in_event.id,
      m.out_event.id,
      m.in_event.event_time,
      m.in_event.checkpoint_name,
      m.out_event.event_time,
      m.out_event.checkpoint_name,
      m.distance_miles,
      m.elapsed_minutes,
      m.expected_minutes,
      m.avg_speed_mph,
      m.confidence,
      m.plate_score ?? '',
      m.type_score ?? '',
      m.color_score ?? '',
      m.in_event.plate_raw || '',
      m.out_event.plate_raw || '',
      m.in_event.vehicle_type || '',
      m.in_event.vehicle_color || '',
      m.out_event.vehicle_type || '',
      m.out_event.vehicle_color || '',
    ]);
  }
  const csv = rows.map(r => r.map(csvEscape).join(',')).join('\n');
  const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'cut_through_matches.csv';
  a.click();
  URL.revokeObjectURL(a.href);
}

function exportAllEventsCsv() {
  const siteId = document.getElementById('site_id').value;
  const studyPeriod = document.getElementById('study_period').value;
  if (!siteId) {
    alert('No site is selected.');
    return;
  }
  const url = `api/export_events_csv.php?site_id=${encodeURIComponent(siteId)}&study_period=${encodeURIComponent(studyPeriod)}`;
  window.location.href = url;
}

async function loadDetails() {
  const siteId = Number(siteSelect?.value || 0);
  const studyPeriod = studyPeriodSelect?.value || 'morning';
  const matchesSections = document.getElementById('matchesSections');
  const arrivals = document.getElementById('arrivalsBody');
  const dep = document.getElementById('departuresBody');
  const arrivalsSummary = document.getElementById('arrivalsSummary');
  const departuresSummary = document.getElementById('departuresSummary');
  if (!matchesSections || !arrivals || !dep) return;
  if (!siteId) {
    matchesCache = [];
    matchesSections.innerHTML = '<div class="small">No accessible site is assigned.</div>';
    arrivals.innerHTML = '<tr><td colspan="4">No accessible site is assigned.</td></tr>';
    dep.innerHTML = '<tr><td colspan="4">No accessible site is assigned.</td></tr>';
    if (arrivalsSummary) arrivalsSummary.textContent = 'Local Arrivals (In only) (0)';
    if (departuresSummary) departuresSummary.textContent = 'Local Departures (Out only) (0)';
    return;
  }

  let data = null;
  try {
    const res = await fetch(`api/dashboard_data.php?site_id=${siteId}&study_period=${studyPeriod}`);
    data = await res.json().catch(() => ({ ok: false, error: 'Invalid server response.' }));
  } catch (err) {
    data = { ok: false, error: 'Unable to load details.' };
  }
  if (!data.ok) {
    matchesCache = [];
    matchesSections.innerHTML = `<div class="small">${escapeHtml(data.error || 'Unable to load details.')}</div>`;
    arrivals.innerHTML = `<tr><td colspan="4">${escapeHtml(data.error || 'Unable to load arrivals.')}</td></tr>`;
    dep.innerHTML = `<tr><td colspan="4">${escapeHtml(data.error || 'Unable to load departures.')}</td></tr>`;
    if (arrivalsSummary) arrivalsSummary.textContent = 'Local Arrivals (In only) (0)';
    if (departuresSummary) departuresSummary.textContent = 'Local Departures (Out only) (0)';
    return;
  }

  matchesCache = data.matches || [];

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
  const arrivalsData = data.unmatched_in || [];
  const depData = data.unmatched_out || [];
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
}

if (siteSelect) siteSelect.addEventListener('change', loadDetails);
if (studyPeriodSelect) {
  studyPeriodSelect.addEventListener('change', loadDetails);
  studyPeriodSelect.value = currentStudyPeriod();
}
document.getElementById('exportBtn').addEventListener('click', exportCsv);
document.getElementById('exportAllBtn').addEventListener('click', exportAllEventsCsv);
loadDetails();
</script>
<?php render_foot(); ?>
