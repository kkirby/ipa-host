<?php defined('IPAHOST') || exit; ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title ?? '') ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= APP_VERSION ?>">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/touch-icon.png">
<script src="assets/app.js?v=<?= APP_VERSION ?>" defer></script>
</head>
<body class="public">
<main class="narrow">
<?php foreach (take_flashes() as $f): ?>
  <div class="flash flash-<?= h($f['type']) ?>"><?= h($f['message']) ?></div>
<?php endforeach; ?>
<?= $content ?>
</main>
</body>
</html>
