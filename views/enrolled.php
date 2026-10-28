<?php defined('IPAHOST') || exit; ?>
<div class="card center-text">
  <div class="big-check">✓</div>
  <h1>Device registered</h1>
  <p class="muted"><?= h(trim($device['product'] . ($device['os_version'] ? ' · iOS ' . $device['os_version'] : ''))) ?></p>
  <p class="mono break udid"><?= h($device['udid']) ?></p>
  <p><button type="button" class="btn" data-copy="<?= h($device['udid']) ?>">Copy UDID</button></p>
  <p class="muted small">You can delete the leftover profile in Settings → General → VPN &amp; Device Management if iOS kept one. Next: add this UDID to your provisioning profile at developer.apple.com and re-export the app.</p>
  <p><a class="btn primary" href="<?= h(url('devices')) ?>">Back to devices</a></p>
</div>
