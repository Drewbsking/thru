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

render_head('Data Entry');
?>
<section class="card">
  <h1>Vehicle Data Entry</h1>
  <p class="small">Checkpoint can be locked by link. This prevents wrong checkpoint tagging when different observers are logging traffic.</p>

  <?php if (!$site): ?>
    <p class="status warn">No active site found. Configure a site first in <a href="setup.php">Site Setup</a>.</p>
  <?php else: ?>
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
      <div>
        <label>Direction</label>
        <select id="direction">
          <option value="In">In</option>
          <option value="Out">Out</option>
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
          <label>Vehicle Type</label>
          <input id="vehicle_type" maxlength="50" required placeholder="SUV, Sedan, Truck...">
        </div>
        <div>
          <label>Vehicle Color</label>
          <input id="vehicle_color" maxlength="50" required placeholder="White, Black, Blue...">
        </div>
      </div>
      <div class="form-row">
        <div>
          <label>Observer Name (optional)</label>
          <input id="observer_name" maxlength="80" placeholder="Initials or name">
        </div>
        <div>
          <label>Event Time</label>
          <input id="event_time" type="datetime-local">
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

const siteInput = document.getElementById('site_id');
const cpInput = document.getElementById('checkpoint_id');
const form = document.getElementById('eventForm');
const statusEl = document.getElementById('saveStatus');

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
  });
}

if (form) {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const payload = new FormData();
    payload.append('site_id', lockedCheckpoint ? String(initialSiteId) : siteInput.value);
    payload.append('checkpoint_id', lockedCheckpoint ? String(initialCheckpointId) : cpInput.value);
    payload.append('direction', document.getElementById('direction').value);
    payload.append('plate', document.getElementById('plate').value);
    payload.append('vehicle_type', document.getElementById('vehicle_type').value);
    payload.append('vehicle_color', document.getElementById('vehicle_color').value);
    payload.append('observer_name', document.getElementById('observer_name').value);
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
    form.reset();
  });
}
</script>
<?php render_foot(); ?>
