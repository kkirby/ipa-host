<?php
declare(strict_types=1);
defined('IPAHOST') || exit;

final class DuplicateBuildException extends RuntimeException
{
    public function __construct(public readonly int $buildId)
    {
        parent::__construct('This exact IPA was already uploaded.');
    }
}

final class Store
{
    /**
     * Parse, store and register an IPA. $tmpPath is moved into storage
     * on success and deleted on failure.
     */
    public static function ingest(string $tmpPath, string $originalName, string $notes = ''): array
    {
        @set_time_limit(0);
        try {
            $meta = IpaParser::parse($tmpPath);
            $sha = (string)hash_file('sha256', $tmpPath);
            $dup = Db::val('SELECT id FROM builds WHERE sha256 = ?', [$sha]);
            if ($dup) {
                throw new DuplicateBuildException((int)$dup);
            }

            $file = bin2hex(random_bytes(16)) . '.ipa';
            $dest = DATA_DIR . '/ipas/' . $file;
            $moved = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $dest) : @rename($tmpPath, $dest);
            if (!$moved && !(@copy($tmpPath, $dest) && @unlink($tmpPath))) {
                throw new RuntimeException('Could not move the IPA into storage. Check that the data directory is writable.');
            }
        } catch (Throwable $e) {
            @unlink($tmpPath);
            throw $e;
        }

        $icon = null;
        if ($meta['icon'] !== null) {
            $icon = bin2hex(random_bytes(16)) . '.png';
            if (@file_put_contents(DATA_DIR . '/icons/' . $icon, $meta['icon']) === false) {
                $icon = null;
            }
        }

