<?php
declare(strict_types=1);
defined('IPAHOST') || exit;

const PUBLIC_ROUTES = ['manifest', 'ipa', 'icon', 'get', 'enroll_cb', 'enrolled', 'api'];
const AUTH_ROUTES = [
    'home', 'app', 'build', 'upload', 'download', 'build_notes', 'build_delete', 'app_delete',
    'shares', 'share_create', 'share_revoke', 'devices', 'device_save', 'device_delete',
    'devices_export', 'enroll', 'settings', 'settings_save', 'password', 'api_token',
    'activity', 'activity_clear', 'logout', 'logout_all',
];
const POST_ONLY = [
    'upload', 'build_notes', 'build_delete', 'app_delete', 'share_create', 'share_revoke',
    'device_save', 'device_delete', 'settings_save', 'password', 'api_token', 'activity_clear',
    'logout', 'logout_all',
];

function dispatch(): void
{
    security_headers();
    $p = get_str('p', 'home');

    if (in_array($p, PUBLIC_ROUTES, true)) {
        if (!Settings::isSetUp()) {
            abort(404, 'Not found.');
        }
        ('route_' . $p)();
        return;
    }

    Auth::startSession();
    if (!Settings::isSetUp()) {
        route_setup();
    }
    if ($p === 'login') {
        route_login();
    }
    if (!in_array($p, AUTH_ROUTES, true)) {
        abort(404, 'Page not found.');
    }
    Auth::requireLogin();
    if (in_array($p, POST_ONLY, true) && !is_post()) {
        abort(405, 'Method not allowed.');
    }
    if (is_post()) {
        Auth::verifyCsrf();
    }
    ('route_' . $p)();
}

/* ---------------------------------------------------------------- setup / auth */

function route_setup(): never
{
    $errors = [];
    $values = [
        'site_title' => 'IPA Host',
        'base_url' => detect_base_url(),
    ];
    if (is_post()) {
        Auth::verifyCsrf();
        $values['site_title'] = post_str('site_title') ?: 'IPA Host';
        $values['base_url'] = rtrim(post_str('base_url'), '/');
        $password = (string)($_POST['password'] ?? '');
        if (strlen($password) < 10) {
            $errors[] = 'Use a password of at least 10 characters.';
        }
        if ($password !== (string)($_POST['password_confirm'] ?? '')) {
            $errors[] = 'Passwords do not match.';
        }
        if (!preg_match('#^https?://[^\s/]+(/\S*)?$#i', $values['base_url'])) {
            $errors[] = 'Base URL must look like https://example.com/ipa';
        }
        if (!$errors) {
            Settings::secret();
            Settings::set('site_title', $values['site_title']);
            Settings::set('base_url', $values['base_url']);
            Settings::set('installed_at', (string)time());
            Settings::set('password_hash', password_hash($password, PASSWORD_DEFAULT));
            Auth::establish();
            log_event('setup');
            flash('ok', 'Setup complete. Upload your first IPA.');
            redirect(url());
        }
    }
    view('setup', [
        'title' => 'Setup',
        'errors' => $errors,
        'values' => $values,
        'checks' => server_checks(),
    ], 'layout_public');
}

function route_login(): never
{
    if (Auth::check()) {
        redirect(url());
    }
    $error = '';
    $next = get_str('next');
    if (is_post()) {
        Auth::verifyCsrf();
        $next = post_str('next');
        if (Auth::throttled(client_ip())) {
            $error = 'Too many failed attempts. Try again in 15 minutes.';
        } elseif (Auth::attempt((string)($_POST['password'] ?? ''), !empty($_POST['remember']))) {
            $target = (str_starts_with($next, '?') && !str_contains($next, "\n")) ? 'index.php' . $next : url();
            redirect($target);
        } else {
            $error = Auth::throttled(client_ip()) ? 'Too many failed attempts. Try again in 15 minutes.' : 'Wrong password.';
        }
    }
    view('login', ['title' => 'Sign in', 'error' => $error, 'next' => $next], 'layout_public');
}

function route_logout(): never
{
    Auth::logout();
    flash('ok', 'Signed out.');
    redirect(url('login'));
}

function route_logout_all(): never
{
    Auth::logout(true);
    flash('ok', 'Signed out on all devices.');
    redirect(url('login'));
}

/* ---------------------------------------------------------------- pages */

