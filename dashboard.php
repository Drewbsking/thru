<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

$siteId = (int)($_GET['site_id'] ?? current_site_id());
$sites = all_sites();
render_head('Dashboard');
?>
<section class="card">
  <h1>Live Dashboard</h1>
  <p class="small">Auto-refreshes every <span id="pollLabel">10</span> seconds. Cut-through is calculated with expected travel time from checkpoint distance and speed setting.</p>
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
      <label>Study Window</label>
      <select id="hours">
        <option value="6">Last 6 hours</option>
        <option value="24" selected>Last 24 hours</option>
        <option value="48">Last 48 hours</option>
        <option value="168">Last 7 days</option>
      </select>
    </div>
    <div>
      <label>&nbsp;</label>
      <button id="refreshBtn" type="button">Refresh Now</button>
    </div>
  </div>
</section>

<section class="grid three" style="margin-top:1rem;" id="kpis"></section>

<section class="grid two" style="margin-top:1rem;">
  <article class="card">
    <h2>Checkpoint Counts</h2>
    <table>
      <thead><tr><th>Checkpoint</th><th>In</th><th>Out</th><th>Total</th></tr></thead>
      <tbody id="checkpointBody"></tbody>
    </table>
  </article>
  <article class="card">
    <h2>Cut-Through Matches</h2>
    <table>
      <thead><tr><th>In</th><th>Out</th><th>Elapsed</th><th>Expected</th><th>Confidence</th></tr></thead>
      <tbody id="matchBody"></tbody>
    </table>
  </article>
</section>

<section class="card" style="margin-top:1rem;">
  <h2>Recent Events</h2>
  <table>
    <thead><tr><th>Time</th><th>Checkpoint</th><th>Dir</th><th>Plate</th><th>Type</th><th>Color</th><th>Observer</th></tr></thead>
    <tbody id="recentBody"></tbody>
  </table>
</section>

<script>
let pollMs = 10000;
let timer;

function kpiCard(label, value, css='') {
  return `<article class="card"><div class="kpi ${css}">${value}</div><div class="kpi-label">${label}</div></article>`;
}

async function loadDashboard() {
  const siteId = document.getElementById('site_id').value;
  const hours = document.getElementById('hours').value;
  const res = await fetch(`api/dashboard_data.php?site_id=${siteId}&hours=${hours}`);
  const json = await res.json();
  if (!json.ok) return;

  pollMs = Math.max(5000, Number(json.settings.poll_seconds || 10) * 1000);
  document.getElementById('pollLabel').textContent = String(pollMs / 1000);

  const summary = json.summary;
  const policyStatus = summary.meets_policy ? 'Meets 25% Policy' : 'Below 25% Policy';
  const policyClass = summary.meets_policy ? 'ok' : 'warn';

  document.getElementById('kpis').innerHTML = [
    kpiCard('Total Volume (deduped)', summary.total_volume),
    kpiCard('Cut-Through Vehicles', summary.cut_through_count),
    kpiCard('Cut-Through %', `${summary.cut_through_percent}%`, policyClass),
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
  json.matches.slice(0, 20).forEach(m => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${m.in_event.checkpoint_name} ${m.in_event.event_time}</td>
      <td>${m.out_event.checkpoint_name} ${m.out_event.event_time}</td>
      <td>${m.elapsed_minutes} min</td>
      <td>${m.expected_minutes} min</td>
      <td>${m.confidence}</td>`;
    matchBody.appendChild(tr);
  });

  const recentBody = document.getElementById('recentBody');
  recentBody.innerHTML = '';
  json.recent_events.forEach(e => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${e.event_time}</td><td>${e.checkpoint_name}</td><td>${e.direction}</td><td>${e.plate_raw || ''}</td><td>${e.vehicle_type}</td><td>${e.vehicle_color}</td><td>${e.observer_name || ''}</td>`;
    recentBody.appendChild(tr);
  });

  if (timer) clearTimeout(timer);
  timer = setTimeout(loadDashboard, pollMs);
}

document.getElementById('refreshBtn').addEventListener('click', loadDashboard);
document.getElementById('site_id').addEventListener('change', loadDashboard);
document.getElementById('hours').addEventListener('change', loadDashboard);
loadDashboard();
</script>
<?php render_foot(); ?>
