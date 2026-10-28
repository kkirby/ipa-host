<?php defined('IPAHOST') || exit; ?>
<div class="toolbar">
  <h1>Devices</h1>
  <?php if ($devices): ?>
    <div class="row gap">
      <?= copy_button(implode("\n", array_column($devices, 'udid')), 'Copy UDIDs') ?>
      <a class="btn small" href="<?= h(url('devices_export')) ?>" title="Upload this file at developer.apple.com → Devices → Register Multiple Devices">Export for Apple</a>
    </div>
  <?php endif; ?>
</div>

<div class="cols">
  <section class="card">
    <h2>Register this iPhone or iPad</h2>
    <ol class="steps small">
      <li>Open this page in <strong>Safari</strong> on the device and tap the button below.</li>
      <li>Tap <em>Allow</em>, then go to <em>Settings → Profile Downloaded → Install</em>.</li>
      <li>iOS sends the UDID here and opens a confirmation page. Nothing stays installed.</li>
      <li>Add the UDID to your provisioning profile at developer.apple.com, then re-sign / re-export the app.</li>
    </ol>
    <a class="btn primary" href="<?= h(url('enroll')) ?>">Get device UDID</a>
  </section>

  <section class="card">
    <h2>Add manually</h2>
    <form method="post" action="<?= h(url('device_save')) ?>" class="stack">
      <?= Auth::csrfField() ?>
      <label>Name <input type="text" name="name" maxlength="100" placeholder="My iPhone"></label>
      <label>UDID <input type="text" name="udid" required class="mono" placeholder="00008110-000A1B2C3D4E5F6A" autocapitalize="characters" spellcheck="false"></label>
      <button class="btn" type="submit">Add device</button>
    </form>
    <p class="muted small">On a Mac: connect the device, open Finder (or Apple Configurator / Xcode → Devices) and click the serial number until it shows the UDID.</p>
  </section>
</div>

<?php if ($devices): ?>
  <ul class="list card">
    <?php foreach ($devices as $d): ?>
      <li class="list-row">
        <?php if ($edit === (int)$d['id']): ?>
          <form method="post" action="<?= h(url('device_save')) ?>" class="row gap wrap-row grow">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <input type="text" name="name" value="<?= h($d['name']) ?>" maxlength="100" class="grow" aria-label="Name">
            <input type="text" name="udid" value="<?= h($d['udid']) ?>" class="mono grow" aria-label="UDID">
            <button class="btn small primary" type="submit">Save</button>
            <a class="btn small" href="<?= h(url('devices')) ?>">Cancel</a>
          </form>
        <?php else: ?>
          <div class="min0">
            <div><strong><?= h($d['name'] ?: 'Unnamed') ?></strong>
              <span class="muted small"><?= h(trim($d['product'] . ($d['os_version'] ? ' · iOS ' . $d['os_version'] : ''))) ?></span></div>
            <div class="mono small truncate"><?= h($d['udid']) ?></div>
            <div class="muted small">added <?= h(time_ago((int)$d['created_at'])) ?></div>
          </div>
          <div class="row gap">
            <?= copy_button($d['udid']) ?>
            <a class="btn small" href="<?= h(url('devices', ['edit' => $d['id']])) ?>">Edit</a>
            <form method="post" action="<?= h(url('device_delete', ['id' => $d['id']])) ?>" data-confirm="Remove <?= h($d['name'] ?: $d['udid']) ?>?">
              <?= Auth::csrfField() ?>
              <button class="btn small danger" type="submit">Remove</button>
            </form>
          </div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
