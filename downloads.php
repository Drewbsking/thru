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
$todayYmd = date('Y-m-d');

render_head('Downloads');
?>
<section class="card">
  <h2>Downloads</h2>
  <p class="small">All report and CSV exports are centralized here.</p>
  <?php if (count($sites) === 0): ?>
    <p class="status warn">No checkpoint assignment found for your account.</p>
  <?php else: ?>
    <div class="form-row">
      <div>
        <label>Site</label>
        <select id="download_site_id">
          <?php foreach ($sites as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $siteId ? 'selected' : '' ?>><?= h((string)$s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Study Period (CSV)</label>
        <select id="download_study_period">
          <option value="morning">Morning Study</option>
          <option value="afternoon">Afternoon Study</option>
        </select>
      </div>
      <div>
        <label>Study Date</label>
        <input id="download_study_date" type="date" value="<?= h($todayYmd) ?>">
      </div>
    </div>
    <div class="actions">
      <button type="button" id="downloadPdfBtn">Download PDF Report (AM+PM)</button>
      <button type="button" id="downloadMatchesCsvBtn" class="secondary">Export Matches CSV</button>
      <button type="button" id="downloadAllCsvBtn" class="secondary">Export All Events CSV</button>
    </div>
    <p class="small">PDF includes both Morning + Afternoon for the selected date and site.</p>
    <p class="status small" id="downloadStatus"></p>
  <?php endif; ?>
</section>

<?php if (count($sites) > 0): ?>
<script>
const siteSelect = document.getElementById('download_site_id');
const studyPeriodSelect = document.getElementById('download_study_period');
const studyDateInput = document.getElementById('download_study_date');
const downloadPdfBtn = document.getElementById('downloadPdfBtn');
const downloadMatchesCsvBtn = document.getElementById('downloadMatchesCsvBtn');
const downloadAllCsvBtn = document.getElementById('downloadAllCsvBtn');
const downloadStatus = document.getElementById('downloadStatus');
const siteMeta = <?= json_encode(array_values(array_map(static function (array $s): array {
    return [
        'id' => (int)$s['id'],
        'name' => (string)($s['name'] ?? ''),
        'image_path' => (string)($s['image_path'] ?? ''),
    ];
}, $sites)), JSON_UNESCAPED_SLASHES) ?>;

function currentStudyPeriod() {
  const hourEt = Number(new Intl.DateTimeFormat('en-US', {
    hour: 'numeric',
    hour12: false,
    timeZone: 'America/New_York'
  }).format(new Date()));
  return hourEt < 12 ? 'morning' : 'afternoon';
}

function selectedSiteId() {
  return Number(siteSelect?.value || 0);
}

function selectedSiteMeta() {
  const id = selectedSiteId();
  return siteMeta.find((s) => Number(s.id) === id) || null;
}

function selectedStudyDate() {
  const raw = String(studyDateInput?.value || '').trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
  const now = new Date();
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
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

async function fetchReportPeriod(siteId, studyDate, period) {
  const url = `api/dashboard_data.php?site_id=${encodeURIComponent(siteId)}&study_period=${encodeURIComponent(period)}&study_date=${encodeURIComponent(studyDate)}&include_all_events=1`;
  const res = await fetch(url);
  const json = await res.json();
  if (!json.ok) throw new Error(json.error || `Failed to load ${period} report data.`);
  return json;
}

async function fetchRepeatCutThroughData(siteId, studyDate) {
  const url = `api/repeat_cut_through_data.php?site_id=${encodeURIComponent(siteId)}&study_date=${encodeURIComponent(studyDate)}`;
  const res = await fetch(url);
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
  const rows = Array.from(byCheckpoint.entries())
    .sort((a, b) => a[0].localeCompare(b[0], undefined, { numeric: true, sensitivity: 'base' }))
    .map(([checkpoint, collectors]) => [checkpoint, Array.from(collectors).sort((a, b) => a.localeCompare(b)).join(', ') || 'Not recorded']);
  return rows.length ? rows : [['--', 'No collector names recorded']];
}

function checkpointCountRows(data) {
  const byId = new Map();
  for (const row of (data?.checkpoint_counts_by_id || [])) {
    byId.set(Number(row.checkpoint_id || 0), row);
  }
  const rows = [];
  for (const cp of (data?.checkpoints || [])) {
    const cpId = Number(cp.id || 0);
    const c = byId.get(cpId) || { in: 0, out: 0, total: 0 };
    rows.push([checkpointLabel(cp.display_name, cp.checkpoint_code), String(c.in || 0), String(c.out || 0), String(c.total || 0)]);
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
  const checkpointTotals = (data?.checkpoint_counts_by_id || []).map((row) => Number(row.total || 0));
  if (checkpointTotals.length === 0) {
    for (const [, countRow] of Object.entries(data?.checkpoint_counts || {})) {
      checkpointTotals.push(Number(countRow?.total || 0));
    }
  }
  const highestCheckpointTwoWayFromCounts = checkpointTotals.length > 0 ? Math.max(...checkpointTotals) : 0;
  const highestCheckpointTwoWay = Number(summary.highest_checkpoint_two_way ?? highestCheckpointTwoWayFromCounts);
  const cutThroughCount = Number(summary.cut_through_count ?? 0);
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

async function downloadPdfReport() {
  const siteId = selectedSiteId();
  const studyDate = selectedStudyDate();
  const site = selectedSiteMeta();
  if (!siteId || !site) {
    downloadStatus.textContent = 'Select a valid site.';
    downloadStatus.className = 'status small warn';
    return;
  }

  downloadPdfBtn.disabled = true;
  downloadPdfBtn.textContent = 'Building PDF...';
  downloadStatus.textContent = 'Loading report data...';
  downloadStatus.className = 'status small';
  try {
    await ensurePdfLibraries();
    const [morningData, afternoonData, repeatData] = await Promise.all([
      fetchReportPeriod(siteId, studyDate, 'morning'),
      fetchReportPeriod(siteId, studyDate, 'afternoon'),
      fetchRepeatCutThroughData(siteId, studyDate),
    ]);

    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF({ unit: 'pt', format: 'letter' });
    const pageWidth = pdf.internal.pageSize.getWidth();
    const pageHeight = pdf.internal.pageSize.getHeight();
    const marginX = 40;
    const contentWidth = pageWidth - (marginX * 2);
    const siteName = site.name || `Site ${siteId}`;
    const studyDateText = formatEtDateOnly(morningData.study_date || afternoonData.study_date || studyDate);
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

    if (site.image_path) {
      const dataUri = await imageUrlToDataUri(site.image_path);
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

    const fileName = `ncat_report_${safeFileName(siteName)}_${morningData.study_date || studyDate}.pdf`;
    pdf.save(fileName);
    downloadStatus.textContent = 'PDF generated.';
    downloadStatus.className = 'status small ok';
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Unable to generate report PDF.';
    downloadStatus.textContent = message;
    downloadStatus.className = 'status small warn';
  } finally {
    downloadPdfBtn.disabled = false;
    downloadPdfBtn.textContent = 'Download PDF Report (AM+PM)';
  }
}

function downloadMatchesCsv() {
  const siteId = selectedSiteId();
  const studyPeriod = String(studyPeriodSelect?.value || 'morning');
  const studyDate = selectedStudyDate();
  if (!siteId) {
    downloadStatus.textContent = 'Select a valid site.';
    downloadStatus.className = 'status small warn';
    return;
  }
  const url = `api/export_matches_csv.php?site_id=${encodeURIComponent(siteId)}&study_period=${encodeURIComponent(studyPeriod)}&study_date=${encodeURIComponent(studyDate)}`;
  window.location.href = url;
}

function downloadAllEventsCsv() {
  const siteId = selectedSiteId();
  const studyPeriod = String(studyPeriodSelect?.value || 'morning');
  const studyDate = selectedStudyDate();
  if (!siteId) {
    downloadStatus.textContent = 'Select a valid site.';
    downloadStatus.className = 'status small warn';
    return;
  }
  const url = `api/export_events_csv.php?site_id=${encodeURIComponent(siteId)}&study_period=${encodeURIComponent(studyPeriod)}&study_date=${encodeURIComponent(studyDate)}`;
  window.location.href = url;
}

if (studyPeriodSelect) {
  studyPeriodSelect.value = currentStudyPeriod();
}
if (downloadPdfBtn) downloadPdfBtn.addEventListener('click', downloadPdfReport);
if (downloadMatchesCsvBtn) downloadMatchesCsvBtn.addEventListener('click', downloadMatchesCsv);
if (downloadAllCsvBtn) downloadAllCsvBtn.addEventListener('click', downloadAllEventsCsv);
</script>
<?php endif; ?>
<?php render_foot(); ?>
