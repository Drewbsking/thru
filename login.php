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
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (login_with_credentials($username, $password)) {
        header('Location: ' . $next, true, 302);
        exit;
    }
    $error = 'Invalid username or password.';
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
      <h1>Login</h1>
      <p class="small">Sign in with your username and password.</p>
      <form method="post">
        <input type="hidden" name="next" value="<?= h($next) ?>">
        <label>Username</label>
        <input type="text" name="username" autocomplete="username" required>
        <label>Password</label>
        <input type="password" name="password" autocomplete="current-password" required>
        <div class="actions">
          <button type="submit">Sign In</button>
        </div>
      </form>
      <?php if ($error !== ''): ?>
        <p class="status warn"><?= h($error) ?></p>
      <?php endif; ?>
      <p class="small" style="margin-top:0.8rem;">Use your assigned account. Contact an admin if you need access.</p>
    </section>
  </main>
</body>
</html>
