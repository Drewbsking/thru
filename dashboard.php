<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

$siteId = current_site_id();
$activeSite = $siteId > 0 ? site_by_id($siteId) : null;
render_head('Dashboard');
?>
<section class="card">
  <p class="small">Auto-refreshes every <span id="pollLabel">10</span> seconds. Cut-through is calculated with expected travel time from checkpoint distance and speed setting.</p>
  <p class="small">All times shown in Eastern Time (ET).</p>
  <p class="small" id="studyDateLabel"></p>
  <p class="status small" id="dashboardStatus"></p>
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

<section class="card" style="margin-top:1rem;">
  <details class="section-collapse">
    <summary id="kpisSummary">Key Metrics</summary>
    <div class="section-collapse-body">
      <section id="kpis"></section>
    </div>
  </details>
</section>

<section class="card" style="margin-top:1rem;">
  <details class="section-collapse">
    <summary id="pairChartSummary">Cut-Through by Checkpoint Pair</summary>
    <div class="section-collapse-body">
      <p class="small">Matched cut-through vehicles grouped by checkpoint direction pair.</p>
      <p class="small" id="pairChartMeta">No pair data loaded yet.</p>
      <div id="pairChart" class="pair-chart"></div>
    </div>
  </details>
</section>

<section class="card" style="margin-top:1rem;">
  <details class="section-collapse">
    <summary id="checkpointSummary">Checkpoint Counts</summary>
    <div class="section-collapse-body">
      <table>
        <thead><tr><th>Checkpoint</th><th>In</th><th>Out</th><th>Total (Two-Way)</th></tr></thead>
        <tbody id="checkpointBody"><tr><td colspan="4">Loading...</td></tr></tbody>
      </table>
    </div>
  </details>
</section>

<section class="card" style="margin-top:1rem;">
  <details class="section-collapse">
    <summary id="matchSummary">Cut-Through Matches</summary>
    <div class="section-collapse-body">
      <table>
        <thead><tr><th>In Event #</th><th>Out Event #</th><th>Elapsed</th><th>Expected</th><th>Avg Speed</th><th>Confidence</th></tr></thead>
        <tbody id="matchBody"><tr><td colspan="6">Loading...</td></tr></tbody>
      </table>
    </div>
  </details>
</section>

<section class="card" style="margin-top:1rem;">
  <details class="section-collapse">
    <summary id="sessionCommentsSummary">Session Observations</summary>
    <div class="section-collapse-body">
      <table>
        <thead><tr><th>Checkpoint</th><th>Collector</th><th>Period</th><th>Last Updated (ET)</th><th>Observation Comment</th></tr></thead>
        <tbody id="sessionCommentsBody"><tr><td colspan="5">Loading...</td></tr></tbody>
      </table>
    </div>
  </details>
</section>

<section class="card" style="margin-top:1rem;">
  <details class="section-collapse">
    <summary id="recentSummary">Recent Events</summary>
    <div class="section-collapse-body">
      <table>
        <thead><tr><th>Event #</th><th>Time</th><th>Checkpoint</th><th>Dir</th><th>Plate</th><th>Type</th><th>Color</th><th>Observer</th></tr></thead>
        <tbody id="recentBody"><tr><td colspan="8">Loading...</td></tr></tbody>
      </table>
    </div>
  </details>
</section>

<script>
let pollMs = 10000;
let timer;
const activeSiteId = <?= (int)$siteId ?>;
const refreshBtn = document.getElementById('refreshBtn');
const downloadReportBtn = null;
const studyPeriodSelect = document.getElementById('study_period');
let autoDownloadReport = false;
let reportBusy = false;
let seenRecentEventKeys = new Set();
let hasRecentBaseline = false;
const recentRowHighlightMs = 1200;
let currentDashboardStudyDate = '';

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

function kpiRow(title, cards) {
  return `<section style="margin-bottom:1rem;">
    <div class="grid three">${cards.join('')}</div>
  </section>`;
}

