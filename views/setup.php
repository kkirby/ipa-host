<?php defined('IPAHOST') || exit; ?>
<div class="card">
  <div class="logo-row"><img src="assets/icon.svg" alt="" width="40" height="40"><h1>Set up IPA Host</h1></div>
  <p class="muted">This creates the single admin account. Do it right after uploading — until setup is finished, anyone who opens this page could claim it.</p>

  <?php foreach ($errors as $e): ?>
    <div class="flash flash-err"><?= h($e) ?></div>
  <?php endforeach; ?>

  <form method="post" action="<?= h(url('setup')) ?>" class="stack">
    <?= Auth::csrfField() ?>
    <label>Site title
      <input type="text" name="site_title" value="<?= h($values['site_title']) ?>" required maxlength="60">
    </label>
    <label>Base URL
      <input type="url" name="base_url" value="<?= h($values['base_url']) ?>" required>
      <span class="hint">Public HTTPS address of this folder. Install links are built from it.</span>
    </label>
    <label>Password
      <input type="password" name="password" required minlength="10" autocomplete="new-password">
    </label>
    <label>Confirm password
      <input type="password" name="password_confirm" required minlength="10" autocomplete="new-password">
    </label>
    <button type="submit" class="btn primary">Finish setup</button>
  </form>
</div>

<div class="card">
  <h2>Server check</h2>
  <?php require __DIR__ . '/_checks.php'; ?>
</div>
