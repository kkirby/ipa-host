<?php defined('IPAHOST') || exit; ?>
<p class="crumbs"><a href="<?= h(url()) ?>">Apps</a> /</p>
<section class="hero">
  <?= app_icon($app['icon'], $app['name'], 'lg') ?>
  <div class="min0">
    <h1><?= h($app['name']) ?></h1>
    <div class="muted"><?= h($app['bundle_id']) ?></div>
    <div class="muted small"><?= count($builds) ?> build<?= count($builds) === 1 ? '' : 's' ?> · <?= h(format_bytes($totalSize)) ?></div>
  </div>
</section>

<?php if ($builds): ?>
  <div class="row gap wrap-row">
    <?= install_button($builds[0], 'Install latest') ?>
    <form method="post" action="<?= h(url('share_create')) ?>" class="row gap">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>">
      <input type="hidden" name="label" value="<?= h($app['name']) ?> (always latest)">
      <input type="hidden" name="hours" value="0">
      <button class="btn" type="submit" title="A permanent link that always installs the newest build. Bookmark it on your phone.">Create "always latest" link</button>
    </form>
  </div>
<?php endif; ?>

<?php if ($shares): ?>
  <section class="card">
    <h2>"Always latest" links</h2>
    <ul class="list">
      <?php foreach ($shares as $s): $link = share_url($s); ?>
        <li class="list-row">
          <div class="min0">
            <div class="truncate"><?= h($s['label'] ?: 'Untitled') ?></div>
            <div class="muted small truncate"><?= h($link) ?></div>
          </div>
          <div class="row gap">
            <?= copy_button($link) ?>
            <button type="button" class="btn small" data-qr="<?= h($link) ?>" data-name="<?= h($s['label']) ?>">QR</button>
            <form method="post" action="<?= h(url('share_revoke', ['id' => $s['id']])) ?>" data-confirm="Revoke this link?">
              <?= Auth::csrfField() ?>
              <input type="hidden" name="back" value="<?= h('?' . http_build_query(['p' => 'app', 'id' => $app['id']])) ?>">
              <button class="btn small danger" type="submit">Revoke</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<h2>Builds</h2>
<div class="builds">
  <?php foreach ($builds as $i => $b): [$cls, $label, $tip] = profile_badge($b['profile']); ?>
    <article class="build-row">
      <div class="min0">
        <div class="row gap baseline">
          <a href="<?= h(url('build', ['id' => $b['id']])) ?>"><strong><?= h(build_label($b)) ?></strong></a>
          <?php if ($i === 0): ?><span class="badge accent">latest</span><?php endif; ?>
          <span class="badge <?= h($cls) ?>" title="<?= h($tip) ?>"><?= h($label) ?></span>
        </div>
        <div class="muted small"><?= h(format_date((int)$b['created_at'])) ?> · <?= h(format_bytes((int)$b['size'])) ?> · <?= (int)$b['installs'] ?> installs</div>
        <?php if ($b['notes'] !== ''): ?><p class="notes small"><?= nl2br(h($b['notes'])) ?></p><?php endif; ?>
      </div>
      <div class="row gap">
        <?= install_button($b, 'Install', 'btn small primary') ?>
        <a class="btn small" href="<?= h(url('build', ['id' => $b['id']])) ?>">Details</a>
      </div>
    </article>
  <?php endforeach; ?>
</div>

<section class="card danger-zone">
  <h2>Danger zone</h2>
  <form method="post" action="<?= h(url('app_delete', ['id' => $app['id']])) ?>" data-confirm="Delete <?= h($app['name']) ?> and all <?= count($builds) ?> builds? This cannot be undone.">
    <?= Auth::csrfField() ?>
    <button class="btn danger" type="submit">Delete app and all builds</button>
  </form>
</section>
