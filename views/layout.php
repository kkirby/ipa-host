<?php defined('IPAHOST') || exit; ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= h(Auth::csrfToken()) ?>">
<title><?= h($title ?? '') ?> · <?= h(Settings::get('site_title')) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= APP_VERSION ?>">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/touch-icon.png">
<script src="assets/qrcode.js?v=<?= APP_VERSION ?>" defer></script>
<script src="assets/app.js?v=<?= APP_VERSION ?>" defer></script>
</head>
<body>
<header class="topbar">
  <div class="wrap topbar-inner">
    <a class="brand" href="<?= h(url()) ?>"><img src="assets/icon.svg" alt="" width="22" height="22"><?= h(Settings::get('site_title')) ?></a>
    <nav class="nav">
      <a href="<?= h(url()) ?>"<?= nav_active('home', 'app', 'build') ?>>Apps</a>
      <a href="<?= h(url('devices')) ?>"<?= nav_active('devices') ?>>Devices</a>
      <a href="<?= h(url('shares')) ?>"<?= nav_active('shares') ?>>Links</a>
      <a href="<?= h(url('activity')) ?>"<?= nav_active('activity') ?>>Activity</a>
      <a href="<?= h(url('settings')) ?>"<?= nav_active('settings') ?>>Settings</a>
    </nav>
    <form method="post" action="<?= h(url('logout')) ?>" class="signout">
      <?= Auth::csrfField() ?>
      <button type="submit" class="linkish">Sign out</button>
    </form>
  </div>
</header>
<main class="wrap">
<?php foreach (take_flashes() as $f): ?>
  <div class="flash flash-<?= h($f['type']) ?>"><?= h($f['message']) ?></div>
<?php endforeach; ?>
<?= $content ?>
</main>
<footer class="wrap footer">IPA Host <?= h(APP_VERSION) ?></footer>

<div class="modal" id="qr-modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="qr-title">
    <h2 id="qr-title">Scan to install</h2>
    <p class="muted" id="qr-name"></p>
    <div id="qr-code" class="qr"></div>
    <p class="muted small">Point your iPhone or iPad camera at the code, open the link in Safari, then tap Install.</p>
    <div class="row gap center">
      <button type="button" class="btn small" id="qr-copy">Copy link</button>
      <button type="button" class="btn small" data-close>Close</button>
    </div>
  </div>
</div>
</body>
</html>
