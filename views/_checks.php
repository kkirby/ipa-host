<?php defined('IPAHOST') || exit; ?>
<ul class="checks">
  <?php foreach ($checks as [$label, $ok, $detail]): ?>
    <li class="<?= $ok ? 'ok' : 'warn' ?>">
      <span class="check-mark"><?= $ok ? '✓' : '!' ?></span>
      <div><strong><?= h($label) ?></strong><div class="muted small"><?= h($detail) ?></div></div>
    </li>
  <?php endforeach; ?>
</ul>
