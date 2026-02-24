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
    <div>
      <label>&nbsp;</label>
      <button id="downloadReportBtn" type="button" class="secondary">Download PDF Report (AM+PM)</button>
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

<section class="card" style="margin-top:1rem;">
  <h2>Cut-Through by Checkpoint Pair</h2>
  <p class="small">Matched cut-through vehicles grouped by checkpoint direction pair.</p>
  <p class="small" id="pairChartMeta">No pair data loaded yet.</p>
  <div id="pairChart" class="pair-chart"></div>
</section>

<section class="card" style="margin-top:1rem;">
  <h2>Checkpoint Counts</h2>
  <table>
    <thead><tr><th>Checkpoint</th><th>In</th><th>Out</th><th>Total (Two-Way)</th></tr></thead>
    <tbody id="checkpointBody"></tbody>
  </table>
</section>

<section class="card" style="margin-top:1rem;">
  <h2>Cut-Through Matches</h2>
  <table>
    <thead><tr><th>In Event #</th><th>Out Event #</th><th>Elapsed</th><th>Expected</th><th>Avg Speed</th><th>Confidence</th></tr></thead>
    <tbody id="matchBody"></tbody>
  </table>
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
const downloadReportBtn = document.getElementById('downloadReportBtn');
const studyPeriodSelect = document.getElementById('study_period');
let reportBusy = false;

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

function pairCountsByRoute(matches) {
  const grouped = groupMatchesByRoute(matches).map(([route, rows]) => ({ route, count: rows.length }));
  return grouped.sort((a, b) => (b.count - a.count) || a.route.localeCompare(b.route, undefined, { numeric: true, sensitivity: 'base' }));
}

