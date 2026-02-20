<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/matcher.php';
require_once __DIR__ . '/../lib/auth.php';

ensure_schema();
require_auth_api();
