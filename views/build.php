<?php defined('IPAHOST') || exit;
$p = $build['profile'];
[$cls, $label, $tip] = profile_badge($p);
$meta = $build['meta'];
?>
<p class="crumbs"><a href="<?= h(url()) ?>">Apps</a> / <a href="<?= h(url('app', ['id' => $build['app_id']])) ?>"><?= h($app['name']) ?></a> /</p>

<section class="hero">
  <?= app_icon($build['icon'], $build['name'], 'lg') ?>
  <div class="min0">
    <h1><?= h($build['name']) ?> <span class="muted"><?= h(build_label($build)) ?></span></h1>
    <div class="muted"><?= h($build['bundle_id']) ?></div>
    <div class="badges">
      <span class="badge <?= h($cls) ?>" title="<?= h($tip) ?>"><?= h($label) ?></span>
      <?php if ($isLatest): ?><span class="badge accent">latest</span><?php endif; ?>
      <?php if (empty($meta['signed'])): ?><span class="badge bad">not code-signed</span><?php endif; ?>
    </div>
  </div>
</section>

<?php if ($cls === 'bad'): ?>
  <div class="flash flash-err"><?= h($tip) ?></div>
<?php endif; ?>

<div class="row gap wrap-row">
  <?= install_button($build) ?>
  <a class="btn" href="<?= h(url('download', ['id' => $build['id']])) ?>">Download IPA</a>
  <button type="button" class="btn" data-qr="<?= h(qr_link($build)) ?>" data-name="<?= h($build['name'] . ' ' . build_label($build)) ?>">QR code</button>
  <?= copy_button(install_link($build), 'Copy itms link') ?>
</div>

<div class="cols">
  <section class="card">
    <h2>Details</h2>
    <dl class="kv">
      <dt>Version</dt><dd><?= h($build['version'] ?: '—') ?></dd>
      <dt>Build</dt><dd><?= h($build['build'] ?: '—') ?></dd>
      <dt>Bundle ID</dt><dd class="mono"><?= h($build['bundle_id']) ?></dd>
      <dt>Minimum OS</dt><dd><?= h($build['min_os'] ?: '—') ?></dd>
      <dt>Devices</dt><dd><?= h($build['families'] ?: '—') ?></dd>
      <?php if (!empty($meta['sdk'])): ?><dt>Built with</dt><dd><?= h($meta['sdk']) ?><?= !empty($meta['xcode']) ? ' · Xcode ' . h(ltrim(substr($meta['xcode'], 0, 2), '0') . '.' . ($meta['xcode'][2] ?? '0')) : '' ?></dd><?php endif; ?>
      <dt>Size</dt><dd><?= h(format_bytes((int)$build['size'])) ?></dd>
      <dt>Uploaded</dt><dd><?= h(format_date((int)$build['created_at'])) ?> <span class="muted">(<?= h(time_ago((int)$build['created_at'])) ?>)</span></dd>
      <dt>File</dt><dd class="truncate"><?= h($build['original_name']) ?></dd>
      <dt>SHA-256</dt><dd class="mono small break"><?= h($build['sha256']) ?></dd>
      <dt>Installs</dt><dd><?= (int)$build['installs'] ?><?= $build['last_installed_at'] ? ' <span class="muted">(last ' . h(time_ago((int)$build['last_installed_at'])) . ')</span>' : '' ?></dd>
      <dt>Downloads</dt><dd><?= (int)$build['downloads'] ?></dd>
    </dl>
  </section>

  <section class="card">
    <h2>Release notes</h2>
    <form method="post" action="<?= h(url('build_notes', ['id' => $build['id']])) ?>" class="stack">
      <?= Auth::csrfField() ?>
      <textarea name="notes" rows="6" placeholder="What changed in this build?"><?= h($build['notes']) ?></textarea>
      <button class="btn" type="submit">Save notes</button>
    </form>
  </section>
</div>