function kpiLabeledRow(title, cards, note = '') {
  const safeTitle = escapeHtml(title || '');
  const safeNote = escapeHtml(note || '');
  return `<section style="margin-bottom:1rem;">
    <div class="small" style="font-weight:700; letter-spacing:0.04em; text-transform:uppercase; margin-bottom:0.35rem;">${safeTitle}</div>
    ${safeNote ? `<p class="small" style="margin-top:0; margin-bottom:0.5rem;">${safeNote}</p>` : ''}
    <div class="grid three">${cards.join('')}</div>
  </section>`;
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

function pairCountsByRoute(matches, totalVolume = 0) {
  const denom = Number(totalVolume || 0);
  const grouped = groupMatchesByRoute(matches).map(([route, rows]) => {
    const count = rows.length;
    const percent = denom > 0 ? (count / denom) * 100 : 0;
    const avgSpeed = count > 0
      ? (rows.reduce((acc, m) => acc + Number(m?.avg_speed_mph || 0), 0) / count)
      : 0;
    return {
      route,
      count,
      percent,
      percent_label: `${percent.toFixed(2)}%`,
      avg_speed_mph: Number(avgSpeed.toFixed(2)),
      avg_speed_label: `${avgSpeed.toFixed(2)} mph`,
    };
  });
  return grouped.sort((a, b) => (b.count - a.count) || a.route.localeCompare(b.route, undefined, { numeric: true, sensitivity: 'base' }));
}

function renderPairChart(matches, totalVolume = 0) {
  const chartEl = document.getElementById('pairChart');
  const metaEl = document.getElementById('pairChartMeta');
  if (!chartEl || !metaEl) return;

  const pairCounts = pairCountsByRoute(matches || [], totalVolume);
  if (pairCounts.length === 0) {
    metaEl.textContent = 'No cut-through pair matches in this period.';
    chartEl.innerHTML = '<div class="small">No chart data to display.</div>';
    return;
  }

  const top = pairCounts[0];
  metaEl.textContent = `Unique pairs: ${pairCounts.length} | Top pair: ${top.route} (${top.count}, ${top.percent_label} of total volume, ${top.avg_speed_label})`;
  const maxCount = Math.max(...pairCounts.map((p) => p.count), 1);
  chartEl.innerHTML = pairCounts.map((pair) => {
    const widthPct = Math.max(8, Math.round((pair.count / maxCount) * 100));
    const routeLabel = escapeHtml(pair.route);
    const countLabel = escapeHtml(`${pair.count} (${pair.percent_label}, ${pair.avg_speed_label})`);
    return `<div class="pair-bar-row">
      <div class="pair-bar-head">
        <span class="pair-route">${routeLabel}</span>
        <span class="pair-count">${countLabel}</span>
      </div>
      <div class="pair-bar-track">
        <div class="pair-bar-fill" style="width:${widthPct}%"></div>
      </div>
    </div>`;
  }).join('');
}

function formatKpiTime(value) {
  return formatEtTimeOnly(value);
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

function formatEtTimeOnly(value) {
  const raw = String(value || '').trim();
  if (!raw) return '--';
  const m = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?$/);
  if (!m) return raw;
  const hour24 = Number(m[4]);
  const minute = m[5];
  const ampm = hour24 >= 12 ? 'PM' : 'AM';
  const hour12 = (hour24 % 12) || 12;
  return `${hour12}:${minute} ${ampm} ET`;
}

function formatStudyPeriodLabel(period) {
  return String(period || '').toLowerCase() === 'afternoon' ? 'Afternoon Study' : 'Morning Study';
}

function commentCellHtml(value) {
  return escapeHtml(String(value ?? '')).replace(/\n/g, '<br>');
}

function recentEventKey(event) {
  const idValue = String(event?.id ?? '').trim();
  if (idValue !== '') {
    return `id:${idValue}`;
  }
  const eventTime = String(event?.event_time ?? '').trim();
  const checkpointName = String(event?.checkpoint_name ?? '').trim();
  const direction = String(event?.direction ?? '').trim();
  const plateRaw = String(event?.plate_raw ?? '').trim();
  return `fallback:${eventTime}|${checkpointName}|${direction}|${plateRaw}`;
}

function formatEtDateOnly(ymd) {
  const raw = String(ymd || '').trim();
  const m = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!m) return raw || '--';
  const year = Number(m[1]);
  const month = Number(m[2]);
  const day = Number(m[3]);
  const monthName = new Intl.DateTimeFormat('en-US', { month: 'long' }).format(new Date(year, month - 1, day));
  return `${monthName} ${day}, ${year}`;
}

function formatPolicyThreshold(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '25';
  if (n % 1 === 0) return n.toFixed(0);
  return n.toFixed(2).replace(/\.?0+$/, '');
}

function safeFileName(value) {
  return String(value || 'site')
    .trim()
    .replace(/[^A-Za-z0-9._-]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 80) || 'site';
}

function getReportSiteName() {
  const text = document.getElementById('siteCardName')?.textContent || '';
  return text.trim() || 'Active Site';
}

function getReportSiteImageSrc() {
  const img = document.getElementById('siteCardImage');
  if (!img) return '';
  const src = img.getAttribute('src') || '';
  const visible = img.style.display !== 'none';
  return visible ? src : '';
}

function loadScript(src) {
  return new Promise((resolve, reject) => {
    const existing = document.querySelector(`script[src="${src}"]`);
    if (existing) {
      if (existing.dataset.loaded === '1') {
        resolve();
        return;
      }
      existing.addEventListener('load', () => resolve(), { once: true });
      existing.addEventListener('error', () => reject(new Error(`Failed to load script: ${src}`)), { once: true });
      return;
    }
    const script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.onload = () => {
      script.dataset.loaded = '1';
      resolve();
    };
    script.onerror = () => reject(new Error(`Failed to load script: ${src}`));
    document.head.appendChild(script);
  });
}

async function ensurePdfLibraries() {
  if (window.jspdf?.jsPDF && typeof window.jspdf.jsPDF.API.autoTable === 'function') {
    return;
  }
  await loadScript('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js');
  await loadScript('https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js');
  if (!window.jspdf?.jsPDF || typeof window.jspdf.jsPDF.API.autoTable !== 'function') {
    throw new Error('PDF library did not initialize.');
  }
}

async function imageUrlToDataUri(url) {
  const src = String(url || '').trim();
  if (!src) return '';
  try {
    const res = await fetch(src, { cache: 'no-store' });
    if (!res.ok) return '';
    const blob = await res.blob();
    return await new Promise((resolve) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result || ''));
      reader.onerror = () => resolve('');
      reader.readAsDataURL(blob);
    });
  } catch (e) {
    return '';
  }
}

async function fetchReportPeriod(period, studyDate = currentDashboardStudyDate) {
  const resolvedStudyDate = String(studyDate || '').trim();
  const params = new URLSearchParams();
  params.set('site_id', String(activeSiteId));
  params.set('study_period', String(period));
  params.set('include_all_events', '1');
  if (resolvedStudyDate !== '') params.set('study_date', resolvedStudyDate);
  const res = await fetch(`api/dashboard_data.php?${params.toString()}`);
  const json = await res.json();
  if (!json.ok) throw new Error(json.error || `Failed to load ${period} report data.`);
  return json;
}