function renderPairChart(matches) {
  const chartEl = document.getElementById('pairChart');
  const metaEl = document.getElementById('pairChartMeta');
  if (!chartEl || !metaEl) return;

  const pairCounts = pairCountsByRoute(matches || []);
  if (pairCounts.length === 0) {
    metaEl.textContent = 'No cut-through pair matches in this period.';
    chartEl.innerHTML = '<div class="small">No chart data to display.</div>';
    return;
  }

  const top = pairCounts[0];
  metaEl.textContent = `Unique pairs: ${pairCounts.length} | Top pair: ${top.route} (${top.count})`;
  const maxCount = Math.max(...pairCounts.map((p) => p.count), 1);
  chartEl.innerHTML = pairCounts.map((pair) => {
    const widthPct = Math.max(8, Math.round((pair.count / maxCount) * 100));
    return `<div class="pair-bar-row">
      <div class="pair-bar-head">
        <span class="pair-route">${pair.route}</span>
        <span class="pair-count">${pair.count}</span>
      </div>
      <div class="pair-bar-track">
        <div class="pair-bar-fill" style="width:${widthPct}%"></div>
      </div>
    </div>`;
  }).join('');
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

async function fetchReportPeriod(period) {
  const res = await fetch(`api/dashboard_data.php?site_id=${activeSiteId}&study_period=${encodeURIComponent(period)}&include_all_events=1`);
  const json = await res.json();
  if (!json.ok) throw new Error(json.error || `Failed to load ${period} report data.`);
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

function pairCountRows(matches) {
  const rows = groupMatchesByRoute(matches || [])
    .map(([route, routeMatches]) => [route, String(routeMatches.length)])
    .sort((a, b) => Number(b[1]) - Number(a[1]));
  return rows.length ? rows : [['No matches', '0']];
}

function matchRows(matches) {
  const rows = (matches || []).map((m) => {
    const route = `${m?.in_event?.checkpoint_code || m?.in_event?.checkpoint_name || 'In'} -> ${m?.out_event?.checkpoint_code || m?.out_event?.checkpoint_name || 'Out'}`;
    return [
      String(m?.in_event?.id || ''),
      String(m?.out_event?.id || ''),
      route,
      `${m?.elapsed_minutes ?? ''} min`,
      `${m?.expected_minutes ?? ''} min`,
      `${m?.avg_speed_mph ?? ''} mph`,
      String(m?.confidence ?? ''),
    ];
  });
  return rows.length ? rows : [['', '', 'No matches', '', '', '', '']];
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
  const avgCutThroughSpeed = (data?.matches || []).length
    ? ((data.matches || []).reduce((acc, m) => acc + Number(m.avg_speed_mph || 0), 0) / data.matches.length).toFixed(2)
    : '0.00';
  return [
    ['Start Time (First Entry)', formatEtDateTime(summary.start_time, true)],
    ['End Time (Last Entry)', formatEtDateTime(summary.end_time, true)],
    ['Total Volume (Two-Way)', String(summary.total_volume ?? 0)],
    ['Cut-Through Vehicles', String(summary.cut_through_count ?? 0)],
    ['Cut-Through %', `${summary.cut_through_percent ?? 0}%`],
    ['Policy Status', summary.meets_policy ? 'Meets 25% Policy' : 'Below 25% Policy'],
    ['Local Arrivals (In only)', String(summary.local_arrivals_count ?? 0)],
    ['Local Departures (Out only)', String(summary.local_departures_count ?? 0)],
    ['Average Cut-Through Speed', `${avgCutThroughSpeed} mph`],
    ['Expected Speed Setting', `${data?.settings?.speed_mph ?? ''} mph`],
    ['Buffer Window', `${data?.settings?.buffer_minutes ?? ''} min`],
    ['Min Confidence', String(data?.settings?.min_confidence ?? '')],
  ];
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
    const morningData = await fetchReportPeriod('morning');
    const afternoonData = await fetchReportPeriod('afternoon');

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
    pdf.text('N-CAT Formal Study Report', marginX, y);
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
        head: [['Checkpoint Pair', 'Matched Cut-Through Count']],
        body: pairCountRows(data.matches || []),
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
  const studyPeriod = studyPeriodSelect.value;
  const res = await fetch(`api/dashboard_data.php?site_id=${activeSiteId}&study_period=${studyPeriod}`);
  const json = await res.json();
  if (!json.ok) return;

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
  renderPairChart(json.matches || []);

  const cpBody = document.getElementById('checkpointBody');
  cpBody.innerHTML = '';
  const cpEntries = Object.entries(json.checkpoint_counts || {});
  if (cpEntries.length === 0) {
    cpBody.innerHTML = '<tr><td colspan="4">No events in this study period/date.</td></tr>';
  } else {
    cpEntries.forEach(([name, c]) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${name}</td><td>${c.in}</td><td>${c.out}</td><td>${c.total}</td>`;
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
      const section = document.createElement('tr');
      section.innerHTML = `<td colspan="6" style="background:#eef2ff; font-weight:700;">${route} (${matches.length})</td>`;
      matchBody.appendChild(section);

      matches.forEach((m) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${m.in_event.id}</td>
          <td>${m.out_event.id}</td>
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
  if (!(json.recent_events || []).length) {
    recentBody.innerHTML = '<tr><td colspan="8">No recent events in this study period/date.</td></tr>';
  } else {
    json.recent_events.forEach(e => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${e.id}</td><td>${formatEtDateTime(e.event_time)}</td><td>${e.checkpoint_name}</td><td>${e.direction}</td><td>${e.plate_raw || ''}</td><td>${e.vehicle_type}</td><td>${e.vehicle_color}</td><td>${e.observer_name || ''}</td>`;
      recentBody.appendChild(tr);
    });
  }

  if (timer) clearTimeout(timer);
  timer = setTimeout(loadDashboard, pollMs);
}

if (refreshBtn) refreshBtn.addEventListener('click', loadDashboard);
if (downloadReportBtn) downloadReportBtn.addEventListener('click', downloadFormalReportPdf);
if (studyPeriodSelect) {
  studyPeriodSelect.addEventListener('change', loadDashboard);
  studyPeriodSelect.value = currentStudyPeriod();
}
loadDashboard();
</script>
<?php render_foot(); ?>
