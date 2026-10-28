<?php defined('IPAHOST') || exit; ?>
<div class="card center-text">
  <h1><?= h((string)$status) ?></h1>
  <p><?= h($message) ?></p>
  <p><a class="btn" href="<?= h(url()) ?>">Home</a></p>
</div>