async function fetchRepeatCutThroughData(studyDate = currentDashboardStudyDate) {
  const resolvedStudyDate = String(studyDate || '').trim();
  const params = new URLSearchParams();
  params.set('site_id', String(activeSiteId));
  if (resolvedStudyDate !== '') params.set('study_date', resolvedStudyDate);
  const res = await fetch(`api/repeat_cut_through_data.php?${params.toString()}`);
  const json = await res.json();
  if (!json.ok) throw new Error(json.error || 'Failed to load AM/PM repeat cut-through data.');
  return json;
}

function checkpointLabel(name, code = '') {
  const n = String(name || '').trim();
  const c = String(code || '').trim();
  if (n && c) return `${n} (${c})`;
  return n || c || '--';
}

function collectorRowsFromEvents(events, checkpoints = []) {
  const byCheckpoint = new Map();
  for (const cp of (checkpoints || [])) {
    const key = checkpointLabel(cp.display_name, cp.checkpoint_code);
    if (!byCheckpoint.has(key)) byCheckpoint.set(key, new Set());
  }
  for (const e of (events || [])) {
    const cp = checkpointLabel(e.checkpoint_name, e.checkpoint_code);
    const observer = String(e.observer_name || '').trim();
    if (!cp || cp === '--') continue;
    if (!byCheckpoint.has(cp)) byCheckpoint.set(cp, new Set());
    if (observer) byCheckpoint.get(cp).add(observer);
  }

  let entries = Array.from(byCheckpoint.entries());
  if ((checkpoints || []).length > 0) {
    const ordered = [];
    for (const cp of checkpoints) {
      const key = checkpointLabel(cp.display_name, cp.checkpoint_code);
      if (byCheckpoint.has(key)) ordered.push([key, byCheckpoint.get(key)]);
    }
    for (const row of entries) {
      if (!ordered.some((o) => o[0] === row[0])) ordered.push(row);
    }
    entries = ordered;
  } else {
    entries.sort((a, b) => a[0].localeCompare(b[0], undefined, { numeric: true, sensitivity: 'base' }));
  }

  const rows = entries
    .map(([checkpoint, collectors]) => [checkpoint, Array.from(collectors).sort((a, b) => a.localeCompare(b)).join(', ') || 'Not recorded']);
  return rows.length ? rows : [['--', 'No collector names recorded']];
}

function checkpointCountRows(data) {
  const byId = new Map();
  for (const row of (data?.checkpoint_counts_by_id || [])) {
    byId.set(Number(row.checkpoint_id || 0), row);
  }

  let rows = [];
  for (const cp of (data?.checkpoints || [])) {
    const cpId = Number(cp.id || 0);
    const c = byId.get(cpId) || { in: 0, out: 0, total: 0 };
    rows.push([checkpointLabel(cp.display_name, cp.checkpoint_code), String(c.in || 0), String(c.out || 0), String(c.total || 0)]);
  }

  if (rows.length === 0) {
    rows = (data?.checkpoint_counts_by_id || [])
      .slice()
      .sort((a, b) => Number(a.checkpoint_id || 0) - Number(b.checkpoint_id || 0))
      .map((row) => [String(row.checkpoint_name || ''), String(row.in || 0), String(row.out || 0), String(row.total || 0)]);
  }
  return rows.length ? rows : [['--', '0', '0', '0']];
}

function pairCountRows(matches, totalVolume = 0) {
  const rows = pairCountsByRoute(matches || [], totalVolume)
    .map((row) => [row.route, String(row.count), row.percent_label, row.avg_speed_label])
    .sort((a, b) => Number(b[1]) - Number(a[1]));
  return rows.length ? rows : [['No matches', '0', '0.00%', '0.00 mph']];
}

function matchRows(matches) {
  const rows = (matches || []).map((m) => {
    const route = matchRouteKey(m);
    return [
      String(m?.in_event?.id || ''),
      String(m?.out_event?.id || ''),
      route,
      `${m?.elapsed_minutes ?? ''} min`,
      `${m?.expected_minutes ?? ''} min`,
      `${formatWholeSpeed(m?.avg_speed_mph)} mph`,
      String(m?.confidence ?? ''),
    ];
  });
  return rows.length ? rows : [['', '', 'No matches', '', '', '', '']];
}

function sessionCommentRows(data) {
  const rows = (data?.session_comments || []).map((row) => [
    String(row?.checkpoint_label || row?.checkpoint_name || row?.checkpoint_code || '--'),
    String(row?.collector_username || '--'),
    formatEtDateTime(row?.updated_at || ''),
    String(row?.comment_text || ''),
  ]);
  return rows.length ? rows : [['--', '--', '--', 'No session observations recorded']];
}

function rawEventRows(morningData, afternoonData) {
  const rows = [];
  for (const e of (morningData?.all_events || [])) {
    rows.push([
      String(e.id || ''),
      'Morning',
      formatEtDateTime(e.event_time),
      String(e.checkpoint_name || ''),
      String(e.direction || ''),
      String(e.plate_raw || ''),
      String(e.vehicle_type || ''),
      String(e.vehicle_color || ''),
      String(e.observer_name || ''),
      String(e.notes || ''),
    ]);
  }
  for (const e of (afternoonData?.all_events || [])) {
    rows.push([
      String(e.id || ''),
      'Afternoon',
      formatEtDateTime(e.event_time),
      String(e.checkpoint_name || ''),
      String(e.direction || ''),
      String(e.plate_raw || ''),
      String(e.vehicle_type || ''),
      String(e.vehicle_color || ''),
      String(e.observer_name || ''),
      String(e.notes || ''),
    ]);
  }
  return rows;
}

