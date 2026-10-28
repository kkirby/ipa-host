<?php
declare(strict_types=1);
defined('IPAHOST') || exit;

final class Settings
{
    private const DEFAULTS = [
        'site_title' => 'IPA Host',
        'base_url' => '',
        'keep_builds' => '0',
        'link_ttl_hours' => '24',
    ];

    private static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Db::all('SELECT k, v FROM settings') as $row) {
                self::$cache[$row['k']] = $row['v'];
            }
        }
        return self::$cache[$key] ?? self::DEFAULTS[$key] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        Db::run('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$key, $value]);
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    public static function isSetUp(): bool
    {
        return (string)self::get('password_hash', '') !== '';
    }

    public static function secret(): string
    {
        $s = (string)self::get('secret', '');
        if ($s === '') {
            $s = bin2hex(random_bytes(32));
            self::set('secret', $s);
        }
        return $s;
    }
}
