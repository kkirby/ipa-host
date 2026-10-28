<?php
declare(strict_types=1);
defined('IPAHOST') || exit;

/**
 * Xcode "crushes" PNGs inside app bundles into Apple's CgBI variant
 * (raw deflate, BGRA byte order, premultiplied alpha), which browsers
 * can't render. This converts them back into standard PNGs.
 */
final class PngFix
{
    private const SIG = "\x89PNG\r\n\x1a\n";

    /** @return array{0:int,1:int}|null width/height from the IHDR chunk */
    public static function dimensions(string $png): ?array
    {
        if (!str_starts_with($png, self::SIG)) {
            return null;
        }
        $pos = 8;
        // CgBI files put a CgBI chunk before IHDR, so scan the first few chunks.
        for ($i = 0; $i < 4 && $pos + 8 <= strlen($png); $i++) {
            $len = unpack('N', substr($png, $pos, 4))[1];
            if (substr($png, $pos + 4, 4) === 'IHDR' && $len >= 8) {
                $d = unpack('Nw/Nh', substr($png, $pos + 8, 8));
                return [$d['w'], $d['h']];
            }
            $pos += 12 + $len;
        }
        return null;
    }

    /** Returns a browser-friendly PNG, or null if it can't be decoded. */
    public static function normalize(string $png): ?string
    {
        if (!str_starts_with($png, self::SIG)) {
            return null;
        }
        $pos = 8;
        $len = strlen($png);
        $ihdr = null;
        $idat = '';
        $cgbi = false;
        while ($pos + 8 <= $len) {
            $clen = unpack('N', substr($png, $pos, 4))[1];
            $type = substr($png, $pos + 4, 4);
            $data = substr($png, $pos + 8, $clen);
            $pos += 12 + $clen;
            if ($type === 'CgBI') {
                $cgbi = true;
            } elseif ($type === 'IHDR') {
                $ihdr = $data;
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }
        }
        if (!$cgbi) {
            return $ihdr !== null ? $png : null;
        }
        if ($ihdr === null || strlen($ihdr) < 13) {
            return null;
        }
        $h = unpack('Nw/Nh/Cdepth/Cctype/Ccomp/Cfilter/Cinterlace', $ihdr);
        $w = $h['w'];
        $ht = $h['h'];
        if ($h['depth'] !== 8 || $h['ctype'] !== 6 || $h['interlace'] !== 0 || $w < 1 || $ht < 1 || $w * $ht > 1024 * 1024) {
            return null;
        }
        $raw = @gzinflate($idat);
        if ($raw === false) {
            $raw = @gzuncompress($idat);
        }
        $stride = $w * 4;
        if ($raw === false || strlen($raw) < $ht * ($stride + 1)) {
            return null;
        }

        $out = '';
        $prev = array_fill(0, $stride, 0);
        $off = 0;
        for ($y = 0; $y < $ht; $y++) {
            $filter = ord($raw[$off]);
            $line = array_values(unpack('C*', substr($raw, $off + 1, $stride)));
            $off += $stride + 1;
            $cur = self::unfilter($filter, $line, $prev, $stride);
            if ($cur === null) {
                return null;
            }
            $prev = $cur;
            // BGRA premultiplied -> RGBA straight alpha
            $row = $cur;
            for ($x = 0; $x < $stride; $x += 4) {
                $b = $cur[$x];
                $g = $cur[$x + 1];
                $r = $cur[$x + 2];
                $a = $cur[$x + 3];
                if ($a > 0 && $a < 255) {
                    $r = min(255, intdiv($r * 255 + ($a >> 1), $a));
                    $g = min(255, intdiv($g * 255 + ($a >> 1), $a));
                    $b = min(255, intdiv($b * 255 + ($a >> 1), $a));
                }
                $row[$x] = $r;
                $row[$x + 1] = $g;
                $row[$x + 2] = $b;
            }
            $out .= "\0" . pack('C*', ...$row);
        }

        $newIhdr = pack('NNCCCCC', $w, $ht, 8, 6, 0, 0, 0);
        return self::SIG
            . self::chunk('IHDR', $newIhdr)
            . self::chunk('IDAT', (string)gzcompress($out, 9))
            . self::chunk('IEND', '');
    }

    private static function unfilter(int $filter, array $line, array $prev, int $stride): ?array
    {
        $cur = $line;
        switch ($filter) {
            case 0:
                return $cur;
            case 1:
                for ($i = 4; $i < $stride; $i++) {
                    $cur[$i] = ($cur[$i] + $cur[$i - 4]) & 0xFF;
                }
                return $cur;
            case 2:
                for ($i = 0; $i < $stride; $i++) {
                    $cur[$i] = ($cur[$i] + $prev[$i]) & 0xFF;
                }
                return $cur;
            case 3:
                for ($i = 0; $i < $stride; $i++) {
                    $left = $i >= 4 ? $cur[$i - 4] : 0;
                    $cur[$i] = ($cur[$i] + (($left + $prev[$i]) >> 1)) & 0xFF;
                }
                return $cur;
            case 4:
                for ($i = 0; $i < $stride; $i++) {
                    $a = $i >= 4 ? $cur[$i - 4] : 0;
                    $b = $prev[$i];
                    $c = $i >= 4 ? $prev[$i - 4] : 0;
                    $p = $a + $b - $c;
                    $pa = abs($p - $a);
                    $pb = abs($p - $b);
                    $pc = abs($p - $c);
                    $pred = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                    $cur[$i] = ($cur[$i] + $pred) & 0xFF;
                }
                return $cur;
        }
        return null;
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
