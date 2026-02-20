<?php

declare(strict_types=1);

function app_config(): array
{
    return [
        'db_host' => getenv('THRU_DB_HOST') ?: 'localhost',
        'db_user' => getenv('THRU_DB_USER') ?: 'rcocwiki_thru',
        'db_pass' => getenv('THRU_DB_PASS') ?: 'Password#110',
        'db_name' => getenv('THRU_DB_NAME') ?: 'rcocwiki_thru',
        'upload_dir' => __DIR__ . '/../uploads/site-images',
        'upload_web_path' => 'uploads/site-images',
    ];
}
