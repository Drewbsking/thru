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
$selectedCheckpoint = null;
foreach ($checkpoints as $cp) {
    if ((int)$cp['id'] === $checkpointId) {
        $selectedCheckpoint = $cp;
        break;
    }
}
$selectedCheckpointLabel = $selectedCheckpoint
    ? ((string)$selectedCheckpoint['display_name'] . ' (' . (string)$selectedCheckpoint['checkpoint_code'] . ')')
    : '';
$showSelectors = $isAdmin && !$isCheckpointLocked && (count($scopedSites) > 1 || count($checkpoints) > 1);
$initialCollectorName = current_username();

render_head('Data Entry');
?>
<section class="card entry-compact">
  <?php if ($site): ?>
    <div class="entry-meta-toggle">
      <button type="button" id="entryMetaToggle" class="secondary" aria-expanded="true" aria-controls="entryMetaPanel">Hide Study Info</button>
    </div>
  <?php endif; ?>
  <div id="entryMetaPanel" class="entry-meta-panel">
    <?php if ($site): ?>
      <p id="entryGreeting" class="status ok">Welcome, <?= h($initialCollectorName !== '' ? $initialCollectorName : 'Collector') ?>. You are logged in.</p>
      <p class="small" id="entryContextLabel">Site: <?= h((string)$site['name']) ?> | Checkpoint: <?= h($selectedCheckpointLabel !== '' ? $selectedCheckpointLabel : '--') ?></p>
    <?php endif; ?>
    <p class="small">All times use Eastern Time (ET).</p>
    <p class="small" id="studyPeriodLabel">Current Study Period: --</p>
    <p class="small" id="checkpointSummaryLabel">Checkpoint Summary: --</p>
  </div>

  <?php if (!$site): ?>
    <p class="status warn"><?= $isAdmin ? 'No active site found. Configure a site first in Site Setup.' : 'No checkpoint assignment found for your account. Ask an admin to assign your checkpoint.' ?></p>
  <?php else: ?>
    <?php if ($showSelectors): ?>
      <div class="form-row">
        <div>
          <label>Site</label>
          <select id="site_id">
            <?php foreach ($scopedSites as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $siteId ? 'selected' : '' ?>><?= h($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Checkpoint</label>
          <select id="checkpoint_id">
            <?php foreach ($checkpoints as $cp): ?>
              <option value="<?= (int)$cp['id'] ?>" <?= (int)$cp['id'] === $checkpointId ? 'selected' : '' ?>>
                <?= h($cp['display_name']) ?> (<?= h($cp['checkpoint_code']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    <?php endif; ?>

    <form id="eventForm" class="card" style="padding:0; border:0; box-shadow:none;">
      <div class="form-row">
        <div>
          <label>License Plate Number (First 3 characters)</label>
          <input id="plate" maxlength="32" placeholder="ABC" style="text-transform:uppercase;" autocapitalize="characters" spellcheck="false">
        </div>
      </div>

      <div class="choice-block">
        <div class="choice-title">Vehicle In/Out</div>
        <div class="direction-chip-grid">
          <label class="radio-chip direction-chip">
            <input type="radio" name="direction" value="In">
            <span><span class="direction-icon"><img src="login-svgrepo-com.svg" alt="" class="direction-icon-img"></span><span class="direction-name">In</span></span>
          </label>
          <label class="radio-chip direction-chip">
            <input type="radio" name="direction" value="Out">
            <span><span class="direction-icon"><img src="logout-svgrepo-com.svg" alt="" class="direction-icon-img"></span><span class="direction-name">Out</span></span>
          </label>
        </div>
      </div>

      <div class="choice-block">
        <div class="choice-title">Vehicle Type</div>
        <div class="vehicle-type-grid">
          <label class="radio-chip vehicle-type-chip">
            <input type="radio" name="vehicle_type" value="Sedan">
            <span><span class="vehicle-icon">🚗</span><span class="vehicle-name">Sedan</span></span>
          </label>
          <label class="radio-chip vehicle-type-chip">
            <input type="radio" name="vehicle_type" value="SUV">
            <span><span class="vehicle-icon">🚙</span><span class="vehicle-name">SUV</span></span>
          </label>
          <label class="radio-chip vehicle-type-chip">
            <input type="radio" name="vehicle_type" value="Pickup Truck">
            <span><span class="vehicle-icon">🛻</span><span class="vehicle-name">Pickup Truck</span></span>
          </label>
          <label class="radio-chip vehicle-type-chip">
            <input type="radio" name="vehicle_type" value="Truck">
            <span><span class="vehicle-icon">🚛</span><span class="vehicle-name">Truck</span></span>
          </label>
          <label class="radio-chip vehicle-type-chip">
            <input type="radio" name="vehicle_type" value="Minivan">
            <span><span class="vehicle-icon">🚐</span><span class="vehicle-name">Minivan</span></span>
          </label>
          <label class="radio-chip vehicle-type-chip">
            <input type="radio" name="vehicle_type" value="Motorcycle">
            <span><span class="vehicle-icon">🏍️</span><span class="vehicle-name">Motorcycle</span></span>
          </label>
          <label class="radio-chip vehicle-type-chip">
            <input type="radio" name="vehicle_type" value="Other">
            <span><span class="vehicle-icon">🛸</span><span class="vehicle-name">Other</span></span>
          </label>
        </div>
      </div>

      <div class="choice-block">
        <div class="choice-title">Vehicle Color</div>
        <div class="color-chip-grid">
          <label class="radio-chip color-chip color-white">
            <input type="radio" name="vehicle_color" value="White">
            <span>White</span>
          </label>
          <label class="radio-chip color-chip color-black-blue">
            <input type="radio" name="vehicle_color" value="Black/Blue">
            <span>Black/Blue</span>
          </label>
          <label class="radio-chip color-chip color-gray-silver">
            <input type="radio" name="vehicle_color" value="Gray/Silver">
            <span>Gray/Silver</span>
          </label>
          <label class="radio-chip color-chip color-red">
            <input type="radio" name="vehicle_color" value="Red">
            <span>Red</span>
          </label>
          <label class="radio-chip color-chip color-green">
            <input type="radio" name="vehicle_color" value="Green">
            <span>Green</span>
          </label>
          <label class="radio-chip color-chip color-other">
            <input type="radio" name="vehicle_color" value="Other">
            <span>Other</span>
          </label>
        </div>
      </div>

      <div class="choice-block">
        <div class="notes-toggle-row">
          <div class="choice-title" style="margin-bottom:0;">Comments (Optional)</div>
          <button type="button" id="notesToggle" class="secondary" aria-expanded="false" aria-controls="notesPanel">Show</button>
        </div>
        <div id="notesPanel" hidden style="margin-top:0.35rem;">
          <textarea id="notes" maxlength="255" placeholder="ANY OTHER DETAILS" style="text-transform:uppercase;" autocapitalize="characters"></textarea>
        </div>
      </div>
      <div class="actions">
        <button type="submit">Save Event</button>
        <a id="recentEntriesLink" class="btn secondary" href="recent_entries.php?site_id=<?= (int)$siteId ?>&checkpoint_id=<?= (int)$checkpointId ?>">Recent Entries</a>
      </div>
      <p id="saveStatus" class="status small" style="margin-top:0.7rem;"></p>
    </form>
  <?php endif; ?>
</section>

<script>
const currentUsername = <?= json_encode(current_username(), JSON_UNESCAPED_SLASHES) ?>;
const initialSiteId = <?= (int)$siteId ?>;
const initialCheckpointId = <?= (int)$checkpointId ?>;
const initialSiteName = <?= json_encode((string)($site['name'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
const initialCheckpointLabel = <?= json_encode($selectedCheckpointLabel, JSON_UNESCAPED_SLASHES) ?>;
const collectorName = currentUsername;
let activeSiteId = Number(initialSiteId || 0);
let activeCheckpointId = Number(initialCheckpointId || 0);
let activeSiteName = initialSiteName;
let activeCheckpointLabel = initialCheckpointLabel;
let pendingConfirmSignature = '';

const siteInput = document.getElementById('site_id');
const cpInput = document.getElementById('checkpoint_id');
const form = document.getElementById('eventForm');
const statusEl = document.getElementById('saveStatus');
const greetingEl = document.getElementById('entryGreeting');
const contextLabelEl = document.getElementById('entryContextLabel');
const plateInput = document.getElementById('plate');
const notesInput = document.getElementById('notes');
const notesToggle = document.getElementById('notesToggle');
const notesPanel = document.getElementById('notesPanel');
const studyPeriodLabel = document.getElementById('studyPeriodLabel');
const checkpointSummaryLabel = document.getElementById('checkpointSummaryLabel');
const recentEntriesLink = document.getElementById('recentEntriesLink');
const entryMetaToggle = document.getElementById('entryMetaToggle');
const entryMetaPanel = document.getElementById('entryMetaPanel');

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
  if (siteInput) {
    if (!siteInput.value && siteInput.options.length > 0) {
      siteInput.value = siteInput.options[0].value;
    }
    activeSiteId = Number(siteInput.value || 0);
    activeSiteName = siteInput.options[siteInput.selectedIndex]?.text || activeSiteName;
  }
  return Number(activeSiteId || 0);
}

function getCheckpointId() {
  if (cpInput) {
    if (!cpInput.value && cpInput.options.length > 0) {
      cpInput.value = cpInput.options[0].value;
    }
    activeCheckpointId = Number(cpInput.value || 0);
    activeCheckpointLabel = cpInput.options[cpInput.selectedIndex]?.text || activeCheckpointLabel;
  }
  return Number(activeCheckpointId || 0);
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
    selectedRadioValue('direction'),
    (plateInput ? plateInput.value.trim().toUpperCase() : ''),
    selectedRadioValue('vehicle_type'),
    selectedVehicleColor()
  ].join('|');
}

function clearPendingConfirm() {
  pendingConfirmSignature = '';
}

function setNotesExpanded(expanded) {
  if (!notesToggle || !notesPanel) return;
  if (expanded) {
    notesPanel.removeAttribute('hidden');
  } else {
    notesPanel.setAttribute('hidden', 'hidden');
  }
  notesToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  notesToggle.textContent = expanded ? 'Hide' : 'Show';
}

function setEntryMetaExpanded(expanded) {
  if (!entryMetaToggle || !entryMetaPanel) return;
  if (expanded) {
    entryMetaPanel.removeAttribute('hidden');
  } else {
    entryMetaPanel.setAttribute('hidden', 'hidden');
  }
  entryMetaToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  entryMetaToggle.textContent = expanded ? 'Hide Study Info' : 'Show Study Info';
}

if (entryMetaToggle && entryMetaPanel) {
  const isSmallScreen = window.matchMedia('(max-width: 700px)').matches;
  setEntryMetaExpanded(!isSmallScreen);
  entryMetaToggle.addEventListener('click', () => {
    setEntryMetaExpanded(entryMetaPanel.hasAttribute('hidden'));
  });
}
if (notesToggle && notesPanel) {
  setNotesExpanded(false);
  notesToggle.addEventListener('click', () => {
    const expand = notesPanel.hasAttribute('hidden');
    setNotesExpanded(expand);
    if (expand && notesInput) {
      notesInput.focus();
    }
  });
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

function syncGreeting() {
  if (!greetingEl) return;
  greetingEl.textContent = `Welcome, ${currentUsername}. You are logged in.`;
}

function syncContextLabel() {
  if (!contextLabelEl) return;
  const siteName = activeSiteName || '--';
  const checkpointName = activeCheckpointLabel || '--';
  contextLabelEl.textContent = `Site: ${siteName} | Checkpoint: ${checkpointName}`;
}

function syncRecentEntriesLink() {
  if (!recentEntriesLink) return;
  const siteId = getSiteId();
  const checkpointId = getCheckpointId();
  const params = new URLSearchParams();
  if (siteId > 0) params.set('site_id', String(siteId));
  if (checkpointId > 0) params.set('checkpoint_id', String(checkpointId));
  recentEntriesLink.href = params.toString() !== '' ? `recent_entries.php?${params.toString()}` : 'recent_entries.php';
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

async function refreshEntryContext() {
  if (studyPeriodLabel) {
    studyPeriodLabel.textContent = `Current Study Period: ${currentStudyPeriodLabel()}`;
  }
  await loadCheckpointSummary();
}

if (siteInput && cpInput) {
  siteInput.addEventListener('change', async () => {
    const res = await fetch('api/site_context.php');
    const data = await res.json();
    if (!data.ok) return;
    const selectedSite = Number(siteInput.value);
    const site = data.sites.find(s => Number(s.id) === selectedSite);
    cpInput.innerHTML = '';
    if (!site) return;
    activeSiteId = selectedSite;
    activeSiteName = site.name || '';
    for (const cp of site.checkpoints) {
      const opt = document.createElement('option');
      opt.value = cp.id;
      opt.textContent = `${cp.display_name} (${cp.checkpoint_code})`;
      cpInput.appendChild(opt);
    }
    if (cpInput.options.length > 0) {
      cpInput.value = cpInput.options[0].value;
    }
    activeCheckpointId = Number(cpInput.value || 0);
    activeCheckpointLabel = cpInput.options[cpInput.selectedIndex]?.text || '--';
    syncContextLabel();
    syncRecentEntriesLink();
    await refreshEntryContext();
  });
}

if (cpInput) {
  cpInput.addEventListener('change', async () => {
    activeCheckpointId = Number(cpInput.value || 0);
    activeCheckpointLabel = cpInput.options[cpInput.selectedIndex]?.text || '--';
    syncContextLabel();
    syncRecentEntriesLink();
    await refreshEntryContext();
  });
}
syncGreeting();
syncContextLabel();
syncRecentEntriesLink();
refreshEntryContext();

if (form) {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const payload = new FormData();
    const direction = selectedRadioValue('direction');
    const vehicleType = selectedRadioValue('vehicle_type');
    const vehicleColor = selectedVehicleColor();
    if (!direction || !vehicleType || !vehicleColor) {
      statusEl.textContent = 'Select In/Out, vehicle type, and color.';
      statusEl.className = 'status warn';
      return;
    }

    const siteId = getSiteId();
    const checkpointId = getCheckpointId();
    if (!siteId || !checkpointId) {
      statusEl.textContent = 'Invalid site/checkpoint selection.';
      statusEl.className = 'status warn';
      return;
    }

    payload.append('site_id', String(siteId));
    payload.append('checkpoint_id', String(checkpointId));
    payload.append('direction', direction);
    payload.append('plate', (document.getElementById('plate').value || '').toUpperCase());
    payload.append('vehicle_type', vehicleType);
    payload.append('vehicle_color', vehicleColor);
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
    setNotesExpanded(false);
    document.querySelectorAll('input[name="direction"], input[name="vehicle_type"], input[name="vehicle_color"]').forEach((input) => {
      input.checked = false;
    });
    if (plateInput) {
      plateInput.focus();
      plateInput.select();
    }
    await refreshEntryContext();
  });
}
</script>
<?php render_foot(); ?>
