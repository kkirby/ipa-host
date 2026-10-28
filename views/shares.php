<?php defined('IPAHOST') || exit; ?>
<div class="toolbar"><h1>Share links</h1></div>
<p class="muted">Public install pages. Create them from an app (always installs the latest build) or a specific build. Anyone with a link can open it, so revoke links you no longer need.</p>

<?php if (!$shares): ?>
  <div class="empty"><p>No share links yet.</p></div>
<?php else: ?>
  <ul class="list card">
    <?php foreach ($shares as $s):
        $link = share_url($s);
        $expired = $s['expires_at'] && $s['expires_at'] < time();
        $target = $s['build_id'] ? build_label($s) : 'always latest';
    ?>
      <li class="list-row<?= (int)$s['id'] === $highlight ? ' highlight' : '' ?>">
        <div class="row gap min0">
          <?= app_icon($s['app_icon'], $s['app_name'], 'sm') ?>
          <div class="min0">
            <div class="truncate"><strong><?= h($s['label'] ?: $s['app_name']) ?></strong> <span class="muted small"><?= h($s['app_name']) ?> · <?= h($target) ?></span></div>
            <div class="muted small truncate"><?= h($link) ?></div>
            <div class="muted small">
              <?php if ($expired): ?><span class="badge bad">expired</span>
              <?php elseif ($s['expires_at']): ?>expires <?= h(time_ago((int)$s['expires_at'])) ?>
              <?php else: ?>never expires<?php endif; ?>
              · <?= (int)$s['views'] ?> views<?= $s['last_used_at'] ? ' · last ' . h(time_ago((int)$s['last_used_at'])) : '' ?>
            </div>
          </div>
        </div>
        <div class="row gap">
          <?= copy_button($link) ?>
          <button type="button" class="btn small" data-qr="<?= h($link) ?>" data-name="<?= h($s['label'] ?: $s['app_name']) ?>">QR</button>
          <form method="post" action="<?= h(url('share_revoke', ['id' => $s['id']])) ?>" data-confirm="Revoke this link?">
            <?= Auth::csrfField() ?>
            <button class="btn small danger" type="submit">Revoke</button>
          </form>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
