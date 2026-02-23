<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

$siteId = current_site_id();
$activeSite = $siteId > 0 ? site_by_id($siteId) : null;
render_head('Dashboard');
?>
<section class="card">
  <h1>Dashboard</h1>
  <p class="small">Auto-refreshes every <span id="pollLabel">10</span> seconds. Cut-through is calculated with expected travel time from checkpoint distance and speed setting.</p>
  <p class="small">All times shown in Eastern Time (ET).</p>
  <?php if (!$activeSite): ?>
    <p class="status warn">No active site is configured. Set one in Site Setup.</p>
  <?php else: ?>
  <p class="small"><strong>Active Site:</strong> <?= h((string)$activeSite['name']) ?></p>
  <div class="form-row">
    <div>
      <label>Study Period</label>
      <select id="study_period">
        <option value="morning" selected>Morning Study</option>
        <option value="afternoon">Afternoon Study</option>
      </select>
    </div>
    <div>
      <label>&nbsp;</label>
      <button id="refreshBtn" type="button">Refresh Now</button>
    </div>
  </div>
  <div class="card" style="margin-top:0.75rem; padding:0.75rem;">
    <h2 style="margin-bottom:0.4rem;">Selected Site</h2>
    <p class="small" id="siteCardName" style="margin-top:0;"><?= h((string)$activeSite['name']) ?></p>
    <?php if (!empty($activeSite['image_path'])): ?>
      <img id="siteCardImage" class="site-preview" alt="Selected site image" src="<?= h((string)$activeSite['image_path']) ?>">
      <p class="small" id="siteCardNoImage" style="display:none; margin-top:0.55rem;">No site image uploaded for this site yet.</p>
    <?php else: ?>
      <img id="siteCardImage" class="site-preview" alt="Selected site image" style="display:none;">
      <p class="small" id="siteCardNoImage" style="margin-top:0.55rem;">No site image uploaded for this site yet.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>

<section class="grid three" style="margin-top:1rem;" id="kpis"></section>

<section class="grid two" style="margin-top:1rem;">
  <article class="card">
    <h2>Checkpoint Counts</h2>
    <table>
      <thead><tr><th>Checkpoint</th><th>In</th><th>Out</th><th>Total (Two-Way)</th></tr></thead>
      <tbody id="checkpointBody"></tbody>
    </table>
  </article>
  <article class="card">
    <h2>Cut-Through Matches</h2>
    <table>
      <thead><tr><th>In Event #</th><th>Out Event #</th><th>In</th><th>Out</th><th>Elapsed</th><th>Expected</th><th>Avg Speed</th><th>Confidence</th></tr></thead>
      <tbody id="matchBody"></tbody>
    </table>
  </article>
</section>

<section class="card" style="margin-top:1rem;">
  <h2>Recent Events</h2>
  <table>
    <thead><tr><th>Event #</th><th>Time</th><th>Checkpoint</th><th>Dir</th><th>Plate</th><th>Type</th><th>Color</th><th>Observer</th></tr></thead>
    <tbody id="recentBody"></tbody>
  </table>
</section>

<script>
let pollMs = 10000;
let timer;
const activeSiteId = <?= (int)$siteId ?>;
const refreshBtn = document.getElementById('refreshBtn');
const studyPeriodSelect = document.getElementById('study_period');

function currentStudyPeriod() {
  const hourEt = Number(new Intl.DateTimeFormat('en-US', {
    hour: 'numeric',
    hour12: false,
    timeZone: 'America/New_York'
  }).format(new Date()));
  return hourEt < 12 ? 'morning' : 'afternoon';
}

function kpiCard(label, value, css='') {
  return `<article class="card"><div class="kpi ${css}">${value}</div><div class="kpi-label">${label}</div></article>`;
}

function matchRouteKey(match) {
  const inCode = match?.in_event?.checkpoint_code || match?.in_event?.checkpoint_name || 'In';
  const outCode = match?.out_event?.checkpoint_code || match?.out_event?.checkpoint_name || 'Out';
  return `${inCode} -> ${outCode}`;
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

function formatKpiDateTime(value) {
  return formatEtDateTime(value, true);
}

function formatEtDateTime(value, longMonth = false) {
  const raw = String(value || '').trim();
  if (!raw) return '--';
  const m = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?$/);
  if (!m) return raw;
  const year = Number(m[1]);
  const month = Number(m[2]);
  const day = Number(m[3]);
  const hour24 = Number(m[4]);
  const minute = m[5];
  const monthName = new Intl.DateTimeFormat('en-US', { month: longMonth ? 'long' : 'short' }).format(new Date(year, month - 1, day));
  const ampm = hour24 >= 12 ? 'PM' : 'AM';
  const hour12 = (hour24 % 12) || 12;
  return `${monthName} ${day}, ${year}, ${hour12}:${minute} ${ampm} ET`;
}

