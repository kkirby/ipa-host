<?php defined('IPAHOST') || exit; [$cls, $label, $tip] = profile_badge($build['profile']); ?>
<div class="card center-text get">
  <?= app_icon($build['icon'], $build['name'], 'xl') ?>
  <h1><?= h($build['name']) ?></h1>
  <p class="muted"><?= h(build_label($build)) ?> · <?= h(format_bytes((int)$build['size'])) ?><?= $build['min_os'] ? ' · iOS ' . h($build['min_os']) . '+' : '' ?></p>
  <p class="muted small">Uploaded <?= h(format_date((int)$build['created_at'])) ?></p>

  <a class="btn primary big install-direct" href="<?= h(install_link($build)) ?>">Install</a>

  <div class="not-ios small" hidden>
    <p>Open this page in Safari on an iPhone or iPad to install.</p>
  </div>

  <?php if ($cls === 'bad'): ?>
    <div class="flash flash-err small"><?= h($tip) ?></div>
  <?php endif; ?>

  <?php if ($build['notes'] !== ''): ?>
    <div class="notes left-text"><h2>What's new</h2><p><?= nl2br(h($build['notes'])) ?></p></div>
  <?php endif; ?>

  <details class="left-text small">
    <summary>Install not working?</summary>
    <ul>
      <li>Use Safari. Other browsers may ignore the install link.</li>
      <li>"Unable to install" usually means this device's UDID isn't in the app's provisioning profile, or the profile expired.</li>
      <li>On iOS 16+, enable <em>Settings → Privacy &amp; Security → Developer Mode</em> for development-signed apps.</li>
      <li>Enterprise apps must be trusted in <em>Settings → General → VPN &amp; Device Management</em> before first launch.</li>
    </ul>
  </details>
  <?php if ($share && $share['expires_at']): ?>
    <p class="muted small">This link expires <?= h(time_ago((int)$share['expires_at'])) ?>.</p>
  <?php endif; ?>
</div>
