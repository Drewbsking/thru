<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

render_head('Site Setup');
?>
<section class="card">
  <h1>N-CAT Site Setup</h1>
  <p class="small">Configure 2-3 checkpoints per site (or more), upload site image, define checkpoint distances, and control cut-through behavior. Recalculation uses latest settings immediately.</p>
</section>

<section class="grid two" style="margin-top:1rem;">
  <article class="card">
    <h2>Global Matching Settings</h2>
    <form id="settingsForm">
      <div class="form-row">
        <div><label>Speed (mph)</label><input id="speed_mph" type="number" min="1" max="120" step="1"></div>
        <div><label>Buffer (minutes)</label><input id="buffer_minutes" type="number" min="0.1" max="20" step="0.1"></div>
      </div>
      <div class="form-row">
        <div><label>Min Confidence (50-100)</label><input id="min_confidence" type="number" min="50" max="100" step="1"></div>
        <div><label>Dashboard Poll (sec)</label><input id="poll_seconds" type="number" min="5" max="60" step="1"></div>
      </div>
      <div class="form-row">
        <div><label>Policy Cut-Through %</label><input id="policy_cut_through_percent" type="number" min="1" max="100" step="1"></div>
      </div>
      <button type="submit">Save Settings</button>
      <p id="settingsStatus" class="status small"></p>
    </form>

    <hr style="margin:1rem 0; border:0; border-top:1px solid #d8dde7;">
    <h3>Access Password</h3>
    <form id="passwordForm">
      <div class="form-row">
        <div><label>New Password</label><input id="new_password" type="password" minlength="10" required></div>
        <div><label>Confirm New Password</label><input id="confirm_new_password" type="password" minlength="10" required></div>
      </div>
      <button type="submit" class="secondary">Update Password</button>
      <p id="passwordStatus" class="status small"></p>
    </form>
  </article>

  <article class="card">
    <h2>Site + Image</h2>
    <div class="form-row">
      <div>
        <label>Site</label>
        <select id="sitePicker"></select>
      </div>
      <div>
        <label>Set Active Site</label>
        <button type="button" id="activeBtn">Set Active</button>
      </div>
    </div>
    <form id="newSiteForm" class="form-row">
      <div>
        <label>New Site Name</label>
        <input id="site_name" placeholder="North Study Area">
      </div>
      <div>
        <label>&nbsp;</label>
        <button type="submit" class="secondary">Create Site</button>
      </div>
    </form>
    <form id="imageForm" enctype="multipart/form-data">
      <label>Site Image (PNG/JPG/WEBP)</label>
      <input id="site_image" type="file" accept="image/png,image/jpeg,image/webp">
      <div class="actions"><button type="submit">Upload Image</button></div>
    </form>
    <p id="siteStatus" class="status small"></p>
    <img id="sitePreview" class="site-preview" alt="Site image preview">
  </article>
</section>

<section class="grid two" style="margin-top:1rem;">
  <article class="card">
    <h2>Checkpoints</h2>
    <form id="checkpointForm">
      <input type="hidden" id="checkpoint_id" value="0">
      <div class="form-row">
        <div><label>Checkpoint Code</label><input id="checkpoint_code" placeholder="CP1"></div>
        <div><label>Display Name</label><input id="display_name" placeholder="Checkpoint 1"></div>
        <div><label>Data Collector</label><input id="collector_name" maxlength="80" placeholder="Collector assigned to this checkpoint"></div>
        <div><label>Type</label><select id="checkpoint_type"><option>Both</option><option>Entrance</option><option>Exit</option></select></div>
      </div>
      <button type="submit">Save Checkpoint</button>
      <p id="cpStatus" class="status small"></p>
    </form>
    <table>
      <thead><tr><th>Code</th><th>Name</th><th>Collector</th><th>Type</th><th>Action</th></tr></thead>
      <tbody id="cpBody"></tbody>
    </table>
  </article>

  <article class="card">
    <h2>Distances Between Checkpoints</h2>
    <p class="small">These distances + speed define expected travel time for cut-through matching.</p>
    <form id="distanceForm">
      <div class="form-row">
        <div><label>From</label><select id="from_cp"></select></div>
        <div><label>To</label><select id="to_cp"></select></div>
        <div><label>Distance (miles)</label><input id="distance_miles" type="number" min="0.01" step="0.01"></div>
      </div>
      <button type="submit">Save Distance</button>
      <p id="distanceStatus" class="status small"></p>
    </form>
    <table>
      <thead><tr><th>From</th><th>To</th><th>Miles</th><th>Expected @ Speed</th></tr></thead>
      <tbody id="distanceBody"></tbody>
    </table>
  </article>
</section>

<script>
let context = null;
let distances = [];
const setupSiteStorageKey = 'ncat_setup_selected_site_id';