function route_home(): never
{
    $apps = Db::all('SELECT a.*, (SELECT COUNT(*) FROM builds WHERE app_id = a.id) AS build_count FROM apps a ORDER BY a.updated_at DESC');
    foreach ($apps as &$a) {
        $a['latest'] = Store::latestBuild((int)$a['id']);
    }
    unset($a);
    view('home', [
        'title' => 'Apps',
        'apps' => array_values(array_filter($apps, fn($a) => $a['latest'] !== null)),
        'stats' => Store::stats(),
        'chunkSize' => upload_chunk_size(),
        'deviceCount' => (int)Db::val('SELECT COUNT(*) FROM devices'),
    ]);
}

function route_app(): never
{
    $app = Db::one('SELECT * FROM apps WHERE id = ?', [get_int('id')]);
    if (!$app) {
        abort(404, 'App not found.');
    }
    $builds = array_map([Store::class, 'hydrate'], Db::all(
        'SELECT b.*, a.bundle_id, a.icon AS app_icon FROM builds b JOIN apps a ON a.id = b.app_id WHERE b.app_id = ? ORDER BY b.id DESC',
        [$app['id']]
    ));
    $shares = Db::all('SELECT * FROM shares WHERE app_id = ? AND build_id IS NULL ORDER BY id DESC', [$app['id']]);
    view('app', [
        'title' => $app['name'],
        'app' => $app,
        'builds' => $builds,
        'shares' => $shares,
        'totalSize' => array_sum(array_column($builds, 'size')),
    ]);
}

function route_build(): never
{
    $build = Store::build(get_int('id'));
    if (!$build) {
        abort(404, 'Build not found.');
    }
    $devices = Db::all('SELECT * FROM devices ORDER BY name COLLATE NOCASE');
    $provisioned = array_map('strtoupper', $build['profile']['devices'] ?? []);
    foreach ($devices as &$d) {
        $d['covered'] = !empty($build['profile']['all_devices']) || in_array(strtoupper($d['udid']), $provisioned, true);
    }
    unset($d);
    $knownUdids = array_map('strtoupper', array_column($devices, 'udid'));
    $unknownDevices = array_values(array_filter($provisioned, fn($u) => !in_array($u, $knownUdids, true)));

    $info = json_decode((string)$build['info'], true) ?: [];
    ksort($info);
    view('build', [
        'title' => $build['name'] . ' ' . build_label($build),
        'build' => $build,
        'app' => Db::one('SELECT * FROM apps WHERE id = ?', [$build['app_id']]),
        'devices' => $devices,
        'unknownDevices' => $unknownDevices,
        'shares' => Db::all('SELECT * FROM shares WHERE build_id = ? ORDER BY id DESC', [$build['id']]),
        'events' => Db::all('SELECT * FROM events WHERE build_id = ? ORDER BY id DESC LIMIT 25', [$build['id']]),
        'infoJson' => json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'isLatest' => (int)Db::val('SELECT MAX(id) FROM builds WHERE app_id = ?', [$build['app_id']]) === (int)$build['id'],
    ]);
}

function route_build_notes(): never
{
    $id = get_int('id');
    Db::update('builds', ['notes' => mb_substr_safe(post_str('notes'), 10000)], 'id = ?', [$id]);
    flash('ok', 'Notes saved.');
    redirect(url('build', ['id' => $id]));
}

function route_build_delete(): never
{
    $build = Store::build(get_int('id'));
    if (!$build) {
        abort(404, 'Build not found.');
    }
    Store::deleteBuild((int)$build['id']);
    flash('ok', 'Deleted ' . $build['name'] . ' ' . build_label($build) . '.');
    $appExists = Db::val('SELECT id FROM apps WHERE id = ?', [$build['app_id']]);
    redirect($appExists ? url('app', ['id' => $build['app_id']]) : url());
}

function route_app_delete(): never
{
    $app = Db::one('SELECT * FROM apps WHERE id = ?', [get_int('id')]);
    if ($app) {
        Store::deleteApp((int)$app['id']);
        flash('ok', 'Deleted ' . $app['name'] . ' and all its builds.');
    }
    redirect(url());
}

