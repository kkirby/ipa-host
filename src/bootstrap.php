<?php
declare(strict_types=1);

define('IPAHOST', true);
define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

$missing = [];
if (PHP_VERSION_ID < 80100) {
    $missing[] = 'PHP 8.1 or newer (running ' . PHP_VERSION . ')';
}
foreach (['zip', 'pdo_sqlite', 'dom', 'zlib', 'session'] as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = "PHP extension: $ext";
    }
}
if (!extension_loaded('mbstring') && !extension_loaded('iconv')) {
    $missing[] = 'PHP extension: mbstring or iconv';
}
if ($missing) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>IPA Host – requirements</title>'
        . '<body style="font-family:system-ui;max-width:40rem;margin:3rem auto;padding:0 1rem">'
        . '<h1>Server requirements not met</h1><p>Ask your host to enable:</p><ul>';
    foreach ($missing as $m) {
        echo '<li>' . htmlspecialchars($m) . '</li>';
    }
    echo '</ul></body>';
    exit;
}

$config = [];
if (is_file(APP_ROOT . '/config.php')) {
    $loaded = require APP_ROOT . '/config.php';
    if (is_array($loaded)) {
        $config = $loaded;
    }
}
define('DATA_DIR', rtrim((string)($config['data_dir'] ?? APP_ROOT . '/data'), '/\\'));
define('TRUST_PROXY', (bool)($config['trust_proxy'] ?? false));
define('DEBUG', (bool)($config['debug'] ?? false));

ini_set('display_errors', DEBUG ? '1' : '0');
error_reporting(E_ALL);

require __DIR__ . '/helpers.php';
require __DIR__ . '/Db.php';
require __DIR__ . '/Settings.php';
require __DIR__ . '/Tokens.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/Plist.php';
require __DIR__ . '/PngFix.php';
require __DIR__ . '/IpaParser.php';
require __DIR__ . '/Store.php';
require __DIR__ . '/routes.php';

ensure_data_dir();
