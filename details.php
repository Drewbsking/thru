<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

$sites = all_sites();
$siteId = (int)($_GET['site_id'] ?? current_site_id());
render_head('Cut-Through Details');
?>
<section class="card">
  <h1>Cut-Through Details</h1>
  <p class="small">High confidence matches are paired one-to-one. Unmatched In events are treated as local arrivals, unmatched Out events as local departures.</p>
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
    </div>
  </div>
</section>

<section class="card" style="margin-top:1rem;">
  <h2>Matched Cut-Through Events</h2>
  <table>
    <thead><tr><th>In Event #</th><th>Out Event #</th><th>In Time</th><th>In CP</th><th>Out Time</th><th>Out CP</th><th>Distance</th><th>Elapsed</th><th>Expected</th><th>Avg Speed</th><th>Confidence</th><th>Vehicle</th></tr></thead>
    <tbody id="matchesBody"></tbody>
  </table>
</section>

<section class="grid two" style="margin-top:1rem;">
  <article class="card">
    <h2>Local Arrivals (In only)</h2>
    <table><thead><tr><th>Time</th><th>Checkpoint</th><th>Plate</th><th>Vehicle</th></tr></thead><tbody id="arrivalsBody"></tbody></table>
  </article>
  <article class="card">
    <h2>Local Departures (Out only)</h2>
    <table><thead><tr><th>Time</th><th>Checkpoint</th><th>Plate</th><th>Vehicle</th></tr></thead><tbody id="departuresBody"></tbody></table>
  </article>
</section>

<script>
let matchesCache = [];

function currentStudyPeriod() {
  return (new Date().getHours() < 12) ? 'morning' : 'afternoon';
}

function csvEscape(v) {
  const s = String(v ?? '');
  return `"${s.replaceAll('"', '""')}"`;
}

function exportCsv() {
  const rows = [['in_event_id','out_event_id','in_time','in_checkpoint','out_time','out_checkpoint','distance_miles','elapsed_minutes','expected_minutes','avg_speed_mph','confidence','plate_in','plate_out','vehicle_type','vehicle_color']];
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
      m.in_event.plate_raw || '',
      m.out_event.plate_raw || '',
      m.in_event.vehicle_type,
      m.in_event.vehicle_color,
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

async function loadDetails() {
  const siteId = document.getElementById('site_id').value;
  const studyPeriod = document.getElementById('study_period').value;
  const res = await fetch(`api/dashboard_data.php?site_id=${siteId}&study_period=${studyPeriod}`);
  const data = await res.json();
  if (!data.ok) return;

  matchesCache = data.matches || [];

  const matchBody = document.getElementById('matchesBody');
  matchBody.innerHTML = '';
  for (const m of matchesCache) {
    const vehicle = `${m.in_event.vehicle_type} / ${m.in_event.vehicle_color}`;
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${m.in_event.id}</td><td>${m.out_event.id}</td><td>${m.in_event.event_time}</td><td>${m.in_event.checkpoint_name}</td>
      <td>${m.out_event.event_time}</td><td>${m.out_event.checkpoint_name}</td><td>${m.distance_miles}</td>
      <td>${m.elapsed_minutes}</td><td>${m.expected_minutes}</td><td>${m.avg_speed_mph} mph</td><td>${m.confidence}</td><td>${vehicle}</td>`;
    matchBody.appendChild(tr);
  }

  const arrivals = document.getElementById('arrivalsBody');
  arrivals.innerHTML = '';
  const arrivalsData = data.unmatched_in || [];
  const depData = data.unmatched_out || [];

  for (const e of arrivalsData) {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${e.event_time}</td><td>${e.checkpoint_name}</td><td>${e.plate_raw || ''}</td><td>${e.vehicle_type} / ${e.vehicle_color}</td>`;
    arrivals.appendChild(tr);
  }

  const dep = document.getElementById('departuresBody');
  dep.innerHTML = '';
  for (const e of depData) {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${e.event_time}</td><td>${e.checkpoint_name}</td><td>${e.plate_raw || ''}</td><td>${e.vehicle_type} / ${e.vehicle_color}</td>`;
    dep.appendChild(tr);
  }
}

document.getElementById('site_id').addEventListener('change', loadDetails);
document.getElementById('study_period').addEventListener('change', loadDetails);
document.getElementById('exportBtn').addEventListener('click', exportCsv);
document.getElementById('study_period').value = currentStudyPeriod();
loadDetails();
</script>
<?php render_foot(); ?>