function route_download(): never
{
    $build = Store::build(get_int('id'));
    if (!$build || !is_file(Store::ipaPath($build))) {
        abort(404, 'IPA file not found.');
    }
    if (!isset($_SERVER['HTTP_RANGE']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        Db::run('UPDATE builds SET downloads = downloads + 1 WHERE id = ?', [$build['id']]);
        log_event('download', (int)$build['id'], $build['name'] . ' ' . build_label($build));
    }
    session_write_close();
    Store::send(Store::ipaPath($build), Store::ipaDownloadName($build));
    exit;
}

/* ---------------------------------------------------------------- chunked upload */

function upload_chunk_size(): int
{
    $max = 8 * 1024 * 1024;
    $post = ini_bytes(ini_get('post_max_size'));
    if ($post > 0) {
        $max = min($max, $post - 64 * 1024);
    }
    return max(256 * 1024, $max);
}

function route_upload(): never
{
    session_write_close();
    $step = get_str('step');
    try {
        match ($step) {
            'init' => upload_init(),
            'chunk' => upload_chunk(),
            'finish' => upload_finish(),
            'cancel' => upload_cancel(),
            default => json_out(['error' => 'Unknown upload step'], 400),
        };
    } catch (DuplicateBuildException $e) {
        json_out(['error' => $e->getMessage(), 'url' => url('build', ['id' => $e->buildId])], 409);
    } catch (RuntimeException $e) {
        json_out(['error' => $e->getMessage()], 422);
    }
}

function upload_row(): array
{
    $id = get_str('id');
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        json_out(['error' => 'Bad upload id'], 400);
    }
    $row = Db::one('SELECT * FROM uploads WHERE id = ?', [$id]);
    if (!$row) {
        json_out(['error' => 'Upload not found or expired. Start again.'], 404);
    }
    return $row;
}

function upload_part_path(string $id): string
{
    return DATA_DIR . '/chunks/' . $id . '.part';
}

function upload_init(): never
{
    foreach (Db::all('SELECT id FROM uploads WHERE created_at < ?', [time() - 86400]) as $stale) {
        @unlink(upload_part_path($stale['id']));
        Db::run('DELETE FROM uploads WHERE id = ?', [$stale['id']]);
    }
    $name = post_str('name');
    $size = (int)post_str('size');
    if (!preg_match('/\.ipa$/i', $name)) {
        json_out(['error' => 'Only .ipa files are accepted.'], 422);
    }
    if ($size <= 0) {
        json_out(['error' => 'File is empty.'], 422);
    }
    $free = @disk_free_space(DATA_DIR);
    if ($free !== false && $free < $size * 2 + 50 * 1024 * 1024) {
        json_out(['error' => 'Not enough disk space on the server (' . format_bytes($free) . ' free).'], 507);
    }
    $id = bin2hex(random_bytes(16));
    touch(upload_part_path($id));
    Db::insert('uploads', [
        'id' => $id,
        'name' => mb_substr_safe($name, 255),
        'size' => $size,
        'notes' => mb_substr_safe(post_str('notes'), 10000),
        'created_at' => time(),
    ]);
    json_out(['id' => $id, 'chunkSize' => upload_chunk_size()]);
}

function upload_chunk(): never
{
    $row = upload_row();
    $offset = (int)get_str('offset', '-1');
    $path = upload_part_path($row['id']);
    $fh = @fopen($path, 'c+b');
    if (!$fh) {
        json_out(['error' => 'Cannot write upload chunk'], 500);
    }
    flock($fh, LOCK_EX);
    $have = fstat($fh)['size'];
    if ($offset < 0 || $offset > $have) {
        flock($fh, LOCK_UN);
        fclose($fh);
        json_out(['error' => 'Out of order chunk', 'received' => $have], 409);
    }
    ftruncate($fh, $offset);
    fseek($fh, $offset);
    $in = fopen('php://input', 'rb');
    stream_copy_to_stream($in, $fh);
    fclose($in);
    fflush($fh);
    $now = fstat($fh)['size'];
    flock($fh, LOCK_UN);
    fclose($fh);
    if ($now > (int)$row['size']) {
        @unlink($path);
        Db::run('DELETE FROM uploads WHERE id = ?', [$row['id']]);
        json_out(['error' => 'Received more data than declared'], 400);
    }
    json_out(['received' => $now]);
}

function upload_finish(): never
{
    $row = upload_row();
    $path = upload_part_path($row['id']);
    clearstatcache(true, $path);
    $have = is_file($path) ? filesize($path) : 0;
    if ($have !== (int)$row['size']) {
        json_out(['error' => "Upload incomplete ($have of {$row['size']} bytes)", 'received' => $have], 409);
    }
    Db::run('DELETE FROM uploads WHERE id = ?', [$row['id']]);
    $build = Store::ingest($path, $row['name'], $row['notes']);
    json_out([
        'ok' => true,
        'build' => ['id' => $build['id'], 'name' => $build['name'], 'version' => build_label($build)],
        'url' => url('build', ['id' => $build['id']]),
    ]);
}