async function loadDashboard() {
  if (!activeSiteId || !studyPeriodSelect) return;
  const studyPeriod = studyPeriodSelect.value;
  const res = await fetch(`api/dashboard_data.php?site_id=${activeSiteId}&study_period=${studyPeriod}`);
  const json = await res.json();
  if (!json.ok) return;

  pollMs = Math.max(5000, Number(json.settings.poll_seconds || 10) * 1000);
  document.getElementById('pollLabel').textContent = String(pollMs / 1000);

  const summary = json.summary;
  const policyStatus = summary.meets_policy ? 'Meets 25% Policy' : 'Below 25% Policy';
  const policyClass = summary.meets_policy ? 'ok' : 'warn';
  const startTime = formatKpiDateTime(summary.start_time);
  const endTime = formatKpiDateTime(summary.end_time);
  const avgCutThroughSpeed = json.matches.length
    ? (json.matches.reduce((acc, m) => acc + Number(m.avg_speed_mph || 0), 0) / json.matches.length).toFixed(2)
    : '0.00';

  document.getElementById('kpis').innerHTML = [
    kpiCard('Start Time (First Entry)', startTime),
    kpiCard('End Time (Last Entry)', endTime),
    kpiCard('Total (Two-Way)', summary.total_volume),
    kpiCard('Cut-Through Vehicles', summary.cut_through_count),
    kpiCard('Cut-Through %', `${summary.cut_through_percent}%`, policyClass),
    kpiCard('Avg Cut-Through Speed', `${avgCutThroughSpeed} mph`),
    kpiCard('Policy Status', policyStatus, policyClass),
    kpiCard('Local Arrivals (In only)', summary.local_arrivals_count),
    kpiCard('Local Departures (Out only)', summary.local_departures_count),
  ].join('');

  const cpBody = document.getElementById('checkpointBody');
  cpBody.innerHTML = '';
  Object.entries(json.checkpoint_counts).forEach(([name, c]) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${name}</td><td>${c.in}</td><td>${c.out}</td><td>${c.total}</td>`;
    cpBody.appendChild(tr);
  });

  const matchBody = document.getElementById('matchBody');
  matchBody.innerHTML = '';
  const routeGroups = groupMatchesByRoute(json.matches || []);
  if (routeGroups.length === 0) {
    matchBody.innerHTML = '<tr><td colspan="8">No cut-through matches in this period.</td></tr>';
  } else {
    for (const [route, matches] of routeGroups) {
      const section = document.createElement('tr');
      section.innerHTML = `<td colspan="8" style="background:#eef2ff; font-weight:700;">${route} (${matches.length})</td>`;
      matchBody.appendChild(section);

      matches.forEach((m) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${m.in_event.id}</td>
          <td>${m.out_event.id}</td>
          <td>${m.in_event.checkpoint_name} ${formatEtDateTime(m.in_event.event_time)}</td>
          <td>${m.out_event.checkpoint_name} ${formatEtDateTime(m.out_event.event_time)}</td>
          <td>${m.elapsed_minutes} min</td>
          <td>${m.expected_minutes} min</td>
          <td>${m.avg_speed_mph} mph</td>
          <td>${m.confidence}</td>`;
        matchBody.appendChild(tr);
      });
    }
  }

  const recentBody = document.getElementById('recentBody');
  recentBody.innerHTML = '';
  json.recent_events.forEach(e => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${e.id}</td><td>${formatEtDateTime(e.event_time)}</td><td>${e.checkpoint_name}</td><td>${e.direction}</td><td>${e.plate_raw || ''}</td><td>${e.vehicle_type}</td><td>${e.vehicle_color}</td><td>${e.observer_name || ''}</td>`;
    recentBody.appendChild(tr);
  });

  if (timer) clearTimeout(timer);
  timer = setTimeout(loadDashboard, pollMs);
}

if (refreshBtn) refreshBtn.addEventListener('click', loadDashboard);
if (studyPeriodSelect) {
  studyPeriodSelect.addEventListener('change', loadDashboard);
  studyPeriodSelect.value = currentStudyPeriod();
}
loadDashboard();
</script>
<?php render_foot(); ?>
