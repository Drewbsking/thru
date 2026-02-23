<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/utils.php';

ensure_schema();
auth_session_start();
$appName = 'N-CAT: Neighborhood Cut-through Analysis Tool';
$pageTitle = $appName . ' - Login';

if (is_dashboard_viewer()) {
    header('Location: dashboard.php', true, 302);
    exit;
}

if (is_authenticated()) {
    header('Location: index.php', true, 302);
    exit;
}

$accountError = '';
$viewerError = '';
$next = (string)($_GET['next'] ?? $_POST['next'] ?? 'index.php');
if ($next === '' || str_starts_with($next, 'http://') || str_starts_with($next, 'https://')) {
    $next = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = (string)($_POST['login_mode'] ?? 'account');
    if ($mode === 'viewer') {
        $viewerCode = (string)($_POST['viewer_code'] ?? '');
        if (login_dashboard_viewer_with_code($viewerCode)) {
            header('Location: dashboard.php', true, 302);
            exit;
        }
        $viewerError = 'Invalid dashboard access code.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (login_with_credentials($username, $password)) {
            header('Location: ' . $next, true, 302);
            exit;
        }
        $accountError = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?></title>
  <script>document.title = <?= json_encode($pageTitle, JSON_UNESCAPED_SLASHES) ?>;</script>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <main class="page" style="max-width: 460px; margin-top: 3rem;">
    <section class="card">
      <h1>Neighborhood Cut-through Analysis Tool (N-CAT)</h1>
      <h2 style="margin-top:0.2rem;">Login</h2>
      <p class="small">Sign in with your username and password.</p>
      <form method="post">
        <input type="hidden" name="login_mode" value="account">
        <input type="hidden" name="next" value="<?= h($next) ?>">
        <label>Username</label>
        <input type="text" name="username" autocomplete="username" required>
        <label>Password</label>
        <input type="password" name="password" autocomplete="current-password" required>
        <div class="actions">
          <button type="submit">Sign In</button>
        </div>
      </form>
      <?php if ($accountError !== ''): ?>
        <p class="status warn"><?= h($accountError) ?></p>
      <?php endif; ?>
      <p class="small" style="margin-top:0.8rem;">Use your assigned account. Contact an admin if you need access.</p>

      <hr style="margin:1rem 0; border:0; border-top:1px solid #d8dde7;">
      <h2 style="margin-bottom:0.4rem;">Dashboard Viewer</h2>
      <p class="small">No username required. Use a shared dashboard access code.</p>
      <form method="post">
        <input type="hidden" name="login_mode" value="viewer">
        <label>Dashboard Access Code</label>
        <input type="password" name="viewer_code" autocomplete="off" required>
        <div class="actions">
          <button type="submit" class="secondary">Open Dashboard</button>
        </div>
      </form>
      <?php if ($viewerError !== ''): ?>
        <p class="status warn"><?= h($viewerError) ?></p>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