function upload_cancel(): never
{
    $row = upload_row();
    @unlink(upload_part_path($row['id']));
    Db::run('DELETE FROM uploads WHERE id = ?', [$row['id']]);
    json_out(['ok' => true]);
}

/* ---------------------------------------------------------------- shares */

function route_shares(): never
{
    $shares = Db::all(
        'SELECT s.*, a.name AS app_name, a.icon AS app_icon, b.version, b.build
         FROM shares s JOIN apps a ON a.id = s.app_id LEFT JOIN builds b ON b.id = s.build_id
         ORDER BY s.id DESC'
    );
    view('shares', ['title' => 'Share links', 'shares' => $shares, 'highlight' => get_int('new')]);
}

function create_share(int $appId, ?int $buildId, string $label, int $hours): array
{
    $id = Db::insert('shares', [
        'token' => Tokens::random(18),
        'app_id' => $appId,
        'build_id' => $buildId,
        'label' => mb_substr_safe($label, 200),
        'expires_at' => $hours > 0 ? time() + $hours * 3600 : null,
        'created_at' => time(),
    ]);
    return Db::one('SELECT * FROM shares WHERE id = ?', [$id]);
}

function share_url(array $share): string
{
    return abs_url('get', ['s' => $share['token']]);
}

function route_share_create(): never
{
    $hours = max(0, min(24 * 366, (int)post_str('hours', '168')));
    $buildId = (int)post_str('build_id');
    $appId = (int)post_str('app_id');
    if ($buildId > 0) {
        $build = Store::build($buildId) ?? abort(404, 'Build not found.');
        $appId = (int)$build['app_id'];
    } elseif (!Db::val('SELECT id FROM apps WHERE id = ?', [$appId])) {
        abort(404, 'App not found.');
    }
    $share = create_share($appId, $buildId > 0 ? $buildId : null, post_str('label'), $hours);
    log_event('share_create', $buildId > 0 ? $buildId : null, post_str('label'));
    flash('ok', 'Share link created.');
    redirect(url('shares', ['new' => $share['id']]));
}

function route_share_revoke(): never
{
    Db::run('DELETE FROM shares WHERE id = ?', [get_int('id')]);
    flash('ok', 'Share link revoked.');
    $back = post_str('back');
    redirect(str_starts_with($back, '?') ? 'index.php' . $back : url('shares'));
}

/* ---------------------------------------------------------------- devices */

function route_devices(): never
{
    view('devices', [
        'title' => 'Devices',
        'devices' => Db::all('SELECT * FROM devices ORDER BY name COLLATE NOCASE, id'),
        'edit' => get_int('edit'),
    ]);
}

function normalize_udid(string $udid): ?string
{
    $udid = strtoupper(trim($udid));
    return preg_match('/^([0-9A-F]{40}|[0-9A-F]{8}-[0-9A-F]{16}|[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12})$/', $udid) ? $udid : null;
}

function route_device_save(): never
{
    $id = (int)post_str('id');
    $name = mb_substr_safe(post_str('name'), 100);
    $udid = normalize_udid(post_str('udid'));
    if ($udid === null) {
        flash('err', 'That does not look like a valid UDID (40 hex characters, or 8-16 format like 00008030-001A2B3C4D5E802E).');
        redirect(url('devices'));
    }
    $existing = Db::one('SELECT id FROM devices WHERE udid = ?', [$udid]);
    if ($existing && (int)$existing['id'] !== $id) {
        flash('err', 'That UDID is already registered.');
        redirect(url('devices'));
    }
    if ($id > 0) {
        Db::update('devices', ['name' => $name, 'udid' => $udid, 'updated_at' => time()], 'id = ?', [$id]);
        flash('ok', 'Device updated.');
    } else {
        Db::insert('devices', ['udid' => $udid, 'name' => $name, 'created_at' => time(), 'updated_at' => time()]);
        flash('ok', 'Device added.');
    }
    redirect(url('devices'));
}

function route_device_delete(): never
{
    Db::run('DELETE FROM devices WHERE id = ?', [get_int('id')]);
    flash('ok', 'Device removed.');
    redirect(url('devices'));
}

