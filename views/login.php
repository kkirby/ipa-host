<?php defined('IPAHOST') || exit; ?>
<div class="card login">
  <div class="logo-row"><img src="assets/icon.svg" alt="" width="40" height="40"><h1><?= h(Settings::get('site_title')) ?></h1></div>
  <?php if ($error): ?>
    <div class="flash flash-err"><?= h($error) ?></div>
  <?php endif; ?>
  <form method="post" action="<?= h(url('login')) ?>" class="stack">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <label>Password
      <input type="password" name="password" required autofocus autocomplete="current-password">
    </label>
    <label class="checkbox"><input type="checkbox" name="remember" value="1" checked> Keep me signed in on this device</label>
    <button type="submit" class="btn primary">Sign in</button>
  </form>
</div>
