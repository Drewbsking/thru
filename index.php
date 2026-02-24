<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

$sites = scoped_sites_for_current_user();
$activeId = current_site_id();

render_head('Home');
?>
<section class="hero card">
  <p class="small">Start here so staff do not need to remember URLs. Pick the site, then launch checkpoint-specific data entry or view N-CAT analytics.</p>
  <div class="actions">
    <a class="btn" href="dashboard.php">Open N-CAT Dashboard</a>
    <a class="btn secondary" href="details.php">N-CAT Cut-Through Details</a>
    <?php if (is_admin()): ?>
      <a class="btn secondary" href="setup.php">N-CAT Site Setup</a>
    <?php endif; ?>
  </div>
</section>

<section class="grid two" style="margin-top:1rem;">
  <?php if (count($sites) === 0): ?>
    <article class="card">
      <h2>No Checkpoint Assignment</h2>
      <p class="small">Your account is not assigned to any checkpoint yet. Ask an admin to assign you in Site Setup.</p>
    </article>
  <?php endif; ?>
  <?php foreach ($sites as $site): ?>
    <article class="card">
      <h2><?= h($site['name']) ?><?= (int)$site['id'] === $activeId ? ' (Active)' : '' ?></h2>
      <p class="small">Site ID <?= (int)$site['id'] ?>. Use checkpoint links below for locked data entry.</p>
      <?php if (!empty($site['image_path'])): ?>
        <img class="site-preview" src="<?= h((string)$site['image_path']) ?>" alt="<?= h((string)$site['name']) ?> site image" style="max-height:220px;">
      <?php else: ?>
        <p class="small">No site image uploaded for this site yet.</p>
      <?php endif; ?>
      <div class="small" style="margin-top:0.6rem;">Checkpoint Quick Links:</div>
      <div class="actions">
      <?php foreach (($site['checkpoints'] ?? []) as $cp): ?>
        <a class="btn" href="entry.php?site_id=<?= (int)$site['id'] ?>&checkpoint_id=<?= (int)$cp['id'] ?>">
          <?= h($cp['display_name']) ?>
        </a>
      <?php endforeach; ?>
      </div>
    </article>
  <?php endforeach; ?>
</section>
<?php render_foot(); ?>
