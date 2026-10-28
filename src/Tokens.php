<?php
declare(strict_types=1);
defined('IPAHOST') || exit;

/**
 * Stateless, expiring HMAC tokens. iOS fetches manifests and IPAs without
 * the browser's cookies, so those URLs carry one of these instead.
 */
final class Tokens
{
    public static function sign(string $purpose, string $subject, int $expires): string
    {
        return $expires . '.' . self::mac($purpose, $subject, $expires);
    }

    public static function verify(string $purpose, string $subject, string $token): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            return false;
        }
        $expires = (int)$parts[0];
        if ($expires < time()) {
            return false;
        }
        return hash_equals(self::mac($purpose, $subject, $expires), $parts[1]);
    }

    private static function mac(string $purpose, string $subject, int $expires): string
    {
        return substr(hash_hmac('sha256', "$purpose|$subject|$expires", Settings::secret()), 0, 32);
    }

    public static function random(int $bytes = 24): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
