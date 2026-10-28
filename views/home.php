<?php defined('IPAHOST') || exit; ?>
<section class="card upload-card">
  <label class="dropzone" id="dropzone" data-chunk-size="<?= (int)$chunkSize ?>" data-endpoint="<?= h(url('upload')) ?>">
    <input type="file" id="file-input" accept=".ipa,application/octet-stream" multiple hidden>
    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>
    <strong>Drop .ipa files here</strong>
    <span class="muted">or tap to choose · any size, uploaded in chunks</span>
  </label>
  <details class="notes-toggle">
    <summary>Release notes for the next upload</summary>
    <textarea id="upload-notes" rows="3" placeholder="What changed? (optional, editable later)"></textarea>
  </details>
  <ul class="upload-list" id="upload-list"></ul>
</section>

<?php if ($deviceCount === 0): ?>
  <div class="flash flash-info">
    No devices registered yet. <a href="<?= h(url('devices')) ?>">Register your iPhone's UDID</a> to check which builds it can install.
  </div>
<?php endif; ?>

<div class="toolbar">
  <h1>Apps</h1>
  <?php if ($apps): ?>
    <input type="search" id="app-search" placeholder="Filter apps…" aria-label="Filter apps">
  <?php endif; ?>
</div>

<?php if (!$apps): ?>
  <div class="empty">
    <p>No apps yet. Upload an ad hoc, development or enterprise-signed IPA to get started.</p>
  </div>
<?php else: ?>
  <div class="grid" id="app-grid">
    <?php foreach ($apps as $a): $b = $a['latest']; [$cls, $label, $tip] = profile_badge($b['profile']); ?>
      <article class="app-card" data-search="<?= h(strtolower($a['name'] . ' ' . $a['bundle_id'])) ?>">
        <a class="app-card-head" href="<?= h(url('app', ['id' => $a['id']])) ?>">
          <?= app_icon($a['icon'], $a['name']) ?>
          <div class="min0">
            <h3 class="truncate"><?= h($a['name']) ?></h3>
            <div class="muted small truncate"><?= h($a['bundle_id']) ?></div>
          </div>
        </a>
        <div class="small">
          <strong><?= h(build_label($b)) ?></strong>
          <span class="muted">· <?= h(time_ago((int)$b['created_at'])) ?> · <?= h(format_bytes((int)$b['size'])) ?></span>
        </div>
        <div class="badges">
          <span class="badge <?= h($cls) ?>" title="<?= h($tip) ?>"><?= h($label) ?></span>
          <span class="badge"><?= (int)$a['build_count'] ?> build<?= $a['build_count'] == 1 ? '' : 's' ?></span>
          <?php if ($b['installs']): ?><span class="badge"><?= (int)$b['installs'] ?> install<?= $b['installs'] == 1 ? '' : 's' ?></span><?php endif; ?>
        </div>
        <div class="row gap">
          <?= install_button($b) ?>
          <a class="btn" href="<?= h(url('build', ['id' => $b['id']])) ?>">Details</a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<p class="muted small stats">
  <?= (int)$stats['apps'] ?> apps · <?= (int)$stats['builds'] ?> builds · <?= h(format_bytes($stats['bytes'])) ?> stored
  <?php if ($stats['free']): ?>· <?= h(format_bytes($stats['free'])) ?> free<?php endif; ?>
</p>
