<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';

ensure_schema();
logout_user();
header('Location: login.php', true, 302);
exit;
