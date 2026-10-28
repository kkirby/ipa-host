<?php
declare(strict_types=1);
defined('IPAHOST') || exit;

function h(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Relative URL to a route, e.g. url('build', ['id' => 3]). */
function url(string $page = '', array $params = []): string
{
    $q = $page !== '' ? ['p' => $page] + $params : $params;
    return 'index.php' . ($q ? '?' . http_build_query($q) : '');
}

/** Absolute URL to a route, using the configured base URL. */
function abs_url(string $page = '', array $params = []): string
{
    return base_url() . '/' . url($page, $params);
}

function base_url(): string
{
    $configured = (string)Settings::get('base_url', '');
    return rtrim($configured !== '' ? $configured : detect_base_url(), '/');
}

function detect_base_url(): string
{
    $scheme = is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    if (TRUST_PROXY && !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
    }
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $scheme . '://' . $host . rtrim($dir, '/');
}

function base_path(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return rtrim($dir, '/') . '/';
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    if (TRUST_PROXY && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return false;
}

function client_ip(): string
{
    if (TRUST_PROXY) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $hdr) {
            if (!empty($_SERVER[$hdr])) {
                return trim(explode(',', (string)$_SERVER[$hdr])[0]);
            }
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function redirect(string $to, int $status = 303): never
{
    header('Location: ' . $to, true, $status);
    exit;
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post_str(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function get_str(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? $v : $default;
}

function get_int(string $key): int
{
    $v = $_GET[$key] ?? ($_POST[$key] ?? 0);
    return is_scalar($v) ? (int)$v : 0;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function view(string $name, array $vars = [], ?string $layout = 'layout'): never
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require APP_ROOT . '/views/' . $name . '.php';
    $content = ob_get_clean();
    if ($layout === null) {
        echo $content;
    } else {
        require APP_ROOT . '/views/' . $layout . '.php';
    }
    exit;
}

function abort(int $status, string $message): never
{
    http_response_code($status);
    view('error', ['status' => $status, 'message' => $message, 'title' => 'Error'], 'layout_public');
}

function format_bytes(int|float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return ($i === 0 ? (string)(int)$bytes : number_format($bytes, $bytes < 10 ? 2 : 1)) . ' ' . $units[$i];
}

function time_ago(?int $ts): string
{
    if (!$ts) {
        return 'never';
    }
    $d = time() - $ts;
    $future = $d < 0;
    $d = abs($d);
    $out = match (true) {
        $d < 60 => 'just now',
        $d < 3600 => intdiv($d, 60) . ' min',
        $d < 86400 => intdiv($d, 3600) . ' h',
        $d < 86400 * 30 => intdiv($d, 86400) . ' d',
        $d < 86400 * 365 => intdiv($d, 86400 * 30) . ' mo',
        default => intdiv($d, 86400 * 365) . ' y',
    };
    if ($out === 'just now') {
        return $out;
    }
    return $future ? "in $out" : "$out ago";
}

function format_date(?int $ts): string
{
    return $ts ? date('Y-m-d H:i', $ts) : '—';
}

/** Parse php.ini shorthand like "128M" to bytes. */
function ini_bytes(string|false $v): int
{
    if ($v === false || $v === '') {
        return 0;
    }
    $v = trim($v);
    $n = (float)$v;
    return (int)match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}

function to_utf8(string $s, string $from): string
{
    if (function_exists('mb_convert_encoding')) {
        return (string)mb_convert_encoding($s, 'UTF-8', $from);
    }
    return (string)iconv($from, 'UTF-8//IGNORE', $s);
}

function log_event(string $kind, ?int $buildId = null, string $detail = ''): void
{
    Db::insert('events', [
        'kind' => $kind,
        'build_id' => $buildId,
        'detail' => mb_substr_safe($detail, 500),
        'ip' => client_ip(),
        'ua' => mb_substr_safe((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 300),
        'created_at' => time(),
    ]);
}

function mb_substr_safe(string $s, int $len): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
}

function build_label(array $b): string
{
    $v = (string)($b['version'] ?? '');
    $n = (string)($b['build'] ?? '');
    if ($v === '' && $n === '') {
        return 'unknown version';
    }
    return $n !== '' && $n !== $v ? "$v ($n)" : $v;
}

function profile_badge(?array $profile): array
{
    // Returns [cssClass, label, tooltip]
    if (!$profile) {
        return ['bad', 'No profile', 'No embedded.mobileprovision — this IPA will not install over the air.'];
    }
    $types = [
        'adhoc' => 'Ad Hoc',
        'development' => 'Development',
        'enterprise' => 'Enterprise',
        'appstore' => 'App Store',
    ];
    $label = $types[$profile['type']] ?? $profile['type'];
    $exp = $profile['expires'] ?? null;
    if ($profile['type'] === 'appstore') {
        return ['bad', $label, 'App Store / TestFlight-signed builds cannot be installed over the air.'];
    }
    if ($exp && $exp < time()) {
        return ['bad', "$label · expired", 'Profile expired ' . format_date($exp)];
    }
    if ($exp && $exp < time() + 14 * 86400) {
        return ['warn', "$label · expires " . time_ago($exp), 'Profile expires ' . format_date($exp)];
    }
    return ['ok', $label, $exp ? 'Profile expires ' . format_date($exp) : ''];
}

function install_link(array $build): string
{
    $ttl = max(1, (int)Settings::get('link_ttl_hours', 24)) * 3600;
    $token = Tokens::sign('install', (string)$build['id'], time() + $ttl);
    $manifest = abs_url('manifest', ['b' => $build['id'], 't' => $token]);
    return 'itms-services://?action=download-manifest&url=' . rawurlencode($manifest);
}

/** Short-lived public install page URL, used for QR codes. */
function qr_link(array $build): string
{
    $ttl = max(1, (int)Settings::get('link_ttl_hours', 24)) * 3600;
    $token = Tokens::sign('page', (string)$build['id'], time() + $ttl);
    return abs_url('get', ['b' => $build['id'], 't' => $token]);
}

function ensure_data_dir(): void
{
    foreach (['', '/ipas', '/icons', '/chunks'] as $sub) {
        $dir = DATA_DIR . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            http_response_code(500);
            exit('Cannot create data directory: ' . htmlspecialchars($dir) . '. Make it writable by the web server.');
        }
    }
    $ht = DATA_DIR . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
    if (!is_file(DATA_DIR . '/index.html')) {
        @file_put_contents(DATA_DIR . '/index.html', '');
    }
}

function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/* ---------------------------------------------------------------- view helpers */

function app_icon(?string $icon, string $name, string $size = 'md'): string
{
    if ($icon) {
        return '<img class="icon icon-' . h($size) . '" src="' . h(url('icon', ['f' => $icon])) . '" alt="" loading="lazy">';
    }
    $letter = function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1)) : strtoupper(substr($name, 0, 1));
    $hue = hexdec(substr(md5($name), 0, 2)) * 360 / 256;
    return '<div class="icon icon-' . h($size) . ' icon-placeholder" style="--hue:' . (int)$hue . '">' . h($letter) . '</div>';
}

/** Install button: opens itms-services on iOS, a QR code elsewhere (see app.js). */
function install_button(array $build, string $label = 'Install', string $class = 'btn primary'): string
{
    return '<a class="' . h($class) . ' install" href="' . h(install_link($build)) . '"'
        . ' data-qr="' . h(qr_link($build)) . '"'
        . ' data-name="' . h($build['name'] . ' ' . build_label($build)) . '">' . h($label) . '</a>';
}

function copy_button(string $text, string $label = 'Copy'): string
{
    return '<button type="button" class="btn small" data-copy="' . h($text) . '">' . h($label) . '</button>';
}

function nav_active(string ...$pages): string
{
    return in_array($_GET['p'] ?? 'home', $pages, true) ? ' class="active"' : '';
}
