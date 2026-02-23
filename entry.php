<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

$isAdmin = is_admin();
$scopedSites = scoped_sites_for_current_user();
$defaultSiteId = count($scopedSites) > 0 ? (int)$scopedSites[0]['id'] : current_site_id();
$siteId = (int)($_GET['site_id'] ?? $defaultSiteId);
if ($siteId <= 0) {
    $siteId = $defaultSiteId;
}
$isCheckpointLocked = isset($_GET['checkpoint_id']) && (int)($_GET['checkpoint_id']) > 0;
$checkpointId = (int)($_GET['checkpoint_id'] ?? 0);
$site = null;
foreach ($scopedSites as $s) {
    if ((int)$s['id'] === $siteId) {
        $site = $s;
        break;
    }
}
if (!$site && count($scopedSites) > 0) {
    $site = $scopedSites[0];
    $siteId = (int)$site['id'];
}
$checkpoints = $site ? ($site['checkpoints'] ?? []) : [];
if ($checkpointId > 0) {
    $allowed = false;
    foreach ($checkpoints as $cp) {
        if ((int)$cp['id'] === $checkpointId) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        $checkpointId = (int)($checkpoints[0]['id'] ?? 0);
    }
} elseif (count($checkpoints) > 0) {
    $checkpointId = (int)$checkpoints[0]['id'];
}
$initialCollectorName = !$isAdmin ? current_username() : '';
if ($checkpointId > 0) {
    foreach ($checkpoints as $cp) {
        if ((int)$cp['id'] === $checkpointId) {
            if ($isAdmin) {
                $initialCollectorName = (string)($cp['collector_name'] ?? '');
            }
            break;
        }
    }
} elseif ($isAdmin && count($checkpoints) > 0) {
    $initialCollectorName = (string)($checkpoints[0]['collector_name'] ?? '');
}

