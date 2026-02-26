<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

require_admin_page();

render_head('Admin');
$adminCsrfToken = csrf_token();
?>
<section class="card">
  <h2>Admin</h2>
  <p class="small">Manage collector logins, admin password, and dashboard viewer access code.</p>
</section>

<section class="grid two" style="margin-top:1rem;">
  <article class="card">
    <h3>Create Collector Login</h3>
    <form id="collectorForm">
      <div class="form-row">
        <div><label>Username</label><input id="collector_username" placeholder="jane.doe" required></div>
        <div><label>Password</label><input id="collector_password" type="password" minlength="10" required></div>
      </div>
      <div class="form-row">
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

  <article class="card">
    <h3>Dashboard Viewer Access Code</h3>
    <p class="small" id="viewerCodeEnabled">Viewer access is currently disabled.</p>
    <form id="viewerCodeForm">
      <div class="form-row">
        <div><label>New Access Code</label><input id="viewer_access_code" type="password" minlength="8" required></div>
        <div><label>Confirm Access Code</label><input id="viewer_access_code_confirm" type="password" minlength="8" required></div>
      </div>
      <button type="submit" class="secondary">Update Viewer Code</button>
    </form>
    <p id="viewerCodeStatus" class="status small"></p>
  </article>
</section>

<section class="card" style="margin-top:1rem;">
  <h3>Admin Password</h3>
  <form id="passwordForm">
    <div class="form-row">
      <div><label>New Password (Current Admin)</label><input id="new_password" type="password" minlength="10" required></div>
      <div><label>Confirm New Password</label><input id="confirm_new_password" type="password" minlength="10" required></div>
    </div>
    <button type="submit" class="secondary">Update Admin Password</button>
  </form>
  <p id="passwordStatus" class="status small"></p>
</section>

<script>
let context = null;
const adminCsrfToken = <?= json_encode($adminCsrfToken, JSON_UNESCAPED_SLASHES) ?>;

async function post(action, extra = {}) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf_token', adminCsrfToken);
  for (const [k, v] of Object.entries(extra)) fd.append(k, v);
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

function renderCollectors() {
  const collectorBody = document.getElementById('collectorBody');
  const collectors = (context?.users || []).filter((u) => u.role === 'collector' && Number(u.is_active) === 1);
  collectorBody.innerHTML = '';
  if (collectors.length === 0) {
    collectorBody.innerHTML = '<tr><td colspan="2">No collector users yet.</td></tr>';
    return;
  }
  collectors.forEach((u) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${escapeHtml(u.username)}</td><td>${escapeHtml(u.role)}</td>`;
    collectorBody.appendChild(tr);
  });
}

function renderViewerStatus() {
  const viewerStatus = document.getElementById('viewerCodeEnabled');
  if (!viewerStatus) return;
  const enabled = Boolean(context?.settings?.dashboard_view_enabled);
  viewerStatus.textContent = enabled
    ? 'Viewer access is enabled. Share the access code (no username needed).'
    : 'Viewer access is currently disabled.';
}

async function loadContext() {
  const res = await fetch('api/site_context.php');
  context = await res.json().catch(() => ({ ok: false, error: 'Unable to load admin context.' }));
  if (!context.ok) {
    const msg = context.error || 'Unable to load admin context.';
    document.getElementById('collectorStatus').textContent = msg;
    document.getElementById('collectorStatus').className = 'status small warn';
    return;
  }
  renderCollectors();
  renderViewerStatus();
}

document.getElementById('collectorForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const out = await post('create_collector', {
    username: document.getElementById('collector_username').value,
    password: document.getElementById('collector_password').value,
    confirm_password: document.getElementById('collector_password_confirm').value,
  });
  document.getElementById('collectorStatus').textContent = out.ok ? 'Collector created.' : out.error;
  document.getElementById('collectorStatus').className = out.ok ? 'status small ok' : 'status small warn';
  if (out.ok) {
    document.getElementById('collector_username').value = '';
    document.getElementById('collector_password').value = '';
    document.getElementById('collector_password_confirm').value = '';
    await loadContext();
  }
});

document.getElementById('passwordForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const newPassword = document.getElementById('new_password').value;
  const confirmPassword = document.getElementById('confirm_new_password').value;
  if (newPassword !== confirmPassword) {
    document.getElementById('passwordStatus').textContent = 'Passwords do not match.';
    document.getElementById('passwordStatus').className = 'status small warn';
    return;
  }

  const out = await post('save_auth_password', {
    new_password: newPassword,
    confirm_new_password: confirmPassword,
  });
  document.getElementById('passwordStatus').textContent = out.ok ? 'Password updated.' : out.error;
  document.getElementById('passwordStatus').className = out.ok ? 'status small ok' : 'status small warn';
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
    document.getElementById('viewerCodeStatus').className = 'status small warn';
    return;
  }

  const out = await post('save_viewer_access_code', {
    viewer_access_code: accessCode,
    viewer_access_code_confirm: confirmCode,
  });
  document.getElementById('viewerCodeStatus').textContent = out.ok ? 'Viewer access code updated.' : out.error;
  document.getElementById('viewerCodeStatus').className = out.ok ? 'status small ok' : 'status small warn';
  if (out.ok) {
    document.getElementById('viewer_access_code').value = '';
    document.getElementById('viewer_access_code_confirm').value = '';
    await loadContext();
  }
});

loadContext();
</script>
<?php render_foot(); ?>