function route_devices_export(): never
{
    // Apple Developer portal "Register Multiple Devices" format.
    $lines = ["Device ID\tDevice Name\tDevice Platform"];
    foreach (Db::all('SELECT * FROM devices ORDER BY name COLLATE NOCASE') as $d) {
        $name = preg_replace('/[\t\r\n]+/', ' ', $d['name'] ?: ($d['product'] ?: 'Device ' . $d['id']));
        $lines[] = $d['udid'] . "\t" . $name . "\tios";
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="devices.txt"');
    echo implode("\n", $lines) . "\n";
    exit;
}

function route_enroll(): never
{
    $token = Tokens::sign('enroll', 'udid', time() + 3600);
    $profile = [
        'PayloadContent' => [
            'URL' => abs_url('enroll_cb', ['t' => $token]),
            'DeviceAttributes' => ['UDID', 'PRODUCT', 'VERSION', 'SERIAL', 'DEVICE_NAME'],
        ],
        'PayloadOrganization' => (string)Settings::get('site_title'),
        'PayloadDisplayName' => 'Register device with ' . Settings::get('site_title'),
        'PayloadDescription' => 'Sends this device\'s UDID to ' . parse_url(base_url(), PHP_URL_HOST) . ' so it can be added to your provisioning profile. Nothing is installed.',
        'PayloadIdentifier' => 'ipahost.udid.' . substr(hash('sha256', base_url()), 0, 12),
        'PayloadUUID' => strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4))),
        'PayloadType' => 'Profile Service',
        'PayloadVersion' => 1,
    ];
    header('Content-Type: application/x-apple-aspen-config');
    header('Content-Disposition: attachment; filename="register-device.mobileconfig"');
    header('Cache-Control: no-store');
    echo Plist::toXml($profile);
    exit;
}

function route_enroll_cb(): never
{
    if (!Tokens::verify('enroll', 'udid', get_str('t'))) {
        abort(403, 'This registration profile has expired. Download a fresh one from the Devices page.');
    }
    $body = (string)file_get_contents('php://input', false, null, 0, 1024 * 1024);
    $start = strpos($body, '<?xml');
    $end = $start === false ? false : strpos($body, '</plist>', $start);
    if ($start === false || $end === false) {
        abort(400, 'No device data received.');
    }
    $data = Plist::parseXml(substr($body, $start, $end - $start + 8));
    $udid = is_array($data) ? normalize_udid((string)($data['UDID'] ?? '')) : null;
    if ($udid === null) {
        abort(400, 'Device did not send a valid UDID.');
    }
    $fields = [
        'product' => mb_substr_safe((string)($data['PRODUCT'] ?? ''), 50),
        'os_version' => mb_substr_safe((string)($data['VERSION'] ?? ''), 50),
        'serial' => mb_substr_safe((string)($data['SERIAL'] ?? ''), 50),
        'updated_at' => time(),
    ];
    $existing = Db::one('SELECT * FROM devices WHERE udid = ?', [$udid]);
    if ($existing) {
        Db::update('devices', $fields, 'id = ?', [$existing['id']]);
    } else {
        $deviceName = trim((string)($data['DEVICE_NAME'] ?? ''));
        Db::insert('devices', $fields + [
            'udid' => $udid,
            'name' => mb_substr_safe($deviceName !== '' ? $deviceName : ($fields['product'] ?: 'iOS device'), 100),
            'created_at' => time(),
        ]);
    }
    log_event('enroll', null, $udid . ' ' . $fields['product']);
    // iOS opens this redirect in Safari once the profile finishes.
    redirect(abs_url('enrolled', ['u' => $udid, 't' => Tokens::sign('enrolled', $udid, time() + 3600)]), 301);
}

function route_enrolled(): never
{
    $udid = get_str('u');
    if (!Tokens::verify('enrolled', $udid, get_str('t'))) {
        abort(403, 'Link expired.');
    }
    $device = Db::one('SELECT * FROM devices WHERE udid = ?', [$udid]) ?? abort(404, 'Device not found.');
    view('enrolled', ['title' => 'Device registered', 'device' => $device], 'layout_public');
}

/* ---------------------------------------------------------------- settings / activity */

