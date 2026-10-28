<?php defined('IPAHOST') || exit; ?>
<div class="toolbar">
  <h1>Activity</h1>
  <div class="row gap">
    <form method="get" action="index.php" class="row gap">
      <input type="hidden" name="p" value="activity">
      <select name="kind" aria-label="Filter" data-autosubmit>
        <option value="">All events</option>
        <?php foreach ($kinds as $k): ?>
          <option value="<?= h($k) ?>"<?= $k === $kind ? ' selected' : '' ?>><?= h($k) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn small" type="submit">Filter</button></noscript>
    </form>
    <?php if ($events): ?>
      <form method="post" action="<?= h(url('activity_clear')) ?>" data-confirm="Clear the entire activity log?">
        <?= Auth::csrfField() ?>
        <button class="btn small danger" type="submit">Clear log</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!$events): ?>
  <div class="empty"><p>Nothing logged yet.</p></div>
<?php else: ?>
  <div class="table-wrap card">
    <table class="table">
      <thead><tr><th>When</th><th>Event</th><th>Build</th><th>IP</th><th>Client</th></tr></thead>
      <tbody>
      <?php foreach ($events as $e): ?>
        <tr>
          <td class="nowrap" title="<?= h(format_date((int)$e['created_at'])) ?>"><?= h(time_ago((int)$e['created_at'])) ?></td>
          <td><span class="badge kind-<?= h($e['kind']) ?>"><?= h($e['kind']) ?></span></td>
          <td>
            <?php if ($e['build_id']): ?>
              <a href="<?= h(url('build', ['id' => $e['build_id']])) ?>"><?= h($e['build_name'] . ' ' . build_label($e)) ?></a>
            <?php else: ?>
              <span class="muted"><?= h($e['detail']) ?></span>
            <?php endif; ?>
          </td>
          <td class="mono small"><?= h($e['ip']) ?></td>
          <td class="small muted ua" title="<?= h($e['ua']) ?>"><?= h(mb_substr_safe($e['ua'], 70)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="row gap center">
    <?php if ($page > 1): ?><a class="btn small" href="<?= h(url('activity', ['page' => $page - 1, 'kind' => $kind])) ?>">Newer</a><?php endif; ?>
    <?php if ($hasMore): ?><a class="btn small" href="<?= h(url('activity', ['page' => $page + 1, 'kind' => $kind])) ?>">Older</a><?php endif; ?>
  </div>
<?php endif; ?>
