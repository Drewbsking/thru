<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

$sites = all_sites();
$activeId = current_site_id();

render_head('Home');
?>
<section class="hero card">
  <h1>N-CAT Home</h1>
  <p class="small">Start here so staff do not need to remember URLs. Pick the site, then launch checkpoint-specific data entry or view N-CAT analytics.</p>
  <div class="actions">
    <a class="btn" href="dashboard.php">Open N-CAT Dashboard</a>
    <a class="btn secondary" href="details.php">N-CAT Cut-Through Details</a>
    <a class="btn secondary" href="setup.php">N-CAT Site Setup</a>
  </div>
</section>

<section class="grid two" style="margin-top:1rem;">
  <?php foreach ($sites as $site): ?>
    <article class="card">
      <h2><?= h($site['name']) ?><?= (int)$site['id'] === $activeId ? ' (Active)' : '' ?></h2>
      <p class="small">Site ID <?= (int)$site['id'] ?>. Use checkpoint links below for locked data entry.</p>
      <?php if (!empty($site['image_path'])): ?>
        <img class="site-preview" src="<?= h($site['image_path']) ?>" alt="Site image">
      <?php else: ?>
        <p class="small">No site image uploaded yet.</p>
      <?php endif; ?>
      <div class="actions">
        <a class="btn secondary" href="entry.php?site_id=<?= (int)$site['id'] ?>">Open N-CAT Data Entry</a>
      </div>
      <div class="small" style="margin-top:0.6rem;">Checkpoint Quick Links:</div>
      <div class="actions">
      <?php foreach (checkpoints_for_site((int)$site['id']) as $cp): ?>
        <a class="btn" href="entry.php?site_id=<?= (int)$site['id'] ?>&checkpoint_id=<?= (int)$cp['id'] ?>">
          <?= h($cp['display_name']) ?>
        </a>
      <?php endforeach; ?>
      </div>
    </article>
  <?php endforeach; ?>
</section>
<?php render_foot(); ?>