function server_checks(): array
{
    $post = ini_get('post_max_size');
    $upload = ini_get('upload_max_filesize');
    $free = @disk_free_space(DATA_DIR);
    return [
        ['HTTPS', is_https() || str_starts_with((string)Settings::get('base_url', ''), 'https://'), 'iOS refuses over-the-air installs unless the manifest and IPA are served over HTTPS with a trusted certificate.'],
        ['Data directory writable', is_writable(DATA_DIR), DATA_DIR],
        ['Data directory outside web root', !str_starts_with(realpath(DATA_DIR) ?: DATA_DIR, realpath(APP_ROOT) ?: APP_ROOT), 'Optional. data/ ships with a deny-all .htaccess; on nginx, see README.'],
        ['PHP version', PHP_VERSION_ID >= 80100, PHP_VERSION],
        ['post_max_size', true, "$post (browser uploads are chunked, so this only limits API uploads)"],
        ['upload_max_filesize', true, "$upload (API uploads only)"],
        ['max_execution_time', true, ini_get('max_execution_time') . 's'],
        ['Free disk space', $free === false || $free > 1024 ** 3, $free === false ? 'unknown' : format_bytes($free)],
    ];
}

function route_settings(): never
{
    $newToken = $_SESSION['new_api_token'] ?? null;
    unset($_SESSION['new_api_token']);
    view('settings', [
        'title' => 'Settings',
        'checks' => server_checks(),
        'newToken' => $newToken,
        'hasToken' => (string)Settings::get('api_token_hash', '') !== '',
        'tokenHint' => (string)Settings::get('api_token_hint', ''),
        'sessions' => (int)Db::val('SELECT COUNT(*) FROM remember_tokens WHERE expires_at > ?', [time()]),
    ]);
}

function route_settings_save(): never
{
    $baseUrl = rtrim(post_str('base_url'), '/');
    if ($baseUrl !== '' && !preg_match('#^https?://[^\s/]+(/\S*)?$#i', $baseUrl)) {
        flash('err', 'Base URL must look like https://example.com/ipa');
        redirect(url('settings'));
    }
    Settings::set('site_title', post_str('site_title') ?: 'IPA Host');
    Settings::set('base_url', $baseUrl);
    Settings::set('link_ttl_hours', (string)max(1, min(24 * 365, (int)post_str('link_ttl_hours', '24'))));
    $keep = max(0, (int)post_str('keep_builds', '0'));
    Settings::set('keep_builds', (string)$keep);
    $pruned = 0;
    if ($keep > 0) {
        foreach (Db::all('SELECT id FROM apps') as $a) {
            $pruned += Store::prune((int)$a['id']);
        }
    }
    flash('ok', 'Settings saved.' . ($pruned ? " Removed $pruned old build(s)." : ''));
    redirect(url('settings'));
}

function route_password(): never
{
    $current = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    if (!password_verify($current, (string)Settings::get('password_hash'))) {
        flash('err', 'Current password is wrong.');
    } elseif (strlen($new) < 10) {
        flash('err', 'New password must be at least 10 characters.');
    } elseif ($new !== (string)($_POST['confirm'] ?? '')) {
        flash('err', 'New passwords do not match.');
    } else {
        Settings::set('password_hash', password_hash($new, PASSWORD_DEFAULT));
        Db::run('DELETE FROM remember_tokens');
        Auth::establish();
        log_event('password_change');
        flash('ok', 'Password changed. Other devices have been signed out.');
    }
    redirect(url('settings'));
}

function route_api_token(): never
{
    if (post_str('action') === 'revoke') {
        Settings::set('api_token_hash', '');
        Settings::set('api_token_hint', '');
        flash('ok', 'API token revoked.');
    } else {
        $token = 'iph_' . Tokens::random(24);
        Settings::set('api_token_hash', hash('sha256', $token));
        Settings::set('api_token_hint', substr($token, -4));
        $_SESSION['new_api_token'] = $token;
        flash('ok', 'New API token created. Copy it now — it will not be shown again.');
    }
    redirect(url('settings'));
}

function route_activity(): never
{
    $page = max(1, get_int('page'));
    $per = 100;
    $kind = get_str('kind');
    $where = $kind !== '' ? 'WHERE e.kind = ?' : '';
    $params = $kind !== '' ? [$kind] : [];
    $events = Db::all(
        "SELECT e.*, b.name AS build_name, b.version, b.build FROM events e LEFT JOIN builds b ON b.id = e.build_id
         $where ORDER BY e.id DESC LIMIT $per OFFSET " . (($page - 1) * $per),
        $params
    );
    view('activity', [
        'title' => 'Activity',
        'events' => $events,
        'page' => $page,
        'kind' => $kind,
        'kinds' => array_column(Db::all('SELECT DISTINCT kind FROM events ORDER BY kind'), 'kind'),
        'hasMore' => count($events) === $per,
    ]);
}