async function post(action, extra = {}, file = null) {
  const fd = new FormData();
  fd.append('action', action);
  for (const [k, v] of Object.entries(extra)) fd.append(k, v);
  if (file) fd.append('site_image', file);
  const res = await fetch('api/save_setup.php', { method: 'POST', body: fd });
  return res.json();
}

function selectedSiteId() {
  return Number(document.getElementById('sitePicker').value || 0);
}

function getStoredSiteId() {
  return Number(localStorage.getItem(setupSiteStorageKey) || 0);
}

function storeSiteId(siteId) {
  localStorage.setItem(setupSiteStorageKey, String(siteId || 0));
}

function refreshSitePicker(preferredSiteId = 0) {
  const picker = document.getElementById('sitePicker');
  picker.innerHTML = '';
  for (const s of context.sites) {
    const o = document.createElement('option');
    o.value = s.id;
    o.textContent = `${s.name}${Number(s.id) === Number(context.active_site_id) ? ' (Active)' : ''}`;
    picker.appendChild(o);
  }

  const validIds = new Set(context.sites.map(s => Number(s.id)));
  const chosen = validIds.has(Number(preferredSiteId))
    ? Number(preferredSiteId)
    : (validIds.has(Number(context.active_site_id)) ? Number(context.active_site_id) : Number(context.sites[0]?.id || 0));

  picker.value = String(chosen || '');
  storeSiteId(chosen);
}

function renderCheckpoints() {
  const site = context.sites.find(s => Number(s.id) === selectedSiteId());
  const cps = site ? site.checkpoints : [];

  const cpBody = document.getElementById('cpBody');
  cpBody.innerHTML = '';
  cps.forEach(cp => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${cp.checkpoint_code}</td><td>${cp.display_name}</td><td>${cp.collector_name || ''}</td><td>${cp.checkpoint_type}</td>
      <td><button type="button" class="secondary" data-edit="${cp.id}">Edit</button> <button type="button" class="warn" data-del="${cp.id}">Delete</button></td>`;
    cpBody.appendChild(tr);
  });

  const from = document.getElementById('from_cp');
  const to = document.getElementById('to_cp');
  from.innerHTML = '';
  to.innerHTML = '';
  cps.forEach(cp => {
    const o1 = document.createElement('option');
    o1.value = cp.id; o1.textContent = cp.display_name;
    const o2 = o1.cloneNode(true);
    from.appendChild(o1); to.appendChild(o2);
  });
}

function renderSettings() {
  const s = context.settings;
  for (const k of ['speed_mph','buffer_minutes','min_confidence','poll_seconds','policy_cut_through_percent']) {
    document.getElementById(k).value = s[k];
  }
}

function renderSiteImage() {
  const site = context.sites.find(s => Number(s.id) === selectedSiteId());
  const img = document.getElementById('sitePreview');
  if (site && site.image_path) {
    img.src = site.image_path;
    img.style.display = 'block';
  } else {
    img.removeAttribute('src');
    img.style.display = 'none';
  }
}

function renderDistances() {
  const tbody = document.getElementById('distanceBody');
  tbody.innerHTML = '';
  const site = context.sites.find(s => Number(s.id) === selectedSiteId());
  if (!site) return;
  const cpMap = new Map(site.checkpoints.map(cp => [Number(cp.id), cp.display_name]));
  const speed = Number(context.settings.speed_mph || 25);

  distances.filter(d => Number(d.site_id) === Number(site.id)).forEach(d => {
    const expected = ((Number(d.distance_miles) / speed) * 60).toFixed(2);
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${cpMap.get(Number(d.from_checkpoint_id)) || d.from_checkpoint_id}</td>
      <td>${cpMap.get(Number(d.to_checkpoint_id)) || d.to_checkpoint_id}</td>
      <td>${d.distance_miles}</td>
      <td>${expected} min</td>`;
    tbody.appendChild(tr);
  });
}

async function loadContext() {
  const currentSelected = selectedSiteId();
  const storedSelected = getStoredSiteId();
  const preferredSiteId = currentSelected || storedSelected;

  const res = await fetch('api/site_context.php');
  context = await res.json();
  if (!context.ok) return;

  const dRes = await fetch('api/list_distances.php');
  const dJson = await dRes.json();
  distances = dJson.distances || [];

  refreshSitePicker(preferredSiteId);
  renderSettings();
  renderCheckpoints();
  renderSiteImage();
  renderDistances();
}

document.getElementById('sitePicker').addEventListener('change', () => {
  storeSiteId(selectedSiteId());
  renderCheckpoints();
  renderSiteImage();
  renderDistances();
});

document.getElementById('settingsForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const payload = {
    speed_mph: document.getElementById('speed_mph').value,
    buffer_minutes: document.getElementById('buffer_minutes').value,
    min_confidence: document.getElementById('min_confidence').value,
    poll_seconds: document.getElementById('poll_seconds').value,
    policy_cut_through_percent: document.getElementById('policy_cut_through_percent').value,
  };
  const out = await post('save_settings', payload);
  document.getElementById('settingsStatus').textContent = out.ok ? 'Settings saved. Dashboard recalculation uses these values immediately.' : out.error;
  document.getElementById('settingsStatus').className = out.ok ? 'status ok' : 'status warn';
  await loadContext();
});

