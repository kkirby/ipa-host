<?php
declare(strict_types=1);
defined('IPAHOST') || exit;

/**
 * Property list reader (XML and binary "bplist00") and XML writer.
 * <data> values decode to ['$data' => base64] so results stay JSON-safe.
 */
final class Plist
{
    public static function parse(string $data): mixed
    {
        if (str_starts_with($data, 'bplist00')) {
            return (new BinaryPlistReader($data))->read();
        }
        $trimmed = ltrim($data, "\xEF\xBB\xBF \t\r\n");
        if (str_starts_with($trimmed, '<')) {
            return self::parseXml($trimmed);
        }
        throw new RuntimeException('Unsupported property list format');
    }

    public static function parseXml(string $xml): mixed
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || !$doc->documentElement || $doc->documentElement->nodeName !== 'plist') {
            throw new RuntimeException('Malformed XML property list');
        }
        foreach ($doc->documentElement->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return self::xmlNode($child, 0);
            }
        }
        return null;
    }

    private static function xmlNode(DOMElement $el, int $depth): mixed
    {
        if ($depth > 64) {
            throw new RuntimeException('Property list nested too deeply');
        }
        switch ($el->nodeName) {
            case 'dict':
                $out = [];
                $key = null;
                foreach ($el->childNodes as $c) {
                    if (!$c instanceof DOMElement) {
                        continue;
                    }
                    if ($c->nodeName === 'key') {
                        $key = $c->textContent;
                    } elseif ($key !== null) {
                        $out[$key] = self::xmlNode($c, $depth + 1);
                        $key = null;
                    }
                }
                return $out;
            case 'array':
                $out = [];
                foreach ($el->childNodes as $c) {
                    if ($c instanceof DOMElement) {
                        $out[] = self::xmlNode($c, $depth + 1);
                    }
                }
                return $out;
            case 'string':
                return $el->textContent;
            case 'integer':
                $t = trim($el->textContent);
                return filter_var($t, FILTER_VALIDATE_INT) !== false ? (int)$t : $t;
            case 'real':
                return (float)trim($el->textContent);
            case 'true':
                return true;
            case 'false':
                return false;
            case 'date':
                return trim($el->textContent);
            case 'data':
                return ['$data' => (string)preg_replace('/\s+/', '', $el->textContent)];
            default:
                return null;
        }
    }

    public static function toXml(mixed $value): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<!DOCTYPE plist PUBLIC \"-//Apple//DTD PLIST 1.0//EN\" \"http://www.apple.com/DTDs/PropertyList-1.0.dtd\">\n"
            . "<plist version=\"1.0\">\n"
            . self::xmlValue($value, 0)
            . "</plist>\n";
    }

    private static function xmlValue(mixed $v, int $level): string
    {
        $pad = str_repeat("\t", $level);
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        if (is_bool($v)) {
            return $pad . ($v ? '<true/>' : '<false/>') . "\n";
        }
        if (is_int($v)) {
            return "$pad<integer>$v</integer>\n";
        }
        if (is_float($v)) {
            return "$pad<real>$v</real>\n";
        }
        if (is_string($v)) {
            return "$pad<string>" . $esc($v) . "</string>\n";
        }
        if (is_array($v)) {
            if (array_is_list($v)) {
                $out = "$pad<array>\n";
                foreach ($v as $item) {
                    $out .= self::xmlValue($item, $level + 1);
                }
                return $out . "$pad</array>\n";
            }
            $out = "$pad<dict>\n";
            foreach ($v as $k => $item) {
                if ($item === null) {
                    continue;
                }
                $out .= "$pad\t<key>" . $esc((string)$k) . "</key>\n" . self::xmlValue($item, $level + 1);
            }
            return $out . "$pad</dict>\n";
        }
        return '';
    }
}

final class BinaryPlistReader
{
    private array $offsets = [];
    private int $refSize = 1;
    private array $visiting = [];

    public function __construct(private readonly string $d)
    {
    }

    public function read(): mixed
    {
        $len = strlen($this->d);
        if ($len < 40) {
            throw new RuntimeException('Truncated binary property list');
        }
        $t = substr($this->d, -32);
        $offSize = ord($t[6]);
        $this->refSize = ord($t[7]);
        $num = self::uint(substr($t, 8, 8));
        $top = self::uint(substr($t, 16, 8));
        $table = self::uint(substr($t, 24, 8));
        if ($offSize < 1 || $offSize > 8 || $this->refSize < 1 || $this->refSize > 8
            || $num < 1 || $num > 5_000_000 || $top >= $num || $table + $num * $offSize > $len - 32) {
            throw new RuntimeException('Corrupt binary property list trailer');
        }
        for ($i = 0; $i < $num; $i++) {
            $this->offsets[] = self::uint(substr($this->d, $table + $i * $offSize, $offSize));
        }
        return $this->object($top);
    }

