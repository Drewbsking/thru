<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/utils.php';

ensure_schema();
auth_session_start();

if (is_authenticated()) {
    header('Location: index.php', true, 302);
    exit;
}

$error = '';
$next = (string)($_GET['next'] ?? $_POST['next'] ?? 'index.php');
if ($next === '' || str_starts_with($next, 'http://') || str_starts_with($next, 'https://')) {
    $next = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    if (login_with_password($password)) {
        header('Location: ' . $next, true, 302);
        exit;
    }
    $error = 'Invalid password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login | N-CAT</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <main class="page" style="max-width: 460px; margin-top: 3rem;">
    <section class="card">
      <h1>N-CAT Login</h1>
      <p class="small">Enter the N-CAT password to continue.</p>
      <form method="post">
        <input type="hidden" name="next" value="<?= h($next) ?>">
        <label>Password</label>
        <input type="password" name="password" autocomplete="current-password" required>
        <div class="actions">
          <button type="submit">Sign In</button>
        </div>
      </form>
      <?php if ($error !== ''): ?>
        <p class="status warn"><?= h($error) ?></p>
      <?php endif; ?>
      <p class="small" style="margin-top:0.8rem;">Default password is <code class="inline">change-me-now</code> unless `THRU_APP_PASSWORD` is set. Change it in Site Setup after login.</p>
    </section>
  </main>
</body>
</html>
