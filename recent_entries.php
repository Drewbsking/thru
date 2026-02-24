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

render_head('Recent Entries');
?>
<section class="card">
  <p class="small">Review and edit recent entries on a separate page so mobile data entry stays clean.</p>
  <p class="small" id="recentContextLabel">Site: <?= h((string)($site['name'] ?? '--')) ?> | Checkpoint: <?= h($selectedCheckpointLabel !== '' ? $selectedCheckpointLabel : '--') ?></p>
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
    <div class="form-row">
      <div>
        <label>Rows</label>
        <select id="recent_limit">
          <option value="6">Last 6</option>
          <option value="10" selected>Last 10</option>
          <option value="20">Last 20</option>
          <option value="50">Last 50</option>
        </select>
      </div>
      <div>
        <label>&nbsp;</label>
        <button type="button" id="recentRefreshBtn">Refresh</button>
      </div>
      <div>
        <label>&nbsp;</label>
        <a id="backToEntryLink" class="btn secondary" href="entry.php?site_id=<?= (int)$siteId ?>&checkpoint_id=<?= (int)$checkpointId ?>">Back To Entry</a>
      </div>
    </div>
    <p id="recentStatus" class="status small"></p>
  <?php endif; ?>
</section>

<?php if ($site): ?>
<section class="card" style="margin-top:1rem;">
  <table>
    <thead><tr><th>Event #</th><th>Time</th><th>Dir</th><th>Plate</th><th>Type</th><th>Color</th><th>Comments</th><th>Action</th></tr></thead>
    <tbody id="recentEntryBody"></tbody>
  </table>
</section>

<script>
const initialSiteId = <?= (int)$siteId ?>;
const initialCheckpointId = <?= (int)$checkpointId ?>;
const initialSiteName = <?= json_encode((string)($site['name'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
const initialCheckpointLabel = <?= json_encode($selectedCheckpointLabel, JSON_UNESCAPED_SLASHES) ?>;

let activeSiteId = Number(initialSiteId || 0);
let activeCheckpointId = Number(initialCheckpointId || 0);
let activeSiteName = initialSiteName;
let activeCheckpointLabel = initialCheckpointLabel;

const siteInput = document.getElementById('site_id');
const cpInput = document.getElementById('checkpoint_id');
const limitInput = document.getElementById('recent_limit');
const refreshBtn = document.getElementById('recentRefreshBtn');
const statusEl = document.getElementById('recentStatus');
const contextLabelEl = document.getElementById('recentContextLabel');
const recentEntryBody = document.getElementById('recentEntryBody');
const backToEntryLink = document.getElementById('backToEntryLink');

const vehicleTypeOptions = ['Sedan', 'SUV', 'Pickup Truck', 'Truck', 'Minivan', 'Motorcycle', 'Other', 'Trailer', 'Trailer/Motorcycle'];
const vehicleColorOptions = ['White', 'Black/Blue', 'Gray/Silver', 'Red', 'Green', 'Other'];

function getSiteId() {
  if (siteInput) {
    activeSiteId = Number(siteInput.value || 0);
    activeSiteName = siteInput.options[siteInput.selectedIndex]?.text || activeSiteName;
  }
  return Number(activeSiteId || 0);
}

function getCheckpointId() {
  if (cpInput) {
    activeCheckpointId = Number(cpInput.value || 0);
    activeCheckpointLabel = cpInput.options[cpInput.selectedIndex]?.text || activeCheckpointLabel;
  }
  return Number(activeCheckpointId || 0);
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

function syncContextLabel() {
  if (!contextLabelEl) return;
  contextLabelEl.textContent = `Site: ${activeSiteName || '--'} | Checkpoint: ${activeCheckpointLabel || '--'}`;
}

function syncBackToEntryLink() {
  if (!backToEntryLink) return;
  const params = new URLSearchParams();
  const siteId = getSiteId();
  const checkpointId = getCheckpointId();
  if (siteId > 0) params.set('site_id', String(siteId));
  if (checkpointId > 0) params.set('checkpoint_id', String(checkpointId));
  backToEntryLink.href = `entry.php?${params.toString()}`;
}

async function loadRecentEntries() {
  const siteId = getSiteId();
  const checkpointId = getCheckpointId();
  const limit = Number(limitInput?.value || 10);
  if (!recentEntryBody) return;
  if (!siteId || !checkpointId) {
    recentEntryBody.innerHTML = '<tr><td colspan=\"8\">Select a valid checkpoint to view entries.</td></tr>';
    return;
  }

  const res = await fetch(`api/recent_checkpoint_events.php?site_id=${siteId}&checkpoint_id=${checkpointId}&limit=${limit}`);
  const data = await res.json();
  if (!data.ok) {
    recentEntryBody.innerHTML = `<tr><td colspan=\"8\">${escapeHtml(data.error || 'Unable to load recent entries.')}</td></tr>`;
    return;
  }

  recentEntryBody.innerHTML = '';
  for (const e of (data.events || [])) {
    const selectedType = String(e.vehicle_type || '');
    const selectedColor = String(e.vehicle_color || '');
    const typeOptions = [`<option value=""${selectedType === '' ? ' selected' : ''}>(None)</option>`]
      .concat(vehicleTypeOptions.map((opt) => `<option value="${escapeHtml(opt)}"${opt === selectedType ? ' selected' : ''}>${escapeHtml(opt)}</option>`))
      .join('');
    const colorOptions = [`<option value=""${selectedColor === '' ? ' selected' : ''}>(None)</option>`]
      .concat(vehicleColorOptions.map((opt) => `<option value="${escapeHtml(opt)}"${opt === selectedColor ? ' selected' : ''}>${escapeHtml(opt)}</option>`))
      .join('');
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
    tr.dataset.vehicleType = String(e.vehicle_type || '');
    tr.dataset.vehicleColor = String(e.vehicle_color || '');
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
  tr.querySelector('[data-field="plate_raw"]').innerHTML = `<input data-input="plate_raw" maxlength="3" value="${escapeHtml(tr.dataset.plateRaw || '')}" style="text-transform:uppercase;" autocapitalize="characters" spellcheck="false">`;
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

if (siteInput && cpInput) {
  siteInput.addEventListener('change', async () => {
    const res = await fetch('api/site_context.php');
    const data = await res.json();
    if (!data.ok) return;
    const selectedSite = Number(siteInput.value || 0);
    const site = (data.sites || []).find((s) => Number(s.id) === selectedSite);
    cpInput.innerHTML = '';
    if (!site) return;
    activeSiteId = selectedSite;
    activeSiteName = site.name || '';
    for (const cp of (site.checkpoints || [])) {
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
    syncBackToEntryLink();
    await loadRecentEntries();
  });
}

if (cpInput) {
  cpInput.addEventListener('change', async () => {
    activeCheckpointId = Number(cpInput.value || 0);
    activeCheckpointLabel = cpInput.options[cpInput.selectedIndex]?.text || '--';
    syncContextLabel();
    syncBackToEntryLink();
    await loadRecentEntries();
  });
}

if (limitInput) {
  limitInput.addEventListener('change', async () => {
    await loadRecentEntries();
  });
}

if (refreshBtn) {
  refreshBtn.addEventListener('click', async () => {
    await loadRecentEntries();
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
      await loadRecentEntries();
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
    }
  });
}

syncContextLabel();
syncBackToEntryLink();
loadRecentEntries();
</script>
<?php endif; ?>
<?php render_foot(); ?>