    private function object(int $ref): mixed
    {
        if (!isset($this->offsets[$ref])) {
            throw new RuntimeException('Invalid object reference in property list');
        }
        if (isset($this->visiting[$ref]) || count($this->visiting) > 64) {
            throw new RuntimeException('Cyclic or too deeply nested property list');
        }
        $this->visiting[$ref] = true;
        try {
            return $this->decode($this->offsets[$ref]);
        } finally {
            unset($this->visiting[$ref]);
        }
    }

    private function decode(int $off): mixed
    {
        if (!isset($this->d[$off])) {
            throw new RuntimeException('Object offset out of range');
        }
        $marker = ord($this->d[$off]);
        $type = $marker >> 4;
        $info = $marker & 0x0F;
        switch ($type) {
            case 0x0:
                return match ($info) {
                    0x8 => false,
                    0x9 => true,
                    default => null,
                };
            case 0x1:
                return $this->int($off + 1, 1 << $info);
            case 0x2:
                $n = 1 << $info;
                if ($n !== 4 && $n !== 8) {
                    throw new RuntimeException('Unsupported real size in property list');
                }
                $b = $this->bytes($off + 1, $n);
                return $n === 4 ? unpack('G', $b)[1] : unpack('E', $b)[1];
            case 0x3:
                $secs = unpack('E', $this->bytes($off + 1, 8))[1];
                return gmdate('Y-m-d\TH:i:s\Z', (int)round(978307200 + $secs));
            case 0x4:
                [$n, $start] = $this->length($off, $info);
                return ['$data' => base64_encode($this->bytes($start, $n))];
            case 0x5:
                [$n, $start] = $this->length($off, $info);
                return $this->bytes($start, $n);
            case 0x6:
                [$n, $start] = $this->length($off, $info);
                return to_utf8($this->bytes($start, $n * 2), 'UTF-16BE');
            case 0x8:
                return self::uint($this->bytes($off + 1, $info + 1));
            case 0xA:
            case 0xC:
                [$n, $start] = $this->length($off, $info);
                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    $out[] = $this->object($this->ref($start + $i * $this->refSize));
                }
                return $out;
            case 0xD:
                [$n, $start] = $this->length($off, $info);
                $out = [];
                $valStart = $start + $n * $this->refSize;
                for ($i = 0; $i < $n; $i++) {
                    $key = $this->object($this->ref($start + $i * $this->refSize));
                    $out[(string)(is_scalar($key) ? $key : '')] = $this->object($this->ref($valStart + $i * $this->refSize));
                }
                return $out;
        }
        throw new RuntimeException(sprintf('Unknown binary plist object type 0x%X', $type));
    }

    private function length(int $off, int $info): array
    {
        if ($info !== 0x0F) {
            return [$info, $off + 1];
        }
        $m = ord($this->bytes($off + 1, 1));
        if (($m >> 4) !== 0x1) {
            throw new RuntimeException('Bad length marker in property list');
        }
        $n = 1 << ($m & 0x0F);
        $len = $this->int($off + 2, $n);
        if ($len < 0 || $len > strlen($this->d)) {
            throw new RuntimeException('Bad length in property list');
        }
        return [$len, $off + 2 + $n];
    }

    private function int(int $off, int $n): int
    {
        $b = $this->bytes($off, $n);
        if ($n === 16) {
            $b = substr($b, 8);
            $n = 8;
        }
        return $n === 8 ? unpack('J', $b)[1] : self::uint($b);
    }

    private function ref(int $off): int
    {
        return self::uint($this->bytes($off, $this->refSize));
    }

    private function bytes(int $off, int $n): string
    {
        $b = substr($this->d, $off, $n);
        if (strlen($b) !== $n) {
            throw new RuntimeException('Unexpected end of property list');
        }
        return $b;
    }

    private static function uint(string $b): int
    {
        $v = 0;
        $len = strlen($b);
        for ($i = 0; $i < $len; $i++) {
            $v = ($v << 8) | ord($b[$i]);
        }
        return $v;
    }
}