function summaryRowsForPeriod(data) {
  const summary = data?.summary || {};
  const policyThresholdLabel = formatPolicyThreshold(data?.settings?.policy_cut_through_percent ?? 25);
  const cutThroughCount = Number(summary.cut_through_count ?? 0);
  const checkpointTotals = (data?.checkpoint_counts_by_id || []).map((row) => Number(row.total || 0));
  if (checkpointTotals.length === 0) {
    for (const [, countRow] of Object.entries(data?.checkpoint_counts || {})) {
      checkpointTotals.push(Number(countRow?.total || 0));
    }
  }
  const highestCheckpointTwoWayFromCounts = checkpointTotals.length > 0 ? Math.max(...checkpointTotals) : 0;
  const highestCheckpointTwoWay = Number(summary.highest_checkpoint_two_way ?? highestCheckpointTwoWayFromCounts);
  const cutThroughOverHighestPercent = Number(summary.cut_through_over_highest_two_way_percent ?? (
    highestCheckpointTwoWay > 0 ? ((cutThroughCount / highestCheckpointTwoWay) * 100) : 0
  ));
  const maxLegPolicyPercent = Number(summary.max_leg_policy_percent ?? 0);
  const maxLegPolicyCount = Number(summary.max_leg_policy_count ?? 0);
  const maxLegPolicyDenominator = Number(summary.max_leg_policy_denominator ?? 0);
  const maxLegPolicyRoute = String(summary.max_leg_policy_route || '--');
  const policyStatus = summary.meets_policy
    ? `Meets ${policyThresholdLabel}% Policy`
    : `Below ${policyThresholdLabel}% Policy`;
  const avgMatchConfidence = Number(summary.avg_match_confidence ?? (
    (data?.matches || []).length
      ? ((data.matches || []).reduce((acc, m) => acc + Number(m.confidence || 0), 0) / data.matches.length)
      : 0
  )).toFixed(2);
  const vehiclesPerHour = Number(summary.vehicles_per_hour ?? 0).toFixed(2);
  return [
    ['Start Time (First Entry)', formatEtDateTime(summary.start_time, true)],
    ['End Time (Last Entry)', formatEtDateTime(summary.end_time, true)],
    ['Total Volume (Two-Way)', String(summary.total_volume ?? 0)],
    ['Highest Checkpoint Two-Way', String(highestCheckpointTwoWay)],
    ['Cut-Through Vehicles', String(cutThroughCount)],
    ['Cut-Through / Highest Two-Way', `${cutThroughCount}/${highestCheckpointTwoWay} (${cutThroughOverHighestPercent.toFixed(2)}%)`],
    ['Vehicles Per Hour', `${vehiclesPerHour} veh/hr`],
    ['Avg Match Confidence', `${avgMatchConfidence}%`],
    ['Max Leg Policy %', `${maxLegPolicyPercent.toFixed(2)}% (${maxLegPolicyCount}/${maxLegPolicyDenominator}, ${maxLegPolicyRoute})`],
    ['Policy Status', `${policyStatus} (max leg ${maxLegPolicyPercent.toFixed(2)}%)`],
    ['Local Arrivals (In only)', String(summary.local_arrivals_count ?? 0)],
    ['Local Departures (Out only)', String(summary.local_departures_count ?? 0)],
    ['Expected Speed Setting', `${data?.settings?.speed_mph ?? ''} mph`],
    ['Buffer Window', `${data?.settings?.buffer_minutes ?? ''} min`],
    ['Min Confidence', String(data?.settings?.min_confidence ?? '')],
  ];
}

function repeatSummaryRows(repeatData) {
  const summary = repeatData?.summary || {};
  const repeatThreshold = Number(repeatData?.repeat_match_min_confidence ?? 0);
  const rows = [
    ['Repeat Cut-Through Vehicles (AM & PM)', String(summary.repeat_vehicle_count ?? 0)],
    ['Plate Prefixes 4x+ (All Data)', String(summary.all_data_plate_4x_count ?? 0)],
    ['In/Out Same Checkpoint', String(summary.same_checkpoint_in_out_count ?? 0)],
    ['In/Out Different, Outside Window', String(summary.different_checkpoint_outside_window_count ?? 0)],
    ['Repeat Basis', 'Cut-through matches only; best AM-to-PM plate/type/color confidence match (route ignored)'],
  ];
  if (repeatThreshold > 0) {
    rows.push(['Repeat Match Threshold', `${repeatThreshold}%`]);
  }
  const skippedCount = Number(summary.skipped_incomplete_match_count ?? 0);
  if (skippedCount > 0) {
    rows.push(['Skipped Repeat Candidates (No Plate)', String(skippedCount)]);
  }
  return rows;
}

function repeatDetailRows(repeatData) {
  const rows = (repeatData?.rows || []).map((row) => [
    String(row?.am_vehicle_label || '--'),
    String(row?.pm_vehicle_label || '--'),
    `${Number(row?.confidence ?? 0)}%`,
    String(row?.score_detail || '--'),
    String(row?.am_route_label || '--'),
    formatEtTimeOnly(row?.am_in_time || ''),
    formatEtTimeOnly(row?.am_out_time || ''),
    String(row?.pm_route_label || '--'),
    formatEtTimeOnly(row?.pm_in_time || ''),
    formatEtTimeOnly(row?.pm_out_time || ''),
  ]);
  return rows.length ? rows : [['No repeat cut-through vehicles detected', '', '', '', '', '', '', '', '', '']];
}