function route_activity_clear(): never
{
    Db::run('DELETE FROM events');
    flash('ok', 'Activity log cleared.');
    redirect(url('activity'));
}

/* ---------------------------------------------------------------- public endpoints */

function route_manifest(): never
{
    $id = get_int('b');
    if (!Tokens::verify('install', (string)$id, get_str('t'))) {
        abort(403, 'Install link expired. Reload the page and tap Install again.');
    }
    $build = Store::build($id) ?? abort(404, 'Build not found.');
    $assets = [[
        'kind' => 'software-package',
        'url' => abs_url('ipa', ['b' => $id, 't' => get_str('t')]),
    ]];
    if ($build['icon']) {
        $iconUrl = abs_url('icon', ['f' => $build['icon']]);
        $assets[] = ['kind' => 'display-image', 'needs-shine' => false, 'url' => $iconUrl];
        $assets[] = ['kind' => 'full-size-image', 'needs-shine' => false, 'url' => $iconUrl];
    }
    $manifest = [
        'items' => [[
            'assets' => $assets,
            'metadata' => [
                'bundle-identifier' => $build['bundle_id'],
                'bundle-version' => $build['version'] !== '' ? $build['version'] : $build['build'],
                'kind' => 'software',
                'title' => $build['name'],
            ],
        ]],
    ];
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: no-store');
    echo Plist::toXml($manifest);
    exit;
}

function route_ipa(): never
{
    $id = get_int('b');
    if (!Tokens::verify('install', (string)$id, get_str('t'))) {
        abort(403, 'Install link expired.');
    }
    $build = Store::build($id);
    if (!$build || !is_file(Store::ipaPath($build))) {
        abort(404, 'IPA not found.');
    }
    $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($range === '' || str_starts_with($range, 'bytes=0-'))) {
        Db::run('UPDATE builds SET installs = installs + 1, last_installed_at = ? WHERE id = ?', [time(), $id]);
        log_event('install', $id, $build['name'] . ' ' . build_label($build));
    }
    Store::send(Store::ipaPath($build), Store::ipaDownloadName($build));
    exit;
}

