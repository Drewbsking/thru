<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';

ensure_schema();
require_auth_page();

function render_head(string $title): void
{
    $isLoggedIn = is_authenticated() || is_dashboard_viewer();

    echo '<!DOCTYPE html><html lang="en"><head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . h($title . ' | N-CAT') . '</title>';
    echo '<link rel="stylesheet" href="assets/app.css">';
    echo '</head><body>';
    echo '<header class="topbar" id="appTopbar">';
    echo '<div class="topbar-row">';
    echo '<div class="brand">N-CAT: Neighborhood Cut-through Analysis Tool</div>';
    echo '<button type="button" class="nav-toggle" id="navToggle" aria-controls="siteNav" aria-expanded="false" aria-label="Toggle navigation menu">☰</button>';
    echo '</div>';
    echo '<nav class="nav" id="siteNav">';
    if (is_dashboard_viewer()) {
        echo '<a href="dashboard.php">Dashboard</a>';
        echo '<a href="about.php">About</a>';
    } else {
        echo '<a href="about.php">About</a>';
        if ($isLoggedIn) {
            echo '<a href="index.php">Home</a>';
            echo '<a href="dashboard.php">Dashboard</a>';
            echo '<a href="details.php">Cut-Through Details</a>';
            if (is_admin()) {
                echo '<a href="setup.php">Site Setup</a>';
            }
        }
    }
    if ($isLoggedIn) {
        echo '<a href="logout.php">Logout</a>';
    } else {
        echo '<a href="login.php">Login</a>';
    }
    echo '</nav></header>';
    echo '<main class="page">';
}

function render_foot(): void
{
    echo '</main>';
    echo '<script>';
    echo '(function(){';
    echo 'var topbar=document.getElementById("appTopbar");';
    echo 'var toggle=document.getElementById("navToggle");';
    echo 'if(!topbar||!toggle){return;}';
    echo 'var nav=document.getElementById("siteNav");';
    echo 'function closeNav(){topbar.classList.remove("nav-open");toggle.setAttribute("aria-expanded","false");}';
    echo 'toggle.addEventListener("click",function(){';
    echo 'var isOpen=topbar.classList.toggle("nav-open");';
    echo 'toggle.setAttribute("aria-expanded",isOpen?"true":"false");';
    echo '});';
    echo 'if(nav){';
    echo 'nav.querySelectorAll("a").forEach(function(link){link.addEventListener("click",closeNav);});';
    echo '}';
    echo 'window.addEventListener("resize",function(){if(window.innerWidth>900){closeNav();}});';
    echo '})();';
    echo '</script>';
    echo '</body></html>';
}
