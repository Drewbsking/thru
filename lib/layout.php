<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/utils.php';

ensure_schema();

function render_head(string $title): void
{
    echo '<!DOCTYPE html><html lang="en"><head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . h($title) . '</title>';
    echo '<link rel="stylesheet" href="assets/app.css">';
    echo '</head><body>';
    echo '<header class="topbar">';
    echo '<div class="brand">Traffic Study Tool</div>';
    echo '<nav class="nav">';
    echo '<a href="index.php">Home</a>';
    echo '<a href="dashboard.php">Dashboard</a>';
    echo '<a href="entry.php">Data Entry</a>';
    echo '<a href="details.php">Cut-Through Details</a>';
    echo '<a href="setup.php">Site Setup</a>';
    echo '</nav></header>';
    echo '<main class="page">';
}

function render_foot(): void
{
    echo '</main>';
    echo '</body></html>';
}