function addAutoTable(pdf, config) {
  pdf.autoTable({
    theme: 'grid',
    margin: { left: 40, right: 40 },
    styles: { fontSize: 8, cellPadding: 4, overflow: 'linebreak' },
    headStyles: { fillColor: [15, 23, 42], textColor: [255, 255, 255] },
    ...config,
  });
}

async function downloadFormalReportPdf() {
  if (!activeSiteId || reportBusy) return;
  reportBusy = true;
  const originalText = downloadReportBtn ? downloadReportBtn.textContent : 'Download PDF Report (AM+PM)';
  if (downloadReportBtn) {
    downloadReportBtn.disabled = true;
    downloadReportBtn.textContent = 'Building PDF...';
  }

  try {
    await ensurePdfLibraries();
    const reportStudyDate = String(currentDashboardStudyDate || '').trim();
    const [morningData, afternoonData, repeatData] = await Promise.all([
      fetchReportPeriod('morning', reportStudyDate),
      fetchReportPeriod('afternoon', reportStudyDate),
      fetchRepeatCutThroughData(reportStudyDate),
    ]);

    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF({ unit: 'pt', format: 'letter' });
    const pageWidth = pdf.internal.pageSize.getWidth();
    const pageHeight = pdf.internal.pageSize.getHeight();
    const marginX = 40;
    const contentWidth = pageWidth - (marginX * 2);
    const siteName = getReportSiteName();
    const studyDateText = formatEtDateOnly(morningData.study_date || afternoonData.study_date || '');
    const generatedAtText = new Intl.DateTimeFormat('en-US', {
      month: 'long',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
      timeZone: 'America/New_York',
    }).format(new Date());

    let y = 56;
    pdf.setTextColor(20, 33, 61);
    pdf.setFont('helvetica', 'bold');
    pdf.setFontSize(22);
    pdf.text('Cut-Through Study Report', marginX, y);
    y += 28;
    pdf.setFont('helvetica', 'normal');
    pdf.setFontSize(12);
    pdf.text(`Site: ${siteName}`, marginX, y);
    y += 18;
    pdf.text(`Study Date: ${studyDateText}`, marginX, y);
    y += 18;
    pdf.text('Included Periods: Morning and Afternoon', marginX, y);
    y += 18;
    pdf.text(`Generated: ${generatedAtText} ET`, marginX, y);
    y += 22;

    const imageSrc = getReportSiteImageSrc();
    if (imageSrc) {
      const dataUri = await imageUrlToDataUri(imageSrc);
      if (dataUri) {
        const imageProps = pdf.getImageProperties(dataUri);
        const maxH = 240;
        let drawW = contentWidth;
        let drawH = (imageProps.height / imageProps.width) * drawW;
        if (drawH > maxH) {
          drawH = maxH;
          drawW = (imageProps.width / imageProps.height) * drawH;
        }
        const drawX = marginX + ((contentWidth - drawW) / 2);
        pdf.addImage(dataUri, imageProps.fileType || 'JPEG', drawX, y, drawW, drawH);
        y += drawH + 18;
      }
    }

    if (y > pageHeight - 220) {
      pdf.addPage();
      y = 56;
    }

    pdf.setFont('helvetica', 'bold');
    pdf.setFontSize(14);
    pdf.text('Data Collectors by Checkpoint', marginX, y);
    y += 10;
    const collectors = collectorRowsFromEvents([
      ...(morningData.all_events || []),
      ...(afternoonData.all_events || []),
    ], morningData.checkpoints || afternoonData.checkpoints || []);
    addAutoTable(pdf, {
      startY: y + 6,
      head: [['Checkpoint', 'Collectors']],
      body: collectors,
      columnStyles: { 0: { cellWidth: 160 }, 1: { cellWidth: contentWidth - 160 } },
    });
    y = pdf.lastAutoTable.finalY + 18;

    const appendPeriod = (title, data) => {
      if (y > pageHeight - 160) {
        pdf.addPage();
        y = 56;
      }
      pdf.setFont('helvetica', 'bold');
      pdf.setFontSize(15);
      pdf.text(`${title} Summary`, marginX, y);
      y += 10;

      addAutoTable(pdf, {
        startY: y + 6,
        head: [['Metric', 'Value']],
        body: summaryRowsForPeriod(data),
        columnStyles: { 0: { cellWidth: 220 }, 1: { cellWidth: contentWidth - 220 } },
      });
      y = pdf.lastAutoTable.finalY + 12;

      addAutoTable(pdf, {
        startY: y,
        head: [['Checkpoint', 'In', 'Out', 'Total (Two-Way)']],
        body: checkpointCountRows(data),
      });
      y = pdf.lastAutoTable.finalY + 12;

      addAutoTable(pdf, {
        startY: y,
        head: [['Checkpoint', 'Collector', 'Last Updated (ET)', 'Observation Comment']],
        body: sessionCommentRows(data),
        columnStyles: { 0: { cellWidth: 120 }, 1: { cellWidth: 90 }, 2: { cellWidth: 120 }, 3: { cellWidth: contentWidth - 330 } },
      });
      y = pdf.lastAutoTable.finalY + 12;

      addAutoTable(pdf, {
        startY: y,
        head: [['Checkpoint Pair', 'Matched Cut-Through Count', '% Of Total Volume', 'Avg Speed (Pair)']],
        body: pairCountRows(data.matches || [], Number(data?.summary?.total_volume || 0)),
      });
      y = pdf.lastAutoTable.finalY + 12;

      addAutoTable(pdf, {
        startY: y,
        head: [['In #', 'Out #', 'Pair', 'Elapsed', 'Expected', 'Avg Speed', 'Confidence']],
        body: matchRows(data.matches || []),
        styles: { fontSize: 7.5, cellPadding: 3.5, overflow: 'linebreak' },
      });
      y = pdf.lastAutoTable.finalY + 18;
    };

    appendPeriod('Morning Study', morningData);
    appendPeriod('Afternoon Study', afternoonData);

    if (y > pageHeight - 200) {
      pdf.addPage();
      y = 56;
    }

    pdf.setFont('helvetica', 'bold');
    pdf.setFontSize(15);
    pdf.text('AM + PM Repeat Cut-Through Summary', marginX, y);
    y += 10;

    addAutoTable(pdf, {
      startY: y + 6,
      head: [['Metric', 'Value']],
      body: repeatSummaryRows(repeatData),
      columnStyles: { 0: { cellWidth: 220 }, 1: { cellWidth: contentWidth - 220 } },
    });
    y = pdf.lastAutoTable.finalY + 12;

    addAutoTable(pdf, {
      startY: y,
      head: [['AM Vehicle', 'PM Vehicle', 'Confidence', 'Score', 'AM Route', 'AM In', 'AM Out', 'PM Route', 'PM In', 'PM Out']],
      body: repeatDetailRows(repeatData),
      styles: { fontSize: 7, cellPadding: 3, overflow: 'linebreak' },
      columnStyles: {
        0: { cellWidth: 69 },
        1: { cellWidth: 69 },
        2: { cellWidth: 36 },
        3: { cellWidth: 36 },
        4: { cellWidth: 70 },
        5: { cellWidth: 40 },
        6: { cellWidth: 40 },
        7: { cellWidth: 70 },
        8: { cellWidth: 40 },
        9: { cellWidth: 40 },
      },
    });
    y = pdf.lastAutoTable.finalY + 18;

    if (y > pageHeight - 120) {
      pdf.addPage();
      y = 56;
    }

    pdf.setFont('helvetica', 'bold');
    pdf.setFontSize(15);
    pdf.text('Raw Events Appendix (Morning + Afternoon)', marginX, y);
    y += 10;

    const rawRows = rawEventRows(morningData, afternoonData);
    addAutoTable(pdf, {
      startY: y + 6,
      head: [['Event #', 'Period', 'Time (ET)', 'Checkpoint', 'Dir', 'Plate', 'Type', 'Color', 'Collector', 'Comments']],
      body: rawRows.length ? rawRows : [['', '', 'No events', '', '', '', '', '', '', '']],
      styles: { fontSize: 7, cellPadding: 3, overflow: 'linebreak' },
      columnStyles: {
        0: { cellWidth: 38 },
        1: { cellWidth: 48 },
        2: { cellWidth: 104 },
        3: { cellWidth: 68 },
        4: { cellWidth: 26 },
        5: { cellWidth: 42 },
        6: { cellWidth: 48 },
        7: { cellWidth: 48 },
        8: { cellWidth: 56 },
        9: { cellWidth: 90 },
      },
    });

    const fileName = `ncat_formal_report_${safeFileName(siteName)}_${morningData.study_date || afternoonData.study_date || 'study'}.pdf`;
    pdf.save(fileName);
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Unable to generate report PDF.';
    alert(message);
  } finally {
    reportBusy = false;
    if (downloadReportBtn) {
      downloadReportBtn.disabled = false;
      downloadReportBtn.textContent = originalText;
    }
  }
}

