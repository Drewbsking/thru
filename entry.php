<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

$siteId = (int)($_GET['site_id'] ?? current_site_id());
if ($siteId <= 0) {
    $siteId = current_site_id();
}
$checkpointId = (int)($_GET['checkpoint_id'] ?? 0);
$site = site_by_id($siteId);
$checkpoints = $site ? checkpoints_for_site($siteId) : [];
$initialCollectorName = '';
if ($checkpointId > 0) {
    foreach ($checkpoints as $cp) {
        if ((int)$cp['id'] === $checkpointId) {
            $initialCollectorName = (string)($cp['collector_name'] ?? '');
            break;
        }
    }
} elseif (count($checkpoints) > 0) {
    $initialCollectorName = (string)($checkpoints[0]['collector_name'] ?? '');
}

render_head('Data Entry');
?>
<section class="card entry-compact">
  <h1>N-CAT Data Entry</h1>
  <?php if ($site): ?>
    <p id="entryGreeting" class="status ok">You are recording at <?= h((string)$site['name']) ?>.</p>
  <?php endif; ?>
  <p class="small" id="studyPeriodLabel">Current Study Period: --</p>
  <p class="small" id="sessionStatusLabel">Study Session: --</p>
  <p class="small" id="checkpointSummaryLabel">Checkpoint Summary: --</p>
  <div class="actions" style="margin-top:0.3rem; margin-bottom:0.5rem;">
    <button type="button" id="startStudyBtn">Start Study</button>
    <button type="button" id="endStudyBtn" class="secondary">End Study</button>
  </div>
  <p class="small">Checkpoint can be locked by link. This prevents wrong checkpoint tagging when different observers are logging traffic. Studies are typically short roadside sessions (around 2 hours).</p>

  <?php if (!$site): ?>
    <p class="status warn">No active site found. Configure a site first in <a href="setup.php">Site Setup</a>.</p>
  <?php else: ?>
    <div class="card" style="margin-bottom:0.5rem; padding:0.7rem;">
      <h2 style="margin-bottom:0.45rem;">Site Map Reminder</h2>
      <div class="actions" style="margin-top:0.25rem;">
        <button type="button" id="mapToggleBtn" class="secondary">Hide Map</button>
      </div>
      <div id="mapPanel" class="site-map-panel open">
        <p class="small" style="margin-top:0;">Use this image to confirm checkpoint numbering before saving.</p>
        <img
          id="entrySitePreview"
          class="site-preview"
          src="<?= !empty($site['image_path']) ? h((string)$site['image_path']) : '' ?>"
          alt="Site image reminder"
          style="<?= empty($site['image_path']) ? 'display:none;' : '' ?>"
        >
        <p id="entrySiteNoImage" class="small" style="<?= !empty($site['image_path']) ? 'display:none;' : '' ?>">
          No image uploaded for this site yet. Add one in <a href="setup.php">Site Setup</a>.
        </p>
      </div>
    </div>

    <div class="form-row">
      <div>
        <label>Site</label>
        <select id="site_id" <?= $checkpointId > 0 ? 'disabled' : '' ?>>
          <?php foreach (all_sites() as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $siteId ? 'selected' : '' ?>><?= h($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Checkpoint</label>
        <select id="checkpoint_id" <?= $checkpointId > 0 ? 'disabled' : '' ?>>
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
          <input id="plate" maxlength="32" placeholder="ABC">
        </div>
        <div>
          <label>Data Collector</label>
          <input id="collector_name_display" value="<?= h($initialCollectorName !== '' ? $initialCollectorName : 'Not set on this checkpoint in Site Setup') ?>" readonly>
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
        <div class="form-row" id="otherColorWrap" style="display:none; margin-top:0.5rem;">
          <div>
            <label>Other Color</label>
            <input id="other_color" maxlength="50" placeholder="Enter color">
          </div>
        </div>
      </div>

      <div class="form-row">
        <div>
          <label>Comments (Optional)</label>
          <textarea id="notes" maxlength="255" placeholder="Any other details"></textarea>
        </div>
      </div>
      <div class="actions">
        <button type="submit">Save Event</button>
        <a class="btn secondary" href="dashboard.php">View Dashboard</a>
        <label class="inline-radio" style="margin-left:0.4rem;">
          <input type="checkbox" id="quickMode" checked> <span>Quick Save + Next</span>
        </label>
      </div>
      <p class="small">Hotkeys (laptop): <code class="inline">I/O</code> direction, <code class="inline">1-5</code> type, <code class="inline">6-0/-</code> color.</p>
      <p id="saveStatus" class="status small" style="margin-top:0.7rem;"></p>
    </form>

    <div class="card" style="margin-top:0.6rem; padding:0.7rem;">
      <h3 style="margin-bottom:0.45rem;">Last 5 Entries (This Checkpoint)</h3>
      <table>
        <thead><tr><th>Time</th><th>Dir</th><th>Plate</th><th>Type/Color</th><th>Action</th></tr></thead>
        <tbody id="recentEntryBody"></tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<script>
const lockedCheckpoint = <?= $checkpointId > 0 ? 'true' : 'false' ?>;
const initialSiteId = <?= (int)$siteId ?>;
const initialCheckpointId = <?= (int)$checkpointId ?>;
let collectorName = <?= json_encode($initialCollectorName, JSON_UNESCAPED_SLASHES) ?>;
let currentCheckpoints = <?= json_encode($checkpoints, JSON_UNESCAPED_SLASHES) ?>;
let pendingConfirmSignature = '';

const siteInput = document.getElementById('site_id');
const cpInput = document.getElementById('checkpoint_id');
const form = document.getElementById('eventForm');
const statusEl = document.getElementById('saveStatus');
const sitePreview = document.getElementById('entrySitePreview');
const noImageMsg = document.getElementById('entrySiteNoImage');
const otherColorWrap = document.getElementById('otherColorWrap');
const otherColorInput = document.getElementById('other_color');
const collectorDisplay = document.getElementById('collector_name_display');
const greetingEl = document.getElementById('entryGreeting');
const mapPanel = document.getElementById('mapPanel');
const mapToggleBtn = document.getElementById('mapToggleBtn');
const plateInput = document.getElementById('plate');
const notesInput = document.getElementById('notes');
const quickModeInput = document.getElementById('quickMode');
const studyPeriodLabel = document.getElementById('studyPeriodLabel');
const sessionStatusLabel = document.getElementById('sessionStatusLabel');
const checkpointSummaryLabel = document.getElementById('checkpointSummaryLabel');
const startStudyBtn = document.getElementById('startStudyBtn');
const endStudyBtn = document.getElementById('endStudyBtn');
const recentEntryBody = document.getElementById('recentEntryBody');

function getSiteId() {
  return Number(lockedCheckpoint ? initialSiteId : (siteInput ? siteInput.value : 0));
}

function getCheckpointId() {
  return Number(lockedCheckpoint ? initialCheckpointId : (cpInput ? cpInput.value : 0));
}

function getCurrentStudyPeriod() {
  return (new Date().getHours() < 12) ? 'morning' : 'afternoon';
}

function currentStudyPeriodLabel() {
  return getCurrentStudyPeriod() === 'morning' ? 'Morning Study' : 'Afternoon Study';
}

function applyMapPanelState() {
  if (!mapPanel || !mapToggleBtn) return;
  const open = mapPanel.classList.contains('open');
  mapToggleBtn.textContent = open ? 'Hide Map' : 'Show Map';
}

if (mapPanel && mapToggleBtn) {
  if (window.matchMedia('(max-width: 700px)').matches) {
    mapPanel.classList.remove('open');
  }
  applyMapPanelState();
  mapToggleBtn.addEventListener('click', () => {
    mapPanel.classList.toggle('open');
    applyMapPanelState();
  });
}

function selectedRadioValue(name) {
  const selected = document.querySelector(`input[name="${name}"]:checked`);
  return selected ? selected.value : '';
}

function selectedVehicleColor() {
  const value = selectedRadioValue('vehicle_color');
  if (value !== 'Other') {
    return value;
  }
  return (otherColorInput ? otherColorInput.value.trim() : '');
}

function toggleOtherColor() {
  if (!otherColorWrap) return;
  const selected = selectedRadioValue('vehicle_color');
  otherColorWrap.style.display = selected === 'Other' ? 'grid' : 'none';
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

function hasAmbiguousPlate(plate) {
  const p = (plate || '').toUpperCase();
  return /[O01IL]/.test(p);
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
  input.addEventListener('change', toggleOtherColor);
  input.addEventListener('change', clearPendingConfirm);
});
toggleOtherColor();
document.querySelectorAll('input[name="vehicle_type"], input[name="direction"]').forEach((input) => {
  input.addEventListener('change', clearPendingConfirm);
});
if (plateInput) plateInput.addEventListener('input', clearPendingConfirm);
if (notesInput) notesInput.addEventListener('input', clearPendingConfirm);

function syncCollectorForSelectedCheckpoint() {
  if (!cpInput) return;
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

async function loadSessionState() {
  const siteId = getSiteId();
  if (!siteId || !sessionStatusLabel) return;
  const period = getCurrentStudyPeriod();
  studyPeriodLabel.textContent = `Current Study Period: ${currentStudyPeriodLabel()}`;
  const res = await fetch(`api/study_session.php?site_id=${siteId}&study_period=${period}`);
  const data = await res.json();
  if (!data.ok) {
    sessionStatusLabel.textContent = 'Study Session: error loading status';
    return;
  }
  if (data.active_session) {
    sessionStatusLabel.textContent = `Study Session: Active (started ${data.active_session.started_at})`;
    if (startStudyBtn) startStudyBtn.disabled = true;
    if (endStudyBtn) endStudyBtn.disabled = false;
  } else {
    sessionStatusLabel.textContent = `Study Session: Not started (${currentStudyPeriodLabel()})`;
    if (startStudyBtn) startStudyBtn.disabled = false;
    if (endStudyBtn) endStudyBtn.disabled = true;
  }
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
  if (!siteId || !checkpointId || !recentEntryBody) return;

  const res = await fetch(`api/recent_checkpoint_events.php?site_id=${siteId}&checkpoint_id=${checkpointId}&limit=5`);
  const data = await res.json();
  if (!data.ok) {
    recentEntryBody.innerHTML = '<tr><td colspan=\"5\">Unable to load recent entries.</td></tr>';
    return;
  }
  recentEntryBody.innerHTML = '';
  for (const e of data.events) {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${e.event_time}</td><td>${e.direction}</td><td>${e.plate_raw || ''}</td><td>${e.vehicle_type}/${e.vehicle_color}</td><td><button type=\"button\" class=\"secondary\" data-edit=\"${e.id}\">Edit</button> <button type=\"button\" class=\"warn\" data-del=\"${e.id}\">Delete</button></td>`;
    recentEntryBody.appendChild(tr);
  }
  if ((data.events || []).length === 0) {
    recentEntryBody.innerHTML = '<tr><td colspan=\"5\">No entries yet.</td></tr>';
  }
}

async function refreshEntryContext() {
  await loadSessionState();
  await loadCheckpointSummary();
  await loadRecentEntries();
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

    if (sitePreview) {
      if (site.image_path) {
        sitePreview.src = site.image_path;
        sitePreview.style.display = 'block';
        if (noImageMsg) noImageMsg.style.display = 'none';
      } else {
        sitePreview.style.display = 'none';
        if (noImageMsg) noImageMsg.style.display = 'block';
      }
    }
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

if (startStudyBtn) {
  startStudyBtn.addEventListener('click', async () => {
    const fd = new FormData();
    fd.append('action', 'start');
    fd.append('site_id', String(getSiteId()));
    fd.append('study_period', getCurrentStudyPeriod());
    const res = await fetch('api/study_session.php', { method: 'POST', body: fd });
    const data = await res.json();
    statusEl.textContent = data.ok ? (data.message || 'Study started.') : (data.error || 'Unable to start study.');
    statusEl.className = data.ok ? 'status ok' : 'status warn';
    await refreshEntryContext();
  });
}

if (endStudyBtn) {
  endStudyBtn.addEventListener('click', async () => {
    const fd = new FormData();
    fd.append('action', 'end');
    fd.append('site_id', String(getSiteId()));
    fd.append('study_period', getCurrentStudyPeriod());
    const res = await fetch('api/study_session.php', { method: 'POST', body: fd });
    const data = await res.json();
    statusEl.textContent = data.ok ? (data.message || 'Study ended.') : (data.error || 'Unable to end study.');
    statusEl.className = data.ok ? 'status ok' : 'status warn';
    await refreshEntryContext();
  });
}

if (recentEntryBody) {
  recentEntryBody.addEventListener('click', async (e) => {
    const target = e.target;
    if (!(target instanceof HTMLElement)) return;
    const eventId = Number(target.dataset.edit || target.dataset.del || 0);
    if (!eventId) return;

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
      const plate = prompt('Edit plate (leave blank to keep):');
      if (plate === null) return;
      const notes = prompt('Edit comments (optional):', notesInput ? notesInput.value : '') ?? '';
      const fd = new FormData();
      fd.append('action', 'edit');
      fd.append('event_id', String(eventId));
      fd.append('site_id', String(getSiteId()));
      fd.append('checkpoint_id', String(getCheckpointId()));
      fd.append('plate_raw', plate);
      fd.append('notes', notes);
      const res = await fetch('api/entry_event_action.php', { method: 'POST', body: fd });
      const data = await res.json();
      statusEl.textContent = data.ok ? 'Entry updated.' : (data.error || 'Update failed.');
      statusEl.className = data.ok ? 'status ok' : 'status warn';
      await refreshEntryContext();
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
    toggleOtherColor();
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
    payload.append('plate', document.getElementById('plate').value);
    payload.append('vehicle_type', vehicleType);
    payload.append('vehicle_color', vehicleColor);
    payload.append('observer_name', collectorName);
    payload.append('notes', document.getElementById('notes').value);

    const signature = currentEntrySignature();
    const plateValue = plateInput ? plateInput.value.trim() : '';
    if (hasAmbiguousPlate(plateValue) && pendingConfirmSignature !== signature) {
      pendingConfirmSignature = signature;
      statusEl.textContent = 'Potential plate typo (O/0 or I/1/L). Press Save Event again to confirm.';
      statusEl.className = 'status warn';
      return;
    }

    if (pendingConfirmSignature !== signature) {
      const dupRes = await fetch('api/check_duplicate.php', { method: 'POST', body: payload });
      const dupJson = await dupRes.json();
      if (dupJson.ok && dupJson.duplicate) {
        pendingConfirmSignature = signature;
        statusEl.textContent = `Possible duplicate near ${dupJson.latest?.event_time || 'just now'}. Press Save Event again to confirm.`;
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
    if (otherColorInput) {
      otherColorInput.value = '';
    }
    toggleOtherColor();
    if (quickModeInput && quickModeInput.checked && plateInput) {
      plateInput.focus();
      plateInput.select();
    }
    await refreshEntryContext();
  });
}
</script>
<?php render_foot(); ?>
