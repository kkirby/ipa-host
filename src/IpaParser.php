<?php
declare(strict_types=1);
defined('IPAHOST') || exit;

final class IpaParser
{
    private const FAMILIES = [
        1 => 'iPhone',
        2 => 'iPad',
        3 => 'Apple TV',
        4 => 'Apple Watch',
        6 => 'Mac',
        7 => 'Apple Vision',
    ];

    public static function parse(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Not a valid IPA (could not open as a ZIP archive).');
        }
        try {
            return self::parseZip($zip);
        } finally {
            $zip->close();
        }
    }

    private static function parseZip(ZipArchive $zip): array
    {
        $names = [];
        $appDir = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            $names[] = $name;
            if ($appDir === null && preg_match('#^Payload/[^/]+\.app/Info\.plist$#', $name)) {
                $appDir = substr($name, 0, -strlen('Info.plist'));
            }
        }
        if ($appDir === null) {
            throw new RuntimeException('Not a valid IPA (no Payload/*.app/Info.plist found).');
        }

        $info = self::readPlist($zip, $appDir . 'Info.plist');
        if (!is_array($info) || empty($info['CFBundleIdentifier']) || !is_string($info['CFBundleIdentifier'])) {
            throw new RuntimeException('Info.plist is missing CFBundleIdentifier.');
        }

        $families = [];
        foreach ((array)($info['UIDeviceFamily'] ?? []) as $f) {
            $families[] = self::FAMILIES[(int)$f] ?? "Family $f";
        }

        $profileRaw = $zip->getFromName($appDir . 'embedded.mobileprovision');
        $profile = $profileRaw !== false ? self::parseProfile($profileRaw) : null;

        $bundles = [];
        foreach ($names as $name) {
            if (preg_match('#^' . preg_quote($appDir, '#') . '(PlugIns/[^/]+\.appex|Watch/[^/]+\.app|Extensions/[^/]+\.appex|AppClips/[^/]+\.app)/Info\.plist$#', $name, $m)) {
                try {
                    $sub = self::readPlist($zip, $name);
                } catch (Throwable) {
                    continue;
                }
                if (is_array($sub)) {
                    $bundles[] = [
                        'path' => $m[1],
                        'name' => (string)($sub['CFBundleDisplayName'] ?? $sub['CFBundleName'] ?? basename($m[1])),
                        'bundle_id' => (string)($sub['CFBundleIdentifier'] ?? ''),
                        'kind' => (string)($sub['NSExtension']['NSExtensionPointIdentifier'] ?? (str_starts_with($m[1], 'Watch/') ? 'watch app' : (str_starts_with($m[1], 'AppClips/') ? 'app clip' : ''))),
                    ];
                }
            }
        }

        return [
            'bundle_id' => $info['CFBundleIdentifier'],
            'name' => self::str($info['CFBundleDisplayName'] ?? null)
                ?: self::str($info['CFBundleName'] ?? null)
                ?: self::str($info['CFBundleExecutable'] ?? null)
                ?: basename(rtrim($appDir, '/'), '.app'),
            'version' => self::str($info['CFBundleShortVersionString'] ?? null),
            'build' => self::str($info['CFBundleVersion'] ?? null),
            'min_os' => self::str($info['MinimumOSVersion'] ?? ($info['LSMinimumSystemVersion'] ?? null)),
            'families' => $families,
            'platforms' => array_values(array_map('strval', (array)($info['CFBundleSupportedPlatforms'] ?? []))),
            'executable' => self::str($info['CFBundleExecutable'] ?? null),
            'sdk' => self::str($info['DTSDKName'] ?? null),
            'xcode' => self::str($info['DTXcode'] ?? null),
            'signed' => in_array($appDir . '_CodeSignature/CodeResources', $names, true),
            'icon' => self::findIcon($zip, $appDir, $names, $info),
            'profile' => $profile,
            'bundles' => $bundles,
            'info' => $info,
        ];
    }

    private static function readPlist(ZipArchive $zip, string $name): mixed
    {
        $stat = $zip->statName($name);
        if ($stat === false || $stat['size'] > 16 * 1024 * 1024) {
            throw new RuntimeException("Could not read $name");
        }
        $raw = $zip->getFromName($name);
        if ($raw === false) {
            throw new RuntimeException("Could not read $name");
        }
        return Plist::parse($raw);
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? trim((string)$v) : '';
    }

    private static function findIcon(ZipArchive $zip, string $appDir, array $names, array $info): ?string
    {
        $prefixes = [];
        foreach (['CFBundleIcons', 'CFBundleIcons~ipad'] as $key) {
            $primary = $info[$key]['CFBundlePrimaryIcon'] ?? null;
            if (is_array($primary)) {
                foreach ((array)($primary['CFBundleIconFiles'] ?? []) as $f) {
                    $prefixes[] = (string)$f;
                }
                if (!empty($primary['CFBundleIconName'])) {
                    $prefixes[] = (string)$primary['CFBundleIconName'];
                }
            }
        }
        foreach ((array)($info['CFBundleIconFiles'] ?? []) as $f) {
            $prefixes[] = (string)$f;
        }
        if (!empty($info['CFBundleIconFile'])) {
            $prefixes[] = (string)$info['CFBundleIconFile'];
        }
        $prefixes = array_values(array_unique(array_filter(array_map(
            fn($p) => strtolower(preg_replace('/\.png$/i', '', $p)),
            $prefixes
        ))));

        $rootPngs = [];
        $dirLen = strlen($appDir);
        foreach ($names as $name) {
            if (str_starts_with($name, $appDir) && !str_contains(substr($name, $dirLen), '/') && preg_match('/\.png$/i', $name)) {
                $rootPngs[] = $name;
            }
        }

        $candidates = array_filter($rootPngs, function ($name) use ($prefixes, $dirLen) {
            $base = strtolower(substr($name, $dirLen));
            foreach ($prefixes as $p) {
                if (str_starts_with($base, $p)) {
                    return true;
                }
            }
            return false;
        });
        if (!$candidates) {
            $candidates = array_filter($rootPngs, fn($n) => preg_match('/^(appicon|icon)/i', substr($n, $dirLen)));
        }

        $scored = [];
        foreach ($candidates as $name) {
            $stat = $zip->statName($name);
            if ($stat === false || $stat['size'] > 4 * 1024 * 1024) {
                continue;
            }
            $data = $zip->getFromName($name);
            $dim = $data !== false ? PngFix::dimensions($data) : null;
            if ($dim) {
                $scored[] = [$dim[0] * $dim[1], $data];
            }
        }
        usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
        foreach ($scored as [, $data]) {
            $png = PngFix::normalize($data);
            if ($png !== null) {
                return $png;
            }
        }
        return null;
    }

    public static function parseProfile(string $raw): ?array
    {
        $start = strpos($raw, '<?xml');
        $end = $start === false ? false : strpos($raw, '</plist>', $start);
        if ($start === false || $end === false) {
            return null;
        }
        try {
            $p = Plist::parseXml(substr($raw, $start, $end - $start + 8));
        } catch (Throwable) {
            return null;
        }
        if (!is_array($p)) {
            return null;
        }
        $ent = is_array($p['Entitlements'] ?? null) ? $p['Entitlements'] : [];
        $devices = array_values(array_filter(array_map('strval', (array)($p['ProvisionedDevices'] ?? []))));
        $all = !empty($p['ProvisionsAllDevices']);
        $type = match (true) {
            $all => 'enterprise',
            (bool)$devices => !empty($ent['get-task-allow']) ? 'development' : 'adhoc',
            default => 'appstore',
        };
        $date = fn($v) => is_string($v) && ($t = strtotime($v)) ? $t : null;
        return [
            'name' => (string)($p['Name'] ?? ''),
            'uuid' => (string)($p['UUID'] ?? ''),
            'team_name' => (string)($p['TeamName'] ?? ''),
            'team_id' => (string)(((array)($p['TeamIdentifier'] ?? []))[0] ?? ''),
            'app_id_name' => (string)($p['AppIDName'] ?? ''),
            'created' => $date($p['CreationDate'] ?? null),
            'expires' => $date($p['ExpirationDate'] ?? null),
            'type' => $type,
            'all_devices' => $all,
            'devices' => $devices,
            'platforms' => array_values(array_map('strval', (array)($p['Platform'] ?? []))),
            'entitlements' => $ent,
        ];
    }
}
