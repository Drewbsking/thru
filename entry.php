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
$collectorName = app_setting('data_collector_name', '');

render_head('Data Entry');
?>
<section class="card">
  <h1>N-CAT Data Entry</h1>
  <p class="small">Checkpoint can be locked by link. This prevents wrong checkpoint tagging when different observers are logging traffic. Studies are typically short roadside sessions (around 2 hours).</p>

  <?php if (!$site): ?>
    <p class="status warn">No active site found. Configure a site first in <a href="setup.php">Site Setup</a>.</p>
  <?php else: ?>
    <div class="card" style="margin-bottom:0.8rem;">
      <h2 style="margin-bottom:0.45rem;">Site Map Reminder</h2>
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
          <label>Plate (full or partial)</label>
          <input id="plate" maxlength="32" placeholder="ABC123 or partial">
        </div>
        <div>
          <label>Data Collector</label>
          <input id="collector_name_display" value="<?= h($collectorName !== '' ? $collectorName : 'Not set in Site Setup') ?>" readonly>
        </div>
        <div>
          <label>Event Time</label>
          <input id="event_time" type="datetime-local">
        </div>
      </div>

      <div class="choice-block">
        <div class="choice-title">Direction</div>
        <div class="radio-grid two-col">
          <label class="radio-chip">
            <input type="radio" name="direction" value="In" checked>
            <span>In</span>
          </label>
          <label class="radio-chip">
            <input type="radio" name="direction" value="Out">
            <span>Out</span>
          </label>
        </div>
      </div>

      <div class="choice-block">
        <div class="choice-title">Vehicle Type</div>
        <div class="radio-grid">
          <label class="radio-chip"><input type="radio" name="vehicle_type" value="Sedan" checked><span>Sedan</span></label>
          <label class="radio-chip"><input type="radio" name="vehicle_type" value="SUV"><span>SUV</span></label>
          <label class="radio-chip"><input type="radio" name="vehicle_type" value="Truck"><span>Truck</span></label>
          <label class="radio-chip"><input type="radio" name="vehicle_type" value="Minivan"><span>Minivan</span></label>
          <label class="radio-chip"><input type="radio" name="vehicle_type" value="Trailer/Motorcycle"><span>Trailer/Motorcycle</span></label>
        </div>
      </div>

      <div class="choice-block">
        <div class="choice-title">Vehicle Color</div>
        <div class="radio-grid">
          <label class="radio-chip"><input type="radio" name="vehicle_color" value="White" checked><span>White</span></label>
          <label class="radio-chip"><input type="radio" name="vehicle_color" value="Black/Blue"><span>Black/Blue</span></label>
          <label class="radio-chip"><input type="radio" name="vehicle_color" value="Gray/Silver"><span>Gray/Silver</span></label>
          <label class="radio-chip"><input type="radio" name="vehicle_color" value="Red"><span>Red</span></label>
          <label class="radio-chip"><input type="radio" name="vehicle_color" value="Green"><span>Green</span></label>
          <label class="radio-chip"><input type="radio" name="vehicle_color" value="Other"><span>Other</span></label>
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
          <label>Notes (optional)</label>
          <textarea id="notes" maxlength="255"></textarea>
        </div>
      </div>
      <div class="actions">
        <button type="submit">Save Event</button>
        <a class="btn secondary" href="dashboard.php">View Dashboard</a>
      </div>
      <p id="saveStatus" class="status small" style="margin-top:0.7rem;"></p>
    </form>
  <?php endif; ?>
</section>

<script>
const lockedCheckpoint = <?= $checkpointId > 0 ? 'true' : 'false' ?>;
const initialSiteId = <?= (int)$siteId ?>;
const initialCheckpointId = <?= (int)$checkpointId ?>;
let collectorName = <?= json_encode($collectorName, JSON_UNESCAPED_SLASHES) ?>;

const siteInput = document.getElementById('site_id');
const cpInput = document.getElementById('checkpoint_id');
const form = document.getElementById('eventForm');
const statusEl = document.getElementById('saveStatus');
const sitePreview = document.getElementById('entrySitePreview');
const noImageMsg = document.getElementById('entrySiteNoImage');
const otherColorWrap = document.getElementById('otherColorWrap');
const otherColorInput = document.getElementById('other_color');
const collectorDisplay = document.getElementById('collector_name_display');

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

document.querySelectorAll('input[name="vehicle_color"]').forEach((input) => {
  input.addEventListener('change', toggleOtherColor);
});
toggleOtherColor();

if (siteInput && cpInput && !lockedCheckpoint) {
  siteInput.addEventListener('change', async () => {
    const res = await fetch('api/site_context.php');
    const data = await res.json();
    if (!data.ok) return;
    const selectedSite = Number(siteInput.value);
    const site = data.sites.find(s => Number(s.id) === selectedSite);
    cpInput.innerHTML = '';
    if (!site) return;
    for (const cp of site.checkpoints) {
      const opt = document.createElement('option');
      opt.value = cp.id;
      opt.textContent = `${cp.display_name} (${cp.checkpoint_code})`;
      cpInput.appendChild(opt);
    }

    collectorName = (data.settings && data.settings.data_collector_name) ? data.settings.data_collector_name : '';
    if (collectorDisplay) {
      collectorDisplay.value = collectorName || 'Not set in Site Setup';
    }

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
    const dtLocal = document.getElementById('event_time').value;
    if (dtLocal) {
      payload.append('event_time', dtLocal.replace('T', ' ') + ':00');
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
    document.getElementById('plate').value = '';
    document.getElementById('notes').value = '';
    document.getElementById('event_time').value = '';
    if (otherColorInput) {
      otherColorInput.value = '';
    }
    toggleOtherColor();
  });
}
</script>
<?php render_foot(); ?>