render_head('Data Entry');
?>
<section class="card entry-compact">
  <h1>Data Entry</h1>
  <?php if ($site): ?>
    <p id="entryGreeting" class="status ok">You are recording at <?= h((string)$site['name']) ?>.</p>
  <?php endif; ?>
  <p class="small">All times use Eastern Time (ET).</p>
  <p class="small" id="studyPeriodLabel">Current Study Period: --</p>
  <p class="small" id="checkpointSummaryLabel">Checkpoint Summary: --</p>
  <p class="small">Checkpoint can be locked by link. This prevents wrong checkpoint tagging when different observers are logging traffic. Studies are typically short roadside sessions (around 2 hours).</p>

  <?php if (!$site): ?>
    <p class="status warn"><?= $isAdmin ? 'No active site found. Configure a site first in Site Setup.' : 'No checkpoint assignment found for your account. Ask an admin to assign your checkpoint.' ?></p>
  <?php else: ?>
    <div class="form-row">
      <div>
        <label>Site</label>
        <select id="site_id" <?= $isCheckpointLocked ? 'disabled' : '' ?>>
          <?php foreach ($scopedSites as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $siteId ? 'selected' : '' ?>><?= h($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Checkpoint</label>
        <select id="checkpoint_id" <?= $isCheckpointLocked ? 'disabled' : '' ?>>
          <?php foreach ($checkpoints as $cp): ?>
            <option value="<?= (int)$cp['id'] ?>" <?= (int)$cp['id'] === $checkpointId ? 'selected' : '' ?>>
              <?= h($cp['display_name']) ?> (<?= h($cp['checkpoint_code']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <form id="eventForm" class="card" style="padding:0; border:0; box-shadow:none;">
      <div class="form-row">
        <div>
          <label>License Plate Number (First 3 characters)</label>
          <input id="plate" maxlength="32" placeholder="ABC" style="text-transform:uppercase;" autocapitalize="characters" spellcheck="false">
        </div>
        <div>
          <label>Data Collector</label>
          <input id="collector_name_display" value="<?= h($initialCollectorName !== '' ? $initialCollectorName : ($isAdmin ? 'Not set on this checkpoint in Site Setup' : current_username())) ?>" readonly>
        </div>
      </div>

      <div class="choice-block">
        <div class="choice-title">Vehicle In/Out</div>
        <div class="inline-radio-group">
          <label class="inline-radio"><input type="radio" name="direction" value="In" checked> <span>In</span></label>
          <label class="inline-radio"><input type="radio" name="direction" value="Out"> <span>Out</span></label>
        </div>
      </div>

      <div class="choice-block">
        <div class="choice-title">Vehicle Type</div>
        <div class="inline-radio-group">
          <label class="inline-radio"><input type="radio" name="vehicle_type" value="Sedan" checked> <span>Sedan</span></label>
          <label class="inline-radio"><input type="radio" name="vehicle_type" value="SUV"> <span>SUV</span></label>
          <label class="inline-radio"><input type="radio" name="vehicle_type" value="Truck"> <span>Truck</span></label>
          <label class="inline-radio"><input type="radio" name="vehicle_type" value="Minivan"> <span>Minivan</span></label>
          <label class="inline-radio"><input type="radio" name="vehicle_type" value="Trailer/Motorcycle"> <span>Trailer/Motorcycle</span></label>
        </div>
      </div>

      <div class="choice-block">
        <div class="choice-title">Vehicle Color</div>
        <div class="inline-radio-group">
          <label class="inline-radio"><input type="radio" name="vehicle_color" value="White" checked> <span>White</span></label>
          <label class="inline-radio"><input type="radio" name="vehicle_color" value="Black/Blue"> <span>Black/Blue</span></label>
          <label class="inline-radio"><input type="radio" name="vehicle_color" value="Gray/Silver"> <span>Gray/Silver</span></label>
          <label class="inline-radio"><input type="radio" name="vehicle_color" value="Red"> <span>Red</span></label>
          <label class="inline-radio"><input type="radio" name="vehicle_color" value="Green"> <span>Green</span></label>
          <label class="inline-radio"><input type="radio" name="vehicle_color" value="Other"> <span>Other</span></label>
        </div>
      </div>

      <div class="form-row">
        <div>
          <label>Comments (Optional)</label>
          <textarea id="notes" maxlength="255" placeholder="ANY OTHER DETAILS" style="text-transform:uppercase;" autocapitalize="characters"></textarea>
        </div>
      </div>
      <div class="actions">
        <button type="submit">Save Event</button>
        <a class="btn secondary" href="dashboard.php">View Dashboard</a>
      </div>
      <p class="small">Hotkeys (laptop): <code class="inline">I/O</code> direction, <code class="inline">1-5</code> type, <code class="inline">6-0/-</code> color.</p>
      <p id="saveStatus" class="status small" style="margin-top:0.7rem;"></p>
    </form>

    <div class="card" style="margin-top:0.6rem; padding:0.7rem;">
      <div style="display:flex; align-items:center; justify-content:space-between; gap:0.5rem;">
        <h3 style="margin-bottom:0;">Last 6 Entries (This Checkpoint)</h3>
        <button type="button" id="recentEntriesToggle" class="secondary" aria-expanded="false" aria-controls="recentEntriesPanel">Show</button>
      </div>
      <div id="recentEntriesPanel" style="display:none; margin-top:0.45rem;">
        <table>
          <thead><tr><th>Event #</th><th>Time</th><th>Dir</th><th>Plate</th><th>Type</th><th>Color</th><th>Comments</th><th>Action</th></tr></thead>
          <tbody id="recentEntryBody"></tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</section>

<script>
const lockedCheckpoint = <?= $isCheckpointLocked ? 'true' : 'false' ?>;
const isAdminUser = <?= $isAdmin ? 'true' : 'false' ?>;
const currentUsername = <?= json_encode(current_username(), JSON_UNESCAPED_SLASHES) ?>;
const initialSiteId = <?= (int)$siteId ?>;
const initialCheckpointId = <?= (int)$checkpointId ?>;
let collectorName = <?= json_encode($initialCollectorName, JSON_UNESCAPED_SLASHES) ?>;
let currentCheckpoints = <?= json_encode($checkpoints, JSON_UNESCAPED_SLASHES) ?>;
let pendingConfirmSignature = '';

const siteInput = document.getElementById('site_id');
const cpInput = document.getElementById('checkpoint_id');
const form = document.getElementById('eventForm');
const statusEl = document.getElementById('saveStatus');
const collectorDisplay = document.getElementById('collector_name_display');
const greetingEl = document.getElementById('entryGreeting');
const plateInput = document.getElementById('plate');
const notesInput = document.getElementById('notes');
const studyPeriodLabel = document.getElementById('studyPeriodLabel');
const checkpointSummaryLabel = document.getElementById('checkpointSummaryLabel');
const recentEntriesToggle = document.getElementById('recentEntriesToggle');
const recentEntriesPanel = document.getElementById('recentEntriesPanel');
const recentEntryBody = document.getElementById('recentEntryBody');
const vehicleTypeOptions = ['Sedan', 'SUV', 'Truck', 'Minivan', 'Trailer/Motorcycle'];
const vehicleColorOptions = ['White', 'Black/Blue', 'Gray/Silver', 'Red', 'Green', 'Other'];

function forceUppercaseInput(el) {
  if (!el) return;
  el.addEventListener('input', () => {
    const start = el.selectionStart;
    const end = el.selectionEnd;
    el.value = (el.value || '').toUpperCase();
    if (start !== null && end !== null) {
      el.setSelectionRange(start, end);
    }
  });
}

function getSiteId() {
  if (lockedCheckpoint) return Number(initialSiteId || 0);
  if (!siteInput) return 0;
  if (!siteInput.value && siteInput.options.length > 0) {
    siteInput.value = siteInput.options[0].value;
  }
  return Number(siteInput.value || 0);
}

function getCheckpointId() {
  if (lockedCheckpoint) return Number(initialCheckpointId || 0);
  if (!cpInput) return 0;
  if (!cpInput.value && cpInput.options.length > 0) {
    cpInput.value = cpInput.options[0].value;
  }
  return Number(cpInput.value || 0);
}

function getCurrentStudyPeriod() {
  const hourEt = Number(new Intl.DateTimeFormat('en-US', {
    hour: 'numeric',
    hour12: false,
    timeZone: 'America/New_York'
  }).format(new Date()));
  return hourEt < 12 ? 'morning' : 'afternoon';
}

function currentStudyPeriodLabel() {
  return getCurrentStudyPeriod() === 'morning' ? 'Morning Study' : 'Afternoon Study';
}

function selectedRadioValue(name) {
  const selected = document.querySelector(`input[name="${name}"]:checked`);
  return selected ? selected.value : '';
}

function selectedVehicleColor() {
  return selectedRadioValue('vehicle_color');
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
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

function notifySuccess() {
  if (navigator.vibrate) {
    navigator.vibrate(45);
  }
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) return;
    const ctx = new AudioCtx();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.value = 880;
    gain.gain.value = 0.05;
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 0.07);
  } catch (e) {
    // Ignore audio failures on locked mobile browsers.
  }
}

function currentEntrySignature() {
  return [
    getSiteId(),
    getCheckpointId(),
    selectedRadioValue('direction') || 'In',
    (plateInput ? plateInput.value.trim().toUpperCase() : ''),
    selectedRadioValue('vehicle_type'),
    selectedVehicleColor().toUpperCase()
  ].join('|');
}

function clearPendingConfirm() {
  pendingConfirmSignature = '';
}

document.querySelectorAll('input[name="vehicle_color"]').forEach((input) => {
  input.addEventListener('change', clearPendingConfirm);
});
document.querySelectorAll('input[name="vehicle_type"], input[name="direction"]').forEach((input) => {
  input.addEventListener('change', clearPendingConfirm);
});
if (plateInput) plateInput.addEventListener('input', clearPendingConfirm);
if (notesInput) notesInput.addEventListener('input', clearPendingConfirm);
forceUppercaseInput(plateInput);
forceUppercaseInput(notesInput);

function syncCollectorForSelectedCheckpoint() {
  if (!cpInput) return;
  if (!isAdminUser) {
    collectorName = currentUsername;
    if (collectorDisplay) {
      collectorDisplay.value = currentUsername;
    }
    return;
  }
  const selectedCheckpointId = Number(cpInput.value || 0);
  const selectedCheckpoint = currentCheckpoints.find(cp => Number(cp.id) === selectedCheckpointId);
  collectorName = selectedCheckpoint && selectedCheckpoint.collector_name ? selectedCheckpoint.collector_name : '';
  if (collectorDisplay) {
    collectorDisplay.value = collectorName || 'Not set on this checkpoint in Site Setup';
  }
}

function syncGreeting(selectedSiteName = null) {
  if (!greetingEl) return;
  const siteName = selectedSiteName || (siteInput ? siteInput.options[siteInput.selectedIndex]?.text : '');
  const checkpointName = cpInput ? cpInput.options[cpInput.selectedIndex]?.text : '';
  greetingEl.textContent = checkpointName
    ? `You are recording at ${siteName} (${checkpointName}).`
    : `You are recording at ${siteName}.`;
}

async function loadCheckpointSummary() {
  const siteId = getSiteId();
  const checkpointId = getCheckpointId();
  if (!siteId || !checkpointId || !checkpointSummaryLabel) return;
  const period = getCurrentStudyPeriod();
  const res = await fetch(`api/dashboard_data.php?site_id=${siteId}&study_period=${period}`);
  const data = await res.json();
  if (!data.ok) {
    checkpointSummaryLabel.textContent = 'Checkpoint Summary: unavailable';
    return;
  }
  const row = (data.checkpoint_counts_by_id || []).find(r => Number(r.checkpoint_id) === checkpointId);
  if (!row) {
    checkpointSummaryLabel.textContent = `Checkpoint Summary (${currentStudyPeriodLabel()}): Total 0 (In 0 / Out 0)`;
    return;
  }
  checkpointSummaryLabel.textContent = `Checkpoint Summary (${currentStudyPeriodLabel()}): Total ${row.total} (In ${row.in} / Out ${row.out})`;
}

async function loadRecentEntries() {
  const siteId = getSiteId();
  const checkpointId = getCheckpointId();
  if (!recentEntryBody) return;
  if (!siteId || !checkpointId) {
    recentEntryBody.innerHTML = '<tr><td colspan=\"8\">Select a valid checkpoint to view entries.</td></tr>';
    return;
  }

  const res = await fetch(`api/recent_checkpoint_events.php?site_id=${siteId}&checkpoint_id=${checkpointId}&limit=6`);
  const data = await res.json();
  if (!data.ok) {
    recentEntryBody.innerHTML = '<tr><td colspan=\"8\">Unable to load recent entries.</td></tr>';
    return;
  }
  recentEntryBody.innerHTML = '';
  for (const e of data.events) {
    const typeOptions = vehicleTypeOptions.map((opt) => `<option value="${escapeHtml(opt)}"${opt === e.vehicle_type ? ' selected' : ''}>${escapeHtml(opt)}</option>`).join('');
    const colorOptions = vehicleColorOptions.map((opt) => `<option value="${escapeHtml(opt)}"${opt === e.vehicle_color ? ' selected' : ''}>${escapeHtml(opt)}</option>`).join('');
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${e.id}</td>
      <td>${escapeHtml(formatEtDateTime(e.event_time))}</td>
      <td data-field=\"direction\">${escapeHtml(e.direction)}</td>
      <td data-field=\"plate_raw\">${escapeHtml(e.plate_raw || '')}</td>
      <td data-field=\"vehicle_type\">${escapeHtml(e.vehicle_type)}</td>
      <td data-field=\"vehicle_color\">${escapeHtml(e.vehicle_color)}</td>
      <td data-field=\"notes\">${escapeHtml(e.notes || '')}</td>
      <td>
        <button type=\"button\" class=\"secondary\" data-edit=\"${e.id}\">Edit</button>
        <button type=\"button\" class=\"warn\" data-del=\"${e.id}\">Delete</button>
      </td>`;
    tr.dataset.eventId = String(e.id);
    tr.dataset.editing = 'false';
    tr.dataset.direction = String(e.direction || 'In');
    tr.dataset.plateRaw = String(e.plate_raw || '');
    tr.dataset.vehicleType = String(e.vehicle_type || 'Sedan');
    tr.dataset.vehicleColor = String(e.vehicle_color || 'White');
    tr.dataset.notes = String(e.notes || '');
    tr.dataset.typeOptions = typeOptions;
    tr.dataset.colorOptions = colorOptions;
    recentEntryBody.appendChild(tr);
  }
  if ((data.events || []).length === 0) {
    recentEntryBody.innerHTML = '<tr><td colspan=\"8\">No entries yet.</td></tr>';
  }
}

function enterRecentEditMode(tr) {
  if (!tr || tr.dataset.editing === 'true') return;
  tr.dataset.editing = 'true';
  tr.querySelector('[data-field="direction"]').innerHTML = `
    <select data-input="direction">
      <option value="In"${tr.dataset.direction === 'In' ? ' selected' : ''}>In</option>
      <option value="Out"${tr.dataset.direction === 'Out' ? ' selected' : ''}>Out</option>
    </select>`;
  tr.querySelector('[data-field="plate_raw"]').innerHTML = `<input data-input="plate_raw" maxlength="32" value="${escapeHtml(tr.dataset.plateRaw || '')}" style="text-transform:uppercase;" autocapitalize="characters" spellcheck="false">`;
  tr.querySelector('[data-field="vehicle_type"]').innerHTML = `<select data-input="vehicle_type">${tr.dataset.typeOptions || ''}</select>`;
  tr.querySelector('[data-field="vehicle_color"]').innerHTML = `<select data-input="vehicle_color">${tr.dataset.colorOptions || ''}</select>`;
  tr.querySelector('[data-field="notes"]').innerHTML = `<input data-input="notes" maxlength="255" value="${escapeHtml(tr.dataset.notes || '')}" style="text-transform:uppercase;" autocapitalize="characters">`;
  const actionCell = tr.lastElementChild;
  if (actionCell) {
    actionCell.innerHTML = `<button type="button" data-save="${tr.dataset.eventId}">Save</button> <button type="button" class="secondary" data-cancel="${tr.dataset.eventId}">Cancel</button>`;
  }
}

function exitRecentEditMode(tr) {
  if (!tr) return;
  tr.dataset.editing = 'false';
  tr.querySelector('[data-field="direction"]').textContent = tr.dataset.direction || '';
  tr.querySelector('[data-field="plate_raw"]').textContent = tr.dataset.plateRaw || '';
  tr.querySelector('[data-field="vehicle_type"]').textContent = tr.dataset.vehicleType || '';
  tr.querySelector('[data-field="vehicle_color"]').textContent = tr.dataset.vehicleColor || '';
  tr.querySelector('[data-field="notes"]').textContent = tr.dataset.notes || '';
  const actionCell = tr.lastElementChild;
  if (actionCell) {
    actionCell.innerHTML = `<button type="button" class="secondary" data-edit="${tr.dataset.eventId}">Edit</button> <button type="button" class="warn" data-del="${tr.dataset.eventId}">Delete</button>`;
  }
}

async function refreshEntryContext() {
  if (studyPeriodLabel) {
    studyPeriodLabel.textContent = `Current Study Period: ${currentStudyPeriodLabel()}`;
  }
  await loadCheckpointSummary();
  if (recentEntriesPanel && recentEntriesPanel.style.display !== 'none') {
    await loadRecentEntries();
  }
}

if (siteInput && cpInput && !lockedCheckpoint) {
  siteInput.addEventListener('change', async () => {
    const res = await fetch('api/site_context.php');
    const data = await res.json();
    if (!data.ok) return;
    const selectedSite = Number(siteInput.value);
    const site = data.sites.find(s => Number(s.id) === selectedSite);
    cpInput.innerHTML = '';
    if (!site) return;
    currentCheckpoints = site.checkpoints || [];
    for (const cp of site.checkpoints) {
      const opt = document.createElement('option');
      opt.value = cp.id;
      opt.textContent = `${cp.display_name} (${cp.checkpoint_code})`;
      cpInput.appendChild(opt);
    }
    syncCollectorForSelectedCheckpoint();
    syncGreeting(site.name || '');
    await refreshEntryContext();
  });
}

if (cpInput) {
  cpInput.addEventListener('change', async () => {
    syncCollectorForSelectedCheckpoint();
    syncGreeting();
    await refreshEntryContext();
  });
}
syncCollectorForSelectedCheckpoint();
syncGreeting();
refreshEntryContext();

if (recentEntriesToggle && recentEntriesPanel) {
  recentEntriesToggle.addEventListener('click', async () => {
    const isHidden = recentEntriesPanel.style.display === 'none';
    recentEntriesPanel.style.display = isHidden ? 'block' : 'none';
    recentEntriesToggle.textContent = isHidden ? 'Hide' : 'Show';
    recentEntriesToggle.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
    if (isHidden) {
      await loadRecentEntries();
    }
  });
}

if (recentEntryBody) {
  recentEntryBody.addEventListener('click', async (e) => {
    const target = e.target;
    if (!(target instanceof HTMLElement)) return;
    const eventId = Number(target.dataset.edit || target.dataset.del || target.dataset.save || target.dataset.cancel || 0);
    if (!eventId) return;
    const tr = target.closest('tr');
    if (!tr) return;

    if (target.dataset.del) {
      if (!confirm('Delete this entry?')) return;
      const fd = new FormData();
      fd.append('action', 'delete');
      fd.append('event_id', String(eventId));
      fd.append('site_id', String(getSiteId()));
      fd.append('checkpoint_id', String(getCheckpointId()));
      const res = await fetch('api/entry_event_action.php', { method: 'POST', body: fd });
      const data = await res.json();
      statusEl.textContent = data.ok ? 'Entry deleted.' : (data.error || 'Delete failed.');
      statusEl.className = data.ok ? 'status ok' : 'status warn';
      await refreshEntryContext();
      return;
    }

    if (target.dataset.edit) {
      enterRecentEditMode(tr);
      const plateEditInput = tr.querySelector('[data-input="plate_raw"]');
      if (plateEditInput instanceof HTMLInputElement) {
        plateEditInput.focus();
        plateEditInput.select();
      }
      return;
    }

    if (target.dataset.cancel) {
      exitRecentEditMode(tr);
      return;
    }

    if (target.dataset.save) {
      const direction = (tr.querySelector('[data-input="direction"]')?.value || 'In') === 'Out' ? 'Out' : 'In';
      const plate = (tr.querySelector('[data-input="plate_raw"]')?.value || '').toUpperCase();
      const vehicleType = tr.querySelector('[data-input="vehicle_type"]')?.value || '';
      const vehicleColor = tr.querySelector('[data-input="vehicle_color"]')?.value || '';
      const notes = (tr.querySelector('[data-input="notes"]')?.value || '').toUpperCase();

      if (!vehicleType || !vehicleColor) {
        statusEl.textContent = 'Type and color are required.';
        statusEl.className = 'status warn';
        return;
      }

      const fd = new FormData();
      fd.append('action', 'edit');
      fd.append('event_id', String(eventId));
      fd.append('site_id', String(getSiteId()));
      fd.append('checkpoint_id', String(getCheckpointId()));
      fd.append('direction', direction);
      fd.append('plate_raw', plate);
      fd.append('vehicle_type', vehicleType);
      fd.append('vehicle_color', vehicleColor);
      fd.append('notes', notes);
      const res = await fetch('api/entry_event_action.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.ok) {
        statusEl.textContent = data.error || 'Update failed.';
        statusEl.className = 'status warn';
        return;
      }
      tr.dataset.direction = direction;
      tr.dataset.plateRaw = plate;
      tr.dataset.vehicleType = vehicleType;
      tr.dataset.vehicleColor = vehicleColor;
      tr.dataset.notes = notes;
      exitRecentEditMode(tr);
      statusEl.textContent = 'Entry updated.';
      statusEl.className = 'status ok';
      await loadCheckpointSummary();
    }
  });
}

document.addEventListener('keydown', (e) => {
  const tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
  if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

  const key = e.key.toLowerCase();
  if (key === 'i') {
    document.querySelector('input[name=\"direction\"][value=\"In\"]')?.click();
    return;
  }
  if (key === 'o') {
    document.querySelector('input[name=\"direction\"][value=\"Out\"]')?.click();
    return;
  }

  const typeMap = { '1': 'Sedan', '2': 'SUV', '3': 'Truck', '4': 'Minivan', '5': 'Trailer/Motorcycle' };
  if (typeMap[key]) {
    document.querySelector(`input[name=\"vehicle_type\"][value=\"${typeMap[key]}\"]`)?.click();
    return;
  }

  const colorMap = { '6': 'White', '7': 'Black/Blue', '8': 'Gray/Silver', '9': 'Red', '0': 'Green', '-': 'Other' };
  if (colorMap[key]) {
    document.querySelector(`input[name=\"vehicle_color\"][value=\"${colorMap[key]}\"]`)?.click();
  }
});

if (form) {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const payload = new FormData();
    const vehicleType = selectedRadioValue('vehicle_type');
    const vehicleColor = selectedVehicleColor();
    if (!vehicleType || !vehicleColor) {
      statusEl.textContent = 'Select a vehicle type and color.';
      statusEl.className = 'status warn';
      return;
    }

    payload.append('site_id', lockedCheckpoint ? String(initialSiteId) : siteInput.value);
    payload.append('checkpoint_id', lockedCheckpoint ? String(initialCheckpointId) : cpInput.value);
    payload.append('direction', selectedRadioValue('direction') || 'In');
    payload.append('plate', (document.getElementById('plate').value || '').toUpperCase());
    payload.append('vehicle_type', vehicleType);
    payload.append('vehicle_color', (vehicleColor || '').toUpperCase());
    payload.append('observer_name', collectorName);
    payload.append('notes', (document.getElementById('notes').value || '').toUpperCase());

    const signature = currentEntrySignature();
    if (pendingConfirmSignature !== signature) {
      const dupRes = await fetch('api/check_duplicate.php', { method: 'POST', body: payload });
      const dupJson = await dupRes.json();
      if (dupJson.ok && dupJson.duplicate) {
        pendingConfirmSignature = signature;
        const dupTime = dupJson.latest?.event_time ? formatEtDateTime(dupJson.latest.event_time) : 'just now';
        statusEl.textContent = `Possible duplicate near ${dupTime}. Press Save Event again to confirm.`;
        statusEl.className = 'status warn';
        return;
      }
    }

    const res = await fetch('api/submit_event.php', { method: 'POST', body: payload });
    const json = await res.json();
    if (!json.ok) {
      statusEl.textContent = json.error || 'Save failed.';
      statusEl.className = 'status warn';
      return;
    }

    statusEl.textContent = `Saved event #${json.id}`;
    statusEl.className = 'status ok';
    pendingConfirmSignature = '';
    notifySuccess();
    document.getElementById('plate').value = '';
    document.getElementById('notes').value = '';
    if (plateInput) {
      plateInput.focus();
      plateInput.select();
    }
    await refreshEntryContext();
  });
}
</script>
<?php render_foot(); ?>