async function loadDashboard() {
  if (!activeSiteId || !studyPeriodSelect) return;
  const dashboardStatusEl = document.getElementById('dashboardStatus');
  if (dashboardStatusEl) {
    dashboardStatusEl.textContent = 'Loading dashboard data...';
    dashboardStatusEl.className = 'status small';
  }
  const studyPeriod = studyPeriodSelect.value;
  let json = null;
  try {
    const res = await fetch(`api/dashboard_data.php?site_id=${activeSiteId}&study_period=${studyPeriod}`);
    json = await res.json();
  } catch (err) {
    json = { ok: false, error: 'Unable to load dashboard data.' };
  }
  if (!json || !json.ok) {
    if (dashboardStatusEl) {
      dashboardStatusEl.textContent = json?.error || 'Unable to load dashboard data.';
      dashboardStatusEl.className = 'status small warn';
    }
    if (timer) clearTimeout(timer);
    timer = setTimeout(loadDashboard, pollMs);
    return;
  }
  if (dashboardStatusEl) {
    dashboardStatusEl.textContent = '';
    dashboardStatusEl.className = 'status small';
  }

  const studyDateLabel = document.getElementById('studyDateLabel');
  if (studyDateLabel) {
    const usedDate = formatEtDateOnly(json.study_date);
    const requestedDate = formatEtDateOnly(json.requested_study_date);
    if (json.requested_study_date && json.study_date && json.requested_study_date !== json.study_date) {
      studyDateLabel.textContent = `Study Date: ${usedDate} (auto-selected latest date with data; requested ${requestedDate})`;
    } else {
      studyDateLabel.textContent = `Study Date: ${usedDate}`;
    }
  }

  pollMs = Math.max(5000, Number(json.settings.poll_seconds || 10) * 1000);
  document.getElementById('pollLabel').textContent = String(pollMs / 1000);
  currentDashboardStudyDate = String(json.study_date || '').trim();

  const summary = json.summary;
  const totalVolume = Number(summary.total_volume || 0);
  const policyThresholdLabel = formatPolicyThreshold(json.settings?.policy_cut_through_percent ?? 25);
  const maxLegPolicyPercent = Number(summary.max_leg_policy_percent ?? 0);
  const policyStatus = summary.meets_policy
    ? `Meets ${policyThresholdLabel}% Policy (max leg ${maxLegPolicyPercent.toFixed(2)}%)`
    : `Below ${policyThresholdLabel}% Policy (max leg ${maxLegPolicyPercent.toFixed(2)}%)`;
  const policyClass = summary.meets_policy ? 'ok' : 'warn';
  const vehiclesPerHour = Number(summary.vehicles_per_hour || 0).toFixed(2);
  const checkpointTotals = (json.checkpoint_counts_by_id || []).map((row) => Number(row.total || 0));
  if (checkpointTotals.length === 0) {
    for (const [, countRow] of Object.entries(json.checkpoint_counts || {})) {
      checkpointTotals.push(Number(countRow?.total || 0));
    }
  }
  const highestCheckpointTwoWay = Number(summary.highest_checkpoint_two_way ?? (checkpointTotals.length > 0 ? Math.max(...checkpointTotals) : 0));
  const cutThroughCount = Number(summary.cut_through_count || 0);
  const cutThroughOverTotalVolumePercent = Number(summary.cut_through_percent ?? (
    totalVolume > 0 ? ((cutThroughCount / totalVolume) * 100) : 0
  ));
  const cutThroughOverHighestPercent = Number(summary.cut_through_over_highest_two_way_percent ?? (
    highestCheckpointTwoWay > 0 ? ((cutThroughCount / highestCheckpointTwoWay) * 100) : 0
  ));
  const studyDate = formatEtDateOnly(json.study_date);
  const startTime = formatKpiTime(summary.start_time);
  const endTime = formatKpiTime(summary.end_time);
  const avgMatchConfidence = Number(summary.avg_match_confidence ?? (
    json.matches.length
      ? (json.matches.reduce((acc, m) => acc + Number(m.confidence || 0), 0) / json.matches.length)
      : 0
  )).toFixed(2);
  const pairCounts = pairCountsByRoute(json.matches || [], totalVolume);
  const topPair = pairCounts[0] || null;
  let repeatData = null;
  try {
    repeatData = await fetchRepeatCutThroughData(currentDashboardStudyDate);
  } catch (err) {
    repeatData = null;
  }
  const repeatSummary = repeatData?.summary || {};
  const repeatCountValue = repeatData ? String(repeatSummary.repeat_vehicle_count ?? 0) : '--';
  const skippedRepeatCandidates = Number(repeatSummary.skipped_incomplete_match_count ?? 0);
  const pairChartSummary = document.getElementById('pairChartSummary');
  if (pairChartSummary) pairChartSummary.textContent = `Cut-Through by Checkpoint Pair (${pairCounts.length})`;
  const matchSummary = document.getElementById('matchSummary');
  if (matchSummary) matchSummary.textContent = `Cut-Through Matches (${(json.matches || []).length})`;

  const dateCards = [
    kpiCard('Study Date', studyDate),
    kpiCard('Start Time (First Entry)', startTime),
    kpiCard('End Time (Last Entry)', endTime),
  ];
  const countCards = [
    kpiCard('Highest Checkpoint Two-Way', highestCheckpointTwoWay),
    kpiCard('Cut-Through Vehicles', cutThroughCount),
    kpiCard('Cut-Through / Total Volume', `${cutThroughCount}/${totalVolume} (${cutThroughOverTotalVolumePercent.toFixed(2)}%)`),
    kpiCard('Cut-Through / Highest Two-Way', `${cutThroughCount}/${highestCheckpointTwoWay} (${cutThroughOverHighestPercent.toFixed(2)}%)`),
    kpiCard('Local Arrivals (In only)', summary.local_arrivals_count),
    kpiCard('Local Departures (Out only)', summary.local_departures_count),
  ];
  const speedCards = [
    kpiCard('Vehicles Per Hour', `${vehiclesPerHour} veh/hr`),
    kpiCard('Top Leg Avg Speed', topPair ? topPair.avg_speed_label : '0.00 mph'),
    kpiCard('Top Leg % (Of Total)', topPair ? topPair.percent_label : '0.00%'),
  ];
  const avgMatchCards = [
    kpiCard('Avg Match Confidence', `${avgMatchConfidence}%`),
  ];
  const policyCards = [
    kpiCard('Policy Status', policyStatus, policyClass),
  ];
  const crossPeriodCards = [
    kpiCard('Repeat Cut-Through Vehicles', repeatCountValue),
    kpiCard('Plate Prefixes 4x+ (All Data)', String(repeatSummary.all_data_plate_4x_count ?? 0)),
    kpiCard('In/Out Same Checkpoint', String(repeatSummary.same_checkpoint_in_out_count ?? 0)),
    kpiCard('In/Out Different, Outside Window', String(repeatSummary.different_checkpoint_outside_window_count ?? 0)),
  ];
  const repeatThreshold = Number(repeatData?.repeat_match_min_confidence ?? 0);
  const crossPeriodNote = repeatData
    ? `AM+PM comparison cards use each study period pool after cut-through matches are removed. Same-checkpoint pairs never count as cut-throughs; different-checkpoint pairs here are outside the route timing window. Plate Prefixes 4x+ still counts normalized 3-char plates across all AM+PM events.${repeatThreshold > 0 ? ` Threshold ${repeatThreshold}%.` : ''}${skippedRepeatCandidates > 0 ? ` ${skippedRepeatCandidates} candidate(s) without a usable plate were skipped.` : ''}`
    : 'AM + PM repeat metric unavailable.';

  document.getElementById('kpis').innerHTML = [
    kpiRow('Dates', dateCards),
    kpiRow('Counts', countCards),
    kpiRow('Speeds', speedCards),
    kpiRow('Avg Match', avgMatchCards),
    kpiRow('Policy', policyCards),
    kpiLabeledRow('AM + PM', crossPeriodCards, crossPeriodNote),
  ].join('');
  renderPairChart(json.matches || [], totalVolume);

  const cpBody = document.getElementById('checkpointBody');
  cpBody.innerHTML = '';
  const cpEntries = Object.entries(json.checkpoint_counts || {});
  const checkpointSummary = document.getElementById('checkpointSummary');
  const checkpointCount = (json.checkpoints || []).length > 0 ? (json.checkpoints || []).length : cpEntries.length;
  if (checkpointSummary) checkpointSummary.textContent = `Checkpoint Counts (${checkpointCount})`;
  if (cpEntries.length === 0) {
    cpBody.innerHTML = '<tr><td colspan="4">No events in this study period/date.</td></tr>';
  } else {
    cpEntries.forEach(([name, c]) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${escapeHtml(name)}</td><td>${escapeHtml(c.in)}</td><td>${escapeHtml(c.out)}</td><td>${escapeHtml(c.total)}</td>`;
      cpBody.appendChild(tr);
    });
  }

  const matchBody = document.getElementById('matchBody');
  matchBody.innerHTML = '';
  const routeGroups = groupMatchesByRoute(json.matches || []);
  if (routeGroups.length === 0) {
    matchBody.innerHTML = '<tr><td colspan="6">No cut-through matches in this period.</td></tr>';
  } else {
    for (const [route, matches] of routeGroups) {
      const legPercent = totalVolume > 0 ? ((matches.length / totalVolume) * 100).toFixed(2) : '0.00';
      const legAvgSpeed = matches.length > 0
        ? (matches.reduce((acc, m) => acc + Number(m?.avg_speed_mph || 0), 0) / matches.length).toFixed(2)
        : '0.00';
      const section = document.createElement('tr');
      section.innerHTML = `<td colspan="6" style="background:#eef2ff; font-weight:700;">${escapeHtml(route)} (${escapeHtml(matches.length)}, ${escapeHtml(legPercent)}% of total volume, avg ${escapeHtml(legAvgSpeed)} mph)</td>`;
      matchBody.appendChild(section);

      matches.forEach((m) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${escapeHtml(m.in_event.id)}</td>
          <td>${escapeHtml(m.out_event.id)}</td>
          <td>${escapeHtml(m.elapsed_minutes)} min</td>
          <td>${escapeHtml(m.expected_minutes)} min</td>
          <td>${escapeHtml(formatWholeSpeed(m.avg_speed_mph))} mph</td>
          <td>${escapeHtml(m.confidence)}</td>`;
        matchBody.appendChild(tr);
      });
    }
  }

  const sessionCommentsBody = document.getElementById('sessionCommentsBody');
  sessionCommentsBody.innerHTML = '';
  const sessionComments = json.session_comments || [];
  const sessionCommentsSummary = document.getElementById('sessionCommentsSummary');
  if (sessionCommentsSummary) sessionCommentsSummary.textContent = `Session Observations (${sessionComments.length})`;
  if (!sessionComments.length) {
    sessionCommentsBody.innerHTML = '<tr><td colspan="5">No session observations for this period/date.</td></tr>';
  } else {
    sessionComments.forEach((row) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${escapeHtml(row.checkpoint_label || row.checkpoint_name || row.checkpoint_code || '--')}</td>
        <td>${escapeHtml(row.collector_username || '--')}</td>
        <td>${escapeHtml(formatStudyPeriodLabel(row.study_period || json.study_period))}</td>
        <td>${escapeHtml(formatEtDateTime(row.updated_at || ''))}</td>
        <td>${commentCellHtml(row.comment_text || '')}</td>`;
      sessionCommentsBody.appendChild(tr);
    });
  }

  const recentBody = document.getElementById('recentBody');
  recentBody.innerHTML = '';
  const recentEvents = json.recent_events || [];
  const recentSummary = document.getElementById('recentSummary');
  if (recentSummary) recentSummary.textContent = `Recent Events (${recentEvents.length})`;
  const incomingRecentEventKeys = new Set();
  if (!recentEvents.length) {
    recentBody.innerHTML = '<tr><td colspan="8">No recent events in this study period/date.</td></tr>';
  } else {
    recentEvents.forEach(e => {
      const eventKey = recentEventKey(e);
      incomingRecentEventKeys.add(eventKey);
      const isNewRow = hasRecentBaseline && !seenRecentEventKeys.has(eventKey);
      const tr = document.createElement('tr');
      if (isNewRow) {
        tr.classList.add('recent-row-new');
        setTimeout(() => tr.classList.remove('recent-row-new'), recentRowHighlightMs + 50);
      }
      tr.innerHTML = `<td>${escapeHtml(e.id)}</td><td>${escapeHtml(formatEtDateTime(e.event_time))}</td><td>${escapeHtml(e.checkpoint_name)}</td><td>${escapeHtml(e.direction)}</td><td>${escapeHtml(e.plate_raw || '')}</td><td>${escapeHtml(e.vehicle_type)}</td><td>${escapeHtml(e.vehicle_color)}</td><td>${escapeHtml(e.observer_name || '')}</td>`;
      recentBody.appendChild(tr);
    });
  }
  seenRecentEventKeys = incomingRecentEventKeys;
  hasRecentBaseline = true;

  if (autoDownloadReport) {
    autoDownloadReport = false;
    const nextParams = new URLSearchParams(window.location.search);
    nextParams.delete('download_report');
    const nextQuery = nextParams.toString();
    const nextUrl = `${window.location.pathname}${nextQuery ? `?${nextQuery}` : ''}`;
    window.history.replaceState({}, '', nextUrl);
    await downloadFormalReportPdf();
  }

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