<section class="card">
  <h2>Provisioning profile</h2>
  <?php if (!$p): ?>
    <p>No <code>embedded.mobileprovision</code> in this IPA. It was not signed for device distribution and will not install over the air.</p>
  <?php else: ?>
    <dl class="kv">
      <dt>Name</dt><dd><?= h($p['name']) ?></dd>
      <dt>Type</dt><dd><span class="badge <?= h($cls) ?>"><?= h($label) ?></span></dd>
      <dt>Team</dt><dd><?= h($p['team_name']) ?> <span class="muted mono"><?= h($p['team_id']) ?></span></dd>
      <?php if ($p['app_id_name']): ?><dt>App ID</dt><dd><?= h($p['app_id_name']) ?></dd><?php endif; ?>
      <dt>Created</dt><dd><?= h(format_date($p['created'])) ?></dd>
      <dt>Expires</dt><dd><?= h(format_date($p['expires'])) ?> <span class="muted">(<?= h(time_ago($p['expires'])) ?>)</span></dd>
      <dt>Devices</dt><dd><?= $p['all_devices'] ? 'All devices (enterprise)' : count($p['devices']) . ' provisioned' ?></dd>
      <?php if (!empty($p['entitlements']['aps-environment'])): ?><dt>Push</dt><dd><?= h($p['entitlements']['aps-environment']) ?></dd><?php endif; ?>
    </dl>
    <?php if ($p['type'] === 'appstore'): ?>
      <div class="flash flash-err">This is an App Store / TestFlight distribution build. iOS only installs those through the App Store or TestFlight. Re-export from Xcode with "Release Testing" (Ad Hoc), "Debugging" (Development) or Enterprise.</div>
    <?php endif; ?>

    <?php if (!$p['all_devices']): ?>
      <h3>Your devices</h3>
      <?php if (!$devices): ?>
        <p class="muted">No registered devices. <a href="<?= h(url('devices')) ?>">Register one</a> to see whether it can install this build.</p>
      <?php else: ?>
        <ul class="list">
          <?php foreach ($devices as $d): ?>
            <li class="list-row">
              <div class="min0">
                <div><?= h($d['name'] ?: 'Unnamed') ?> <span class="muted small"><?= h($d['product']) ?></span></div>
                <div class="muted small mono truncate"><?= h($d['udid']) ?></div>
              </div>
              <?= $d['covered'] ? '<span class="badge ok">included</span>' : '<span class="badge bad">not in profile</span>' ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($unknownDevices): ?>
        <details>
          <summary class="muted small"><?= count($unknownDevices) ?> other provisioned UDID<?= count($unknownDevices) === 1 ? '' : 's' ?></summary>
          <ul class="mono small plain"><?php foreach ($unknownDevices as $u): ?><li><?= h($u) ?></li><?php endforeach; ?></ul>
        </details>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($p['entitlements']): ?>
      <details>
        <summary>Entitlements</summary>
        <pre class="code"><?= h(json_encode($p['entitlements'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
      </details>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php if (!empty($meta['bundles'])): ?>
  <section class="card">
    <h2>Embedded extensions</h2>
    <ul class="list">
      <?php foreach ($meta['bundles'] as $x): ?>
        <li class="list-row">
          <div class="min0">
            <div><?= h($x['name']) ?></div>
            <div class="muted small mono truncate"><?= h($x['bundle_id']) ?></div>
          </div>
          <span class="muted small"><?= h($x['kind']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<section class="card">
  <h2>Share</h2>
  <p class="muted small">Public install page for this exact build. Anyone with the link can install it (if their device is in the profile). Revoke any time.</p>
  <form method="post" action="<?= h(url('share_create')) ?>" class="row gap wrap-row">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="build_id" value="<?= (int)$build['id'] ?>">
    <input type="text" name="label" placeholder="Label (e.g. for Sam)" maxlength="200" class="grow">
    <select name="hours" aria-label="Expires">
      <option value="1">1 hour</option>
      <option value="24">1 day</option>
      <option value="168" selected>7 days</option>
      <option value="720">30 days</option>
      <option value="0">Never</option>
    </select>
    <button class="btn" type="submit">Create link</button>
  </form>
  <?php if ($shares): ?>
    <ul class="list">
      <?php foreach ($shares as $s): $link = share_url($s); $expired = $s['expires_at'] && $s['expires_at'] < time(); ?>
        <li class="list-row">
          <div class="min0">
            <div class="truncate"><?= h($s['label'] ?: 'Untitled') ?> <?= $expired ? '<span class="badge bad">expired</span>' : '' ?></div>
            <div class="muted small"><?= $s['expires_at'] ? ($expired ? 'expired ' : 'expires ') . h(time_ago((int)$s['expires_at'])) : 'never expires' ?> · <?= (int)$s['views'] ?> views</div>
          </div>
          <div class="row gap">
            <?= copy_button($link) ?>
            <form method="post" action="<?= h(url('share_revoke', ['id' => $s['id']])) ?>">
              <?= Auth::csrfField() ?>
              <input type="hidden" name="back" value="<?= h('?' . http_build_query(['p' => 'build', 'id' => $build['id']])) ?>">
              <button class="btn small danger" type="submit">Revoke</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php if ($events): ?>
  <section class="card">
    <h2>Recent activity</h2>
    <ul class="list">
      <?php foreach ($events as $e): ?>
        <li class="list-row small">
          <div class="min0"><strong><?= h($e['kind']) ?></strong><div class="muted truncate"><?= h($e['ip']) ?> · <?= h($e['ua']) ?></div></div>
          <span class="muted"><?= h(time_ago((int)$e['created_at'])) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<details class="card">
  <summary><strong>Info.plist</strong></summary>
  <pre class="code"><?= h($infoJson) ?></pre>
</details>

<section class="card danger-zone">
  <h2>Danger zone</h2>
  <form method="post" action="<?= h(url('build_delete', ['id' => $build['id']])) ?>" data-confirm="Delete <?= h($build['name'] . ' ' . build_label($build)) ?>? This cannot be undone.">
    <?= Auth::csrfField() ?>
    <button class="btn danger" type="submit">Delete this build</button>
  </form>
</section>
