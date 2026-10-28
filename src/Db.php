<?php
declare(strict_types=1);
defined('IPAHOST') || exit;

final class Db
{
    private static ?PDO $pdo = null;

    private const MIGRATIONS = [
        1 => [
            'CREATE TABLE settings (k TEXT PRIMARY KEY, v TEXT)',
            'CREATE TABLE apps (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bundle_id TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                icon TEXT,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )',
            'CREATE TABLE builds (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                app_id INTEGER NOT NULL REFERENCES apps(id) ON DELETE CASCADE,
                name TEXT NOT NULL,
                version TEXT NOT NULL DEFAULT \'\',
                build TEXT NOT NULL DEFAULT \'\',
                min_os TEXT NOT NULL DEFAULT \'\',
                families TEXT NOT NULL DEFAULT \'\',
                file TEXT NOT NULL,
                original_name TEXT NOT NULL DEFAULT \'\',
                size INTEGER NOT NULL DEFAULT 0,
                sha256 TEXT NOT NULL,
                icon TEXT,
                notes TEXT NOT NULL DEFAULT \'\',
                profile TEXT,
                info TEXT,
                meta TEXT,
                installs INTEGER NOT NULL DEFAULT 0,
                downloads INTEGER NOT NULL DEFAULT 0,
                last_installed_at INTEGER,
                created_at INTEGER NOT NULL
            )',
            'CREATE INDEX builds_app ON builds(app_id, id)',
            'CREATE INDEX builds_sha ON builds(sha256)',
            'CREATE TABLE devices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                udid TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL DEFAULT \'\',
                product TEXT NOT NULL DEFAULT \'\',
                os_version TEXT NOT NULL DEFAULT \'\',
                serial TEXT NOT NULL DEFAULT \'\',
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )',
            'CREATE TABLE shares (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token TEXT NOT NULL UNIQUE,
                app_id INTEGER NOT NULL REFERENCES apps(id) ON DELETE CASCADE,
                build_id INTEGER REFERENCES builds(id) ON DELETE CASCADE,
                label TEXT NOT NULL DEFAULT \'\',
                expires_at INTEGER,
                views INTEGER NOT NULL DEFAULT 0,
                last_used_at INTEGER,
                created_at INTEGER NOT NULL
            )',
            'CREATE TABLE events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                kind TEXT NOT NULL,
                build_id INTEGER REFERENCES builds(id) ON DELETE SET NULL,
                detail TEXT NOT NULL DEFAULT \'\',
                ip TEXT NOT NULL DEFAULT \'\',
                ua TEXT NOT NULL DEFAULT \'\',
                created_at INTEGER NOT NULL
            )',
            'CREATE INDEX events_time ON events(created_at)',
            'CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, created_at INTEGER NOT NULL)',
            'CREATE INDEX login_attempts_ip ON login_attempts(ip, created_at)',
            'CREATE TABLE remember_tokens (selector TEXT PRIMARY KEY, hash TEXT NOT NULL, expires_at INTEGER NOT NULL)',
            'CREATE TABLE uploads (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                size INTEGER NOT NULL,
                notes TEXT NOT NULL DEFAULT \'\',
                created_at INTEGER NOT NULL
            )',
        ],
    ];

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $pdo = new PDO('sqlite:' . DATA_DIR . '/ipahost.sqlite', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 10000');
            self::$pdo = $pdo;
            self::migrate();
        }
        return self::$pdo;
    }

    private static function migrate(): void
    {
        $pdo = self::$pdo;
        $current = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        foreach (self::MIGRATIONS as $version => $statements) {
            if ($version <= $current) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                foreach ($statements as $sql) {
                    $pdo->exec($sql);
                }
                $pdo->exec('PRAGMA user_version = ' . (int)$version);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        self::run($sql, array_values($row));
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $row, string $where, array $params = []): void
    {
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($row)));
        self::run("UPDATE $table SET $set WHERE $where", [...array_values($row), ...$params]);
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