function route_icon(): never
{
    $f = get_str('f');
    $path = DATA_DIR . '/icons/' . $f;
    if (!preg_match('/^[a-f0-9]{32}\.png$/', $f) || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($path);
    exit;
}

function route_get(): never
{
    $share = null;
    if (($token = get_str('s')) !== '') {
        $share = Db::one('SELECT * FROM shares WHERE token = ?', [$token]);
        if (!$share) {
            abort(404, 'This link is invalid or has been revoked.');
        }
        if ($share['expires_at'] && $share['expires_at'] < time()) {
            abort(410, 'This link has expired.');
        }
        $build = $share['build_id'] ? Store::build((int)$share['build_id']) : Store::latestBuild((int)$share['app_id']);
        Db::run('UPDATE shares SET views = views + 1, last_used_at = ? WHERE id = ?', [time(), $share['id']]);
        if ($build) {
            log_event('share_view', (int)$build['id'], $share['label']);
        }
    } else {
        $id = get_int('b');
        if (!Tokens::verify('page', (string)$id, get_str('t'))) {
            abort(403, 'This link has expired. Generate a new QR code.');
        }
        $build = Store::build($id);
    }
    if (!$build) {
        abort(404, 'No build available.');
    }
    view('get', ['title' => 'Install ' . $build['name'], 'build' => $build, 'share' => $share], 'layout_public');
}

/* ---------------------------------------------------------------- API */

function route_api(): never
{
    if (Auth::throttled(client_ip())) {
        json_out(['error' => 'Too many failed attempts. Try again later.'], 429);
    }
    if (!Auth::checkApiToken()) {
        Auth::recordFailure();
        json_out(['error' => 'Missing or invalid API token. Send "Authorization: Bearer <token>".'], 401);
    }
    $action = get_str('action');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    try {
        match ("$method $action") {
            'GET apps' => api_apps(),
            'GET builds' => api_builds(),
            'POST upload' => api_upload(),
            'POST share' => api_share(),
            'POST delete' => api_delete(),
            default => json_out(['error' => "Unknown action. Use GET apps|builds, POST upload|share|delete."], 404),
        };
    } catch (DuplicateBuildException $e) {
        json_out(['error' => $e->getMessage(), 'build' => api_build_json(Store::build($e->buildId))], 409);
    } catch (RuntimeException $e) {
        json_out(['error' => $e->getMessage()], 422);
    }
}

function api_build_json(?array $b): ?array
{
    if (!$b) {
        return null;
    }
    return [
        'id' => (int)$b['id'],
        'app_id' => (int)$b['app_id'],
        'bundle_id' => $b['bundle_id'],
        'name' => $b['name'],
        'version' => $b['version'],
        'build' => $b['build'],
        'size' => (int)$b['size'],
        'sha256' => $b['sha256'],
        'notes' => $b['notes'],
        'profile_type' => $b['profile']['type'] ?? null,
        'profile_expires' => isset($b['profile']['expires']) ? gmdate('c', (int)$b['profile']['expires']) : null,
        'uploaded_at' => gmdate('c', (int)$b['created_at']),
        'installs' => (int)$b['installs'],
        'page_url' => abs_url('build', ['id' => $b['id']]),
        'install_page_url' => qr_link($b),
        'itms_url' => install_link($b),
    ];
}

function api_apps(): never
{
    $out = [];
    foreach (Db::all('SELECT * FROM apps ORDER BY updated_at DESC') as $a) {
        $out[] = [
            'id' => (int)$a['id'],
            'bundle_id' => $a['bundle_id'],
            'name' => $a['name'],
            'builds' => (int)Db::val('SELECT COUNT(*) FROM builds WHERE app_id = ?', [$a['id']]),
            'latest' => api_build_json(Store::latestBuild((int)$a['id'])),
        ];
    }
    json_out(['apps' => $out]);
}

function api_builds(): never
{
    $appId = get_int('app_id');
    $rows = Db::all('SELECT b.*, a.bundle_id, a.icon AS app_icon FROM builds b JOIN apps a ON a.id = b.app_id WHERE b.app_id = ? ORDER BY b.id DESC', [$appId]);
    json_out(['builds' => array_map(fn($r) => api_build_json(Store::hydrate($r)), $rows)]);
}

function api_upload(): never
{
    $f = $_FILES['ipa'] ?? null;
    if (!$f || is_array($f['error'])) {
        $hint = empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
            ? ' The request was larger than post_max_size (' . ini_get('post_max_size') . ').'
            : ' Send it as multipart field "ipa".';
        json_out(['error' => 'No IPA received.' . $hint], 400);
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $msg = match ($f['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds upload_max_filesize (' . ini_get('upload_max_filesize') . ').',
            UPLOAD_ERR_PARTIAL => 'Upload was interrupted.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Server could not write the temporary file.',
            default => 'Upload failed (error ' . $f['error'] . ').',
        };
        json_out(['error' => $msg], 400);
    }
    $build = Store::ingest($f['tmp_name'], (string)$f['name'], mb_substr_safe(post_str('notes'), 10000));
    $response = ['build' => api_build_json($build)];
    if (($hours = post_str('share_hours')) !== '') {
        $share = create_share((int)$build['app_id'], (int)$build['id'], post_str('share_label', 'API upload'), max(0, (int)$hours));
        $response['share_url'] = share_url($share);
    }
    json_out($response, 201);
}

function api_share(): never
{
    $buildId = (int)post_str('build_id');
    $appId = (int)post_str('app_id');
    if ($buildId > 0) {
        $b = Store::build($buildId) ?? json_out(['error' => 'Build not found'], 404);
        $appId = (int)$b['app_id'];
    } elseif (!Db::val('SELECT id FROM apps WHERE id = ?', [$appId])) {
        json_out(['error' => 'Pass build_id or app_id'], 404);
    }
    $share = create_share($appId, $buildId > 0 ? $buildId : null, post_str('label', 'API'), max(0, (int)post_str('hours', '168')));
    json_out(['share_url' => share_url($share), 'expires_at' => $share['expires_at'] ? gmdate('c', (int)$share['expires_at']) : null], 201);
}

function api_delete(): never
{
    $b = Store::build((int)post_str('build_id')) ?? json_out(['error' => 'Build not found'], 404);
    Store::deleteBuild((int)$b['id']);
    json_out(['ok' => true]);
}