        $now = time();
        $buildId = Db::transaction(function () use ($meta, $file, $originalName, $sha, $icon, $notes, $dest, $now) {
            $app = Db::one('SELECT * FROM apps WHERE bundle_id = ?', [$meta['bundle_id']]);
            if ($app) {
                $appId = (int)$app['id'];
                Db::update('apps', ['name' => $meta['name'], 'icon' => $icon ?? $app['icon'], 'updated_at' => $now], 'id = ?', [$appId]);
            } else {
                $appId = Db::insert('apps', [
                    'bundle_id' => $meta['bundle_id'],
                    'name' => $meta['name'],
                    'icon' => $icon,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $profile = $meta['profile'];
            return Db::insert('builds', [
                'app_id' => $appId,
                'name' => $meta['name'],
                'version' => $meta['version'],
                'build' => $meta['build'],
                'min_os' => $meta['min_os'],
                'families' => implode(', ', $meta['families']),
                'file' => $file,
                'original_name' => mb_substr_safe(basename($originalName), 255),
                'size' => (int)filesize($dest),
                'sha256' => $sha,
                'icon' => $icon,
                'notes' => $notes,
                'profile' => $profile ? json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
                'info' => json_encode($meta['info'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'meta' => json_encode([
                    'platforms' => $meta['platforms'],
                    'executable' => $meta['executable'],
                    'sdk' => $meta['sdk'],
                    'xcode' => $meta['xcode'],
                    'signed' => $meta['signed'],
                    'bundles' => $meta['bundles'],
                ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'created_at' => $now,
            ]);
        });

        $build = self::build($buildId);
        log_event('upload', $buildId, $build['name'] . ' ' . build_label($build));
        self::prune((int)$build['app_id']);
        return $build;
    }

    public static function build(int $id): ?array
    {
        $b = Db::one('SELECT b.*, a.bundle_id, a.icon AS app_icon FROM builds b JOIN apps a ON a.id = b.app_id WHERE b.id = ?', [$id]);
        return $b ? self::hydrate($b) : null;
    }

    public static function latestBuild(int $appId): ?array
    {
        $b = Db::one('SELECT b.*, a.bundle_id, a.icon AS app_icon FROM builds b JOIN apps a ON a.id = b.app_id WHERE b.app_id = ? ORDER BY b.id DESC LIMIT 1', [$appId]);
        return $b ? self::hydrate($b) : null;
    }

    public static function hydrate(array $b): array
    {
        $b['profile'] = !empty($b['profile']) ? json_decode($b['profile'], true) : null;
        $b['meta'] = !empty($b['meta']) ? (json_decode($b['meta'], true) ?: []) : [];
        // Builds without a loose icon PNG (Assets.car only) borrow the app's icon.
        $b['icon'] = $b['icon'] ?: ($b['app_icon'] ?? null);
        return $b;
    }

    public static function ipaPath(array $build): string
    {
        return DATA_DIR . '/ipas/' . $build['file'];
    }

    public static function deleteBuild(int $id): void
    {
        $b = Db::one('SELECT * FROM builds WHERE id = ?', [$id]);
        if (!$b) {
            return;
        }
        log_event('delete', null, $b['name'] . ' ' . build_label($b));
        Db::run('DELETE FROM builds WHERE id = ?', [$id]);
        @unlink(DATA_DIR . '/ipas/' . $b['file']);

        $appId = (int)$b['app_id'];
        $appIcon = Db::val('SELECT icon FROM apps WHERE id = ?', [$appId]);
        $latest = self::latestBuild($appId);
        if ($latest === null) {
            Db::run('DELETE FROM apps WHERE id = ?', [$appId]);
        } else {
            Db::update('apps', ['name' => $latest['name'], 'icon' => $latest['icon'] ?? $appIcon], 'id = ?', [$appId]);
        }
        self::removeIconIfUnused($b['icon']);
        self::removeIconIfUnused($appIcon);
    }

    public static function deleteApp(int $appId): void
    {
        foreach (Db::all('SELECT id FROM builds WHERE app_id = ?', [$appId]) as $row) {
            self::deleteBuild((int)$row['id']);
        }
        Db::run('DELETE FROM apps WHERE id = ?', [$appId]);
    }

    public static function prune(int $appId): int
    {
        $keep = (int)Settings::get('keep_builds', 0);
        if ($keep <= 0) {
            return 0;
        }
        $old = Db::all('SELECT id FROM builds WHERE app_id = ? ORDER BY id DESC LIMIT -1 OFFSET ?', [$appId, $keep]);
        foreach ($old as $row) {
            self::deleteBuild((int)$row['id']);
        }
        return count($old);
    }

    private static function removeIconIfUnused(?string $icon): void
    {
        if (!$icon) {
            return;
        }
        $used = (int)Db::val('SELECT (SELECT COUNT(*) FROM builds WHERE icon = ?) + (SELECT COUNT(*) FROM apps WHERE icon = ?)', [$icon, $icon]);
        if ($used === 0) {
            @unlink(DATA_DIR . '/icons/' . $icon);
        }
    }

    /** Stream a file with HTTP Range support. */
    public static function send(string $path, string $downloadName, string $type = 'application/octet-stream'): void
    {
        $size = (int)filesize($path);
        $start = 0;
        $end = $size - 1;
        $status = 200;

        $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                $start = max(0, $size - (int)$m[2]);
            } else {
                $start = (int)$m[1];
                if ($m[2] !== '') {
                    $end = min($end, (int)$m[2]);
                }
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */$size");
                exit;
            }
            $status = 206;
        }

        @set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: ' . $type);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . ($end - $start + 1));
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
        header('Cache-Control: private, no-transform');
        if ($status === 206) {
            header("Content-Range: bytes $start-$end/$size");
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            exit;
        }
        $fh = fopen($path, 'rb');
        fseek($fh, $start);
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($fh) && !connection_aborted()) {
            $chunk = fread($fh, min(1024 * 1024, $remaining));
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            flush();
            $remaining -= strlen($chunk);
        }
        fclose($fh);
        exit;
    }

    public static function ipaDownloadName(array $b): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $b['name']) ?: 'app';
        $ver = preg_replace('/[^A-Za-z0-9._-]+/', '-', build_label($b)) ?: '';
        return trim("$name-$ver", '-') . '.ipa';
    }

    public static function stats(): array
    {
        return [
            'apps' => (int)Db::val('SELECT COUNT(*) FROM apps'),
            'builds' => (int)Db::val('SELECT COUNT(*) FROM builds'),
            'bytes' => (int)Db::val('SELECT COALESCE(SUM(size), 0) FROM builds'),
            'free' => @disk_free_space(DATA_DIR) ?: 0,
        ];
    }
}
