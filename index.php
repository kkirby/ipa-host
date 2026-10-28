<?php
/**
 * IPA Host — self-hosted over-the-air iOS app installs.
 * Upload this folder to a PHP 8.1+ host with HTTPS and open it in a browser.
 */
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

set_exception_handler(function (Throwable $e): void {
    error_log('[ipa-host] ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
    }
    $wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || isset($_SERVER['HTTP_X_CSRF_TOKEN'])
        || ($_GET['p'] ?? '') === 'api';
    $message = DEBUG ? get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() : 'Something went wrong. Check the PHP error log.';
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
        return;
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Error</title><body style="font-family:system-ui;max-width:40rem;margin:3rem auto;padding:0 1rem">'
        . '<h1>Error</h1><p>' . htmlspecialchars($message) . '</p></body>';
});

dispatch();
