<?php defined('IPAHOST') || exit; ?>
<div class="toolbar"><h1>Settings</h1></div>

<div class="cols">
  <section class="card">
    <h2>General</h2>
    <form method="post" action="<?= h(url('settings_save')) ?>" class="stack">
      <?= Auth::csrfField() ?>
      <label>Site title
        <input type="text" name="site_title" value="<?= h(Settings::get('site_title')) ?>" maxlength="60">
      </label>
      <label>Base URL
        <input type="url" name="base_url" value="<?= h(Settings::get('base_url')) ?>" placeholder="<?= h(detect_base_url()) ?>">
        <span class="hint">Used for install links. Must be the public HTTPS URL of this folder. Leave blank to auto-detect (<?= h(detect_base_url()) ?>).</span>
      </label>
      <label>Builds to keep per app
        <input type="number" name="keep_builds" min="0" value="<?= (int)Settings::get('keep_builds') ?>">
        <span class="hint">Older builds are deleted automatically after each upload. 0 keeps everything.</span>
      </label>
      <label>Install link lifetime (hours)
        <input type="number" name="link_ttl_hours" min="1" max="8760" value="<?= (int)Settings::get('link_ttl_hours') ?>">
        <span class="hint">How long an Install button / QR code keeps working after the page was loaded.</span>
      </label>
      <button class="btn primary" type="submit">Save</button>
    </form>
  </section>

  <section class="card">
    <h2>Password</h2>
    <form method="post" action="<?= h(url('password')) ?>" class="stack">
      <?= Auth::csrfField() ?>
      <label>Current password <input type="password" name="current" required autocomplete="current-password"></label>
      <label>New password <input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
      <label>Confirm new password <input type="password" name="confirm" required minlength="10" autocomplete="new-password"></label>
      <button class="btn" type="submit">Change password</button>
    </form>
    <hr>
    <p class="muted small"><?= (int)$sessions ?> remembered device<?= $sessions === 1 ? '' : 's' ?>.</p>
    <form method="post" action="<?= h(url('logout_all')) ?>" data-confirm="Sign out on every device, including this one?">
      <?= Auth::csrfField() ?>
      <button class="btn danger" type="submit">Sign out everywhere</button>
    </form>
  </section>
</div>

<section class="card">
  <h2>API token</h2>
  <p class="muted small">Upload from scripts, CI, or an Xcode post-archive action.</p>
  <?php if ($newToken): ?>
    <div class="flash flash-ok">
      <div class="mono break"><?= h($newToken) ?></div>
      <div class="row gap"><?= copy_button($newToken, 'Copy token') ?></div>
    </div>
  <?php elseif ($hasToken): ?>
    <p>Active token ending in <code>…<?= h($tokenHint) ?></code>.</p>
  <?php else: ?>
    <p>No API token. The API is disabled.</p>
  <?php endif; ?>
  <div class="row gap">
    <form method="post" action="<?= h(url('api_token')) ?>" <?= $hasToken ? 'data-confirm="Replace the current token? Scripts using it will stop working."' : '' ?>>
      <?= Auth::csrfField() ?>
      <button class="btn" type="submit"><?= $hasToken ? 'Regenerate token' : 'Create token' ?></button>
    </form>
    <?php if ($hasToken): ?>
      <form method="post" action="<?= h(url('api_token')) ?>" data-confirm="Revoke the API token?">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="revoke">
        <button class="btn danger" type="submit">Revoke</button>
      </form>
    <?php endif; ?>
  </div>
  <details>
    <summary>Examples</summary>
<pre class="code"><?= h(
'# Upload (optionally create a 7-day share link)
curl -fsS -H "Authorization: Bearer $IPA_HOST_TOKEN" \
  -F "ipa=@build/MyApp.ipa" -F "notes=Fixed login bug" -F "share_hours=168" \
  "' . abs_url('api', ['action' => 'upload']) . '"

# List apps with latest build + install links
curl -fsS -H "Authorization: Bearer $IPA_HOST_TOKEN" "' . abs_url('api', ['action' => 'apps']) . '"

# Builds for one app
curl -fsS -H "Authorization: Bearer $IPA_HOST_TOKEN" "' . abs_url('api', ['action' => 'builds', 'app_id' => 1]) . '"

# Share link for a build (hours=0 never expires)
curl -fsS -H "Authorization: Bearer $IPA_HOST_TOKEN" -d build_id=12 -d hours=24 \
  "' . abs_url('api', ['action' => 'share']) . '"

# Delete a build
curl -fsS -H "Authorization: Bearer $IPA_HOST_TOKEN" -d build_id=12 \
  "' . abs_url('api', ['action' => 'delete']) . '"'
) ?></pre>
    <p class="muted small">API uploads are a single request, so they're limited by <code>upload_max_filesize</code> (<?= h(ini_get('upload_max_filesize')) ?>) and <code>post_max_size</code> (<?= h(ini_get('post_max_size')) ?>). Browser uploads are chunked and have no such limit.</p>
  </details>
</section>

<section class="card">
  <h2>Server</h2>
  <?php require __DIR__ . '/_checks.php'; ?>
  <p class="muted small">PHP <?= h(PHP_VERSION) ?> · memory_limit <?= h(ini_get('memory_limit')) ?> · data dir <code><?= h(DATA_DIR) ?></code></p>
</section>
