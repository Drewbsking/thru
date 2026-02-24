<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

require_admin_page();

render_head('Site Setup');
$setupCsrfToken = csrf_token();
?>
<section class="card">
  <p class="small">Configure 2-3 checkpoints per site (or more), upload site image, define checkpoint distances, and control cut-through behavior. Recalculation uses latest settings immediately.</p>
</section>

<section class="card" style="margin-top:1rem;">
  <h2>Collectors + Checkpoint Access</h2>
  <div class="grid two">
    <article>
      <h3>Create Collector Login</h3>
      <form id="collectorForm">
        <div class="form-row">
          <div><label>Username</label><input id="collector_username" placeholder="jane.doe" required></div>
          <div><label>Password</label><input id="collector_password" type="password" minlength="10" required></div>
          <div><label>Confirm Password</label><input id="collector_password_confirm" type="password" minlength="10" required></div>
        </div>
        <button type="submit">Create Collector</button>
      </form>
      <p id="collectorStatus" class="status small"></p>
      <table>
        <thead><tr><th>Collector Username</th><th>Role</th></tr></thead>
        <tbody id="collectorBody"></tbody>
      </table>
    </article>
    <article>
      <h3>Assign Collector to Checkpoint</h3>
      <form id="assignmentForm">
        <div class="form-row">
          <div><label>Collector</label><select id="assign_user_id"></select></div>
          <div><label>Site</label><select id="assign_site_id"></select></div>
          <div><label>Checkpoint</label><select id="assign_checkpoint_id"></select></div>
        </div>
        <button type="submit">Save Assignment</button>
      </form>
      <p id="assignmentStatus" class="status small"></p>
      <table>
        <thead><tr><th>Collector</th><th>Site</th><th>Checkpoint</th><th>Action</th></tr></thead>
        <tbody id="assignmentBody"></tbody>
      </table>
    </article>
  </div>
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
    <h3>Admin Password</h3>
    <form id="passwordForm">
      <div class="form-row">
        <div><label>New Password (Current Admin)</label><input id="new_password" type="password" minlength="10" required></div>
        <div><label>Confirm New Password</label><input id="confirm_new_password" type="password" minlength="10" required></div>
      </div>
      <button type="submit" class="secondary">Update Admin Password</button>
      <p id="passwordStatus" class="status small"></p>
    </form>

    <hr style="margin:1rem 0; border:0; border-top:1px solid #d8dde7;">
    <h3>Dashboard Viewer Access Code</h3>
    <p class="small" id="viewerCodeEnabled">Viewer access is currently disabled.</p>
    <form id="viewerCodeForm">
      <div class="form-row">
        <div><label>New Access Code</label><input id="viewer_access_code" type="password" minlength="8" required></div>
        <div><label>Confirm Access Code</label><input id="viewer_access_code_confirm" type="password" minlength="8" required></div>
      </div>
      <button type="submit" class="secondary">Update Viewer Code</button>
      <p id="viewerCodeStatus" class="status small"></p>
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
    <p class="small">Checkpoint numbers are auto-assigned (1, 2, 3...). Display Name is for user convenience.</p>
    <form id="checkpointForm">
      <input type="hidden" id="checkpoint_id" value="0">
      <div class="form-row">
        <div><label>Display Name</label><input id="display_name" placeholder="Main Entrance"></div>
        <div><label>Type</label><select id="checkpoint_type"><option>Both</option><option>Entrance</option><option>Exit</option></select></div>
      </div>
      <button type="submit">Save Checkpoint</button>
      <p id="cpStatus" class="status small"></p>
    </form>
    <table>
      <thead><tr><th>#</th><th>Name</th><th>Type</th><th>Action</th></tr></thead>
      <tbody id="cpBody"></tbody>
    </table>
  </article>

  <article class="card">
    <h2>Distances Between Checkpoints</h2>
    <p class="small">Checkpoint combinations are auto-generated. Enter miles and save each pair.</p>
    <p id="distanceStatus" class="status small"></p>
    <table>
      <thead><tr><th>From</th><th>To</th><th>Miles</th><th>Expected @ Speed</th><th>Action</th></tr></thead>
      <tbody id="distanceBody"></tbody>
    </table>
  </article>