document.getElementById('passwordForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const newPassword = document.getElementById('new_password').value;
  const confirmPassword = document.getElementById('confirm_new_password').value;
  if (newPassword !== confirmPassword) {
    document.getElementById('passwordStatus').textContent = 'Passwords do not match.';
    document.getElementById('passwordStatus').className = 'status warn';
    return;
  }

  const out = await post('save_auth_password', {
    new_password: newPassword,
    confirm_new_password: confirmPassword,
  });
  document.getElementById('passwordStatus').textContent = out.ok ? 'Password updated.' : out.error;
  document.getElementById('passwordStatus').className = out.ok ? 'status ok' : 'status warn';
  if (out.ok) {
    document.getElementById('new_password').value = '';
    document.getElementById('confirm_new_password').value = '';
  }
});

document.getElementById('newSiteForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const out = await post('create_site', { site_name: document.getElementById('site_name').value });
  document.getElementById('siteStatus').textContent = out.ok ? `Site created (#${out.site_id}).` : out.error;
  document.getElementById('siteStatus').className = out.ok ? 'status ok' : 'status warn';
  if (out.ok) document.getElementById('site_name').value = '';
  if (out.ok && out.site_id) storeSiteId(Number(out.site_id));
  await loadContext();
});

document.getElementById('activeBtn').addEventListener('click', async () => {
  const out = await post('set_active_site', { site_id: selectedSiteId() });
  document.getElementById('siteStatus').textContent = out.ok ? 'Active site updated.' : out.error;
  document.getElementById('siteStatus').className = out.ok ? 'status ok' : 'status warn';
  await loadContext();
});

document.getElementById('imageForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const file = document.getElementById('site_image').files[0];
  const out = await post('upload_site_image', { site_id: selectedSiteId() }, file);
  document.getElementById('siteStatus').textContent = out.ok ? 'Image uploaded.' : out.error;
  document.getElementById('siteStatus').className = out.ok ? 'status ok' : 'status warn';
  await loadContext();
});

document.getElementById('checkpointForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const out = await post('save_checkpoint', {
    site_id: selectedSiteId(),
    checkpoint_id: document.getElementById('checkpoint_id').value,
    checkpoint_code: document.getElementById('checkpoint_code').value,
    display_name: document.getElementById('display_name').value,
    collector_name: document.getElementById('collector_name').value,
    checkpoint_type: document.getElementById('checkpoint_type').value,
  });
  document.getElementById('cpStatus').textContent = out.ok ? 'Checkpoint saved.' : out.error;
  document.getElementById('cpStatus').className = out.ok ? 'status ok' : 'status warn';
  if (out.ok) {
    document.getElementById('checkpoint_id').value = '0';
    document.getElementById('checkpoint_code').value = '';
    document.getElementById('display_name').value = '';
    document.getElementById('collector_name').value = '';
    document.getElementById('checkpoint_type').value = 'Both';
  }
  await loadContext();
});

document.getElementById('cpBody').addEventListener('click', async (e) => {
  const target = e.target;
  if (!(target instanceof HTMLElement)) return;

  if (target.dataset.edit) {
    const cpId = Number(target.dataset.edit);
    const site = context.sites.find(s => Number(s.id) === selectedSiteId());
    const cp = site?.checkpoints.find(c => Number(c.id) === cpId);
    if (!cp) return;
    document.getElementById('checkpoint_id').value = cp.id;
    document.getElementById('checkpoint_code').value = cp.checkpoint_code;
    document.getElementById('display_name').value = cp.display_name;
    document.getElementById('collector_name').value = cp.collector_name || '';
    document.getElementById('checkpoint_type').value = cp.checkpoint_type;
  }

  if (target.dataset.del) {
    const cpId = Number(target.dataset.del);
    const out = await post('delete_checkpoint', { site_id: selectedSiteId(), checkpoint_id: cpId });
    document.getElementById('cpStatus').textContent = out.ok ? 'Checkpoint deleted.' : out.error;
    document.getElementById('cpStatus').className = out.ok ? 'status ok' : 'status warn';
    await loadContext();
  }
});

document.getElementById('distanceForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const out = await post('save_distance', {
    site_id: selectedSiteId(),
    from_checkpoint_id: document.getElementById('from_cp').value,
    to_checkpoint_id: document.getElementById('to_cp').value,
    distance_miles: document.getElementById('distance_miles').value,
  });
  document.getElementById('distanceStatus').textContent = out.ok ? 'Distance saved.' : out.error;
  document.getElementById('distanceStatus').className = out.ok ? 'status ok' : 'status warn';
  if (out.ok) document.getElementById('distance_miles').value = '';
  await loadContext();
});

loadContext();
</script>
<?php render_foot(); ?>