</section>

<script>
let context = null;
let distances = [];
const setupSiteStorageKey = 'ncat_setup_selected_site_id';
const setupCsrfToken = <?= json_encode($setupCsrfToken, JSON_UNESCAPED_SLASHES) ?>;

async function post(action, extra = {}, file = null) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf_token', setupCsrfToken);
  for (const [k, v] of Object.entries(extra)) fd.append(k, v);
  if (file) fd.append('site_image', file);
  const res = await fetch('api/save_setup.php', { method: 'POST', body: fd });
  return res.json();
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
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
    tr.innerHTML = `<td>${escapeHtml(cp.checkpoint_code)}</td><td>${escapeHtml(cp.display_name)}</td><td>${escapeHtml(cp.checkpoint_type)}</td>
      <td><button type="button" class="secondary" data-edit="${cp.id}">Edit</button> <button type="button" class="warn" data-del="${cp.id}">Delete</button></td>`;
    cpBody.appendChild(tr);
  });
}

function renderSettings() {
  const s = context.settings;
  for (const k of ['speed_mph','buffer_minutes','min_confidence','poll_seconds','policy_cut_through_percent']) {
    document.getElementById(k).value = s[k];
  }
  const viewerStatus = document.getElementById('viewerCodeEnabled');
  if (viewerStatus) {
    viewerStatus.textContent = s.dashboard_view_enabled
      ? 'Viewer access is enabled. Share the access code (no username needed).'
      : 'Viewer access is currently disabled.';
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
  const cps = [...(site.checkpoints || [])].sort((a, b) => {
    const aNum = Number(a.checkpoint_code);
    const bNum = Number(b.checkpoint_code);
    if (Number.isFinite(aNum) && Number.isFinite(bNum) && aNum !== bNum) return aNum - bNum;
    return String(a.display_name || '').localeCompare(String(b.display_name || ''));
  });
  if (cps.length < 2) {
    tbody.innerHTML = '<tr><td colspan="5">Add at least 2 checkpoints to define distances.</td></tr>';
    return;
  }

  const speed = Number(context.settings.speed_mph || 25);
  const pairMiles = new Map();

  function pairKey(a, b) {
    const first = Math.min(Number(a), Number(b));
    const second = Math.max(Number(a), Number(b));
    return `${first}:${second}`;
  }

  distances
    .filter(d => Number(d.site_id) === Number(site.id))
    .forEach(d => {
      const key = pairKey(d.from_checkpoint_id, d.to_checkpoint_id);
      if (!pairMiles.has(key)) {
        pairMiles.set(key, Number(d.distance_miles));
      }
    });

  for (let i = 0; i < cps.length; i += 1) {
    for (let j = i + 1; j < cps.length; j += 1) {
      const fromCp = cps[i];
      const toCp = cps[j];
      const key = pairKey(fromCp.id, toCp.id);
      const miles = pairMiles.get(key);
      const milesText = Number.isFinite(miles) ? Number(miles).toFixed(3) : '';
      const expected = Number.isFinite(miles) && miles > 0 ? ((miles / speed) * 60).toFixed(2) : '--';

      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${escapeHtml(fromCp.display_name)} (${escapeHtml(fromCp.checkpoint_code)})</td>
        <td>${escapeHtml(toCp.display_name)} (${escapeHtml(toCp.checkpoint_code)})</td>
        <td><input type="number" min="0.01" step="0.01" data-distance-input value="${escapeHtml(milesText)}"></td>
        <td data-expected>${expected === '--' ? '--' : `${escapeHtml(expected)} min`}</td>
        <td><button type="button" data-save-distance data-from-id="${fromCp.id}" data-to-id="${toCp.id}">Save</button></td>`;
      tbody.appendChild(tr);
    }
  }
}

function expectedMinutesText(distanceMiles) {
  const speed = Number(context?.settings?.speed_mph || 25);
  const miles = Number(distanceMiles);
  if (!(miles > 0) || !(speed > 0)) return '--';
  return `${((miles / speed) * 60).toFixed(2)} min`;
}

document.getElementById('distanceBody').addEventListener('input', (e) => {
  const target = e.target;
  if (!(target instanceof HTMLInputElement) || target.dataset.distanceInput === undefined) return;
  const tr = target.closest('tr');
  if (!tr) return;
  const expectedCell = tr.querySelector('[data-expected]');
  if (!(expectedCell instanceof HTMLElement)) return;
  expectedCell.textContent = expectedMinutesText(target.value);
});

document.getElementById('distanceBody').addEventListener('click', async (e) => {
  const target = e.target;
  if (!(target instanceof HTMLElement) || target.dataset.saveDistance === undefined) return;
  const tr = target.closest('tr');
  if (!tr) return;
  const input = tr.querySelector('input[data-distance-input]');
  if (!(input instanceof HTMLInputElement)) return;
  const miles = Number(input.value || 0);
  if (!(miles > 0)) {
    document.getElementById('distanceStatus').textContent = 'Distance must be greater than 0.';
    document.getElementById('distanceStatus').className = 'status warn';
    return;
  }
  const fromId = Number(target.dataset.fromId || 0);
  const toId = Number(target.dataset.toId || 0);
  if (!(fromId > 0) || !(toId > 0) || fromId === toId) {
    document.getElementById('distanceStatus').textContent = 'Invalid checkpoint pair.';
    document.getElementById('distanceStatus').className = 'status warn';
    return;
  }
  const out = await post('save_distance', {
    site_id: selectedSiteId(),
    from_checkpoint_id: String(fromId),
    to_checkpoint_id: String(toId),
    distance_miles: miles.toFixed(3),
  });
  document.getElementById('distanceStatus').textContent = out.ok ? 'Distance saved.' : out.error;
  document.getElementById('distanceStatus').className = out.ok ? 'status ok' : 'status warn';
  if (out.ok) {
    await loadContext();
  }
});

function renderCollectorsAndAssignments() {
  const collectors = (context.users || []).filter(u => u.role === 'collector' && Number(u.is_active) === 1);
  const collectorBody = document.getElementById('collectorBody');
  collectorBody.innerHTML = '';
  collectors.forEach(u => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${escapeHtml(u.username)}</td><td>${escapeHtml(u.role)}</td>`;
    collectorBody.appendChild(tr);
  });

  const assignUser = document.getElementById('assign_user_id');
  assignUser.innerHTML = '';
  collectors.forEach(u => {
    const opt = document.createElement('option');
    opt.value = u.id;
    opt.textContent = u.username;
    assignUser.appendChild(opt);
  });

  const assignSite = document.getElementById('assign_site_id');
  const currentSiteValue = assignSite.value;
  assignSite.innerHTML = '';
  (context.sites || []).forEach(s => {
    const opt = document.createElement('option');
    opt.value = s.id;
    opt.textContent = s.name;
    assignSite.appendChild(opt);
  });
  if (currentSiteValue) {
    assignSite.value = currentSiteValue;
  }
  renderAssignmentCheckpointOptions();

  const assignmentBody = document.getElementById('assignmentBody');
  assignmentBody.innerHTML = '';
  (context.assignments || []).forEach(a => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${escapeHtml(a.username)}</td><td>${escapeHtml(a.site_name)}</td><td>${escapeHtml(a.checkpoint_name)} (${escapeHtml(a.checkpoint_code)})</td><td><button type="button" class="warn" data-del-assignment="${a.id}">Remove</button></td>`;
    assignmentBody.appendChild(tr);
  });
}

function renderAssignmentCheckpointOptions() {
  const siteId = Number(document.getElementById('assign_site_id').value || 0);
  const site = (context.sites || []).find(s => Number(s.id) === siteId);
  const assignCheckpoint = document.getElementById('assign_checkpoint_id');
  assignCheckpoint.innerHTML = '';
  (site?.checkpoints || []).forEach(cp => {
    const opt = document.createElement('option');
    opt.value = cp.id;
    opt.textContent = `${cp.display_name} (${cp.checkpoint_code})`;
    assignCheckpoint.appendChild(opt);
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
  renderCollectorsAndAssignments();
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

document.getElementById('viewerCodeForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const accessCode = document.getElementById('viewer_access_code').value;
  const confirmCode = document.getElementById('viewer_access_code_confirm').value;
  if (accessCode !== confirmCode) {
    document.getElementById('viewerCodeStatus').textContent = 'Access code values do not match.';
    document.getElementById('viewerCodeStatus').className = 'status warn';
    return;
  }

  const out = await post('save_viewer_access_code', {
    viewer_access_code: accessCode,
    viewer_access_code_confirm: confirmCode,
  });
  document.getElementById('viewerCodeStatus').textContent = out.ok ? 'Viewer access code updated.' : out.error;
  document.getElementById('viewerCodeStatus').className = out.ok ? 'status ok' : 'status warn';
  if (out.ok) {
    document.getElementById('viewer_access_code').value = '';
    document.getElementById('viewer_access_code_confirm').value = '';
  }
  await loadContext();
});

document.getElementById('collectorForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const out = await post('create_collector', {
    username: document.getElementById('collector_username').value,
    password: document.getElementById('collector_password').value,
    confirm_password: document.getElementById('collector_password_confirm').value,
  });
  document.getElementById('collectorStatus').textContent = out.ok ? 'Collector created.' : out.error;
  document.getElementById('collectorStatus').className = out.ok ? 'status ok' : 'status warn';
  if (out.ok) {
    document.getElementById('collector_username').value = '';
    document.getElementById('collector_password').value = '';
    document.getElementById('collector_password_confirm').value = '';
  }
  await loadContext();
});

document.getElementById('assign_site_id').addEventListener('change', renderAssignmentCheckpointOptions);

document.getElementById('assignmentForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const out = await post('assign_collector_checkpoint', {
    user_id: document.getElementById('assign_user_id').value,
    site_id: document.getElementById('assign_site_id').value,
    checkpoint_id: document.getElementById('assign_checkpoint_id').value,
  });
  document.getElementById('assignmentStatus').textContent = out.ok ? 'Assignment saved.' : out.error;
  document.getElementById('assignmentStatus').className = out.ok ? 'status ok' : 'status warn';
  await loadContext();
});

document.getElementById('assignmentBody').addEventListener('click', async (e) => {
  const target = e.target;
  if (!(target instanceof HTMLElement) || !target.dataset.delAssignment) return;
  const assignmentId = Number(target.dataset.delAssignment || 0);
  if (!assignmentId) return;
  const out = await post('remove_assignment', { assignment_id: assignmentId });
  document.getElementById('assignmentStatus').textContent = out.ok ? 'Assignment removed.' : out.error;
  document.getElementById('assignmentStatus').className = out.ok ? 'status ok' : 'status warn';
  await loadContext();
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
    display_name: document.getElementById('display_name').value,
    checkpoint_type: document.getElementById('checkpoint_type').value,
  });
  document.getElementById('cpStatus').textContent = out.ok ? 'Checkpoint saved.' : out.error;
  document.getElementById('cpStatus').className = out.ok ? 'status ok' : 'status warn';
  if (out.ok) {
    document.getElementById('checkpoint_id').value = '0';
    document.getElementById('display_name').value = '';
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
    document.getElementById('display_name').value = cp.display_name;
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

loadContext();
</script>
<?php render_foot(); ?>
