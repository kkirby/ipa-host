<?php
declare(strict_types=1);
defined('IPAHOST') || exit;

final class Auth
{
    private const REMEMBER_COOKIE = 'ipahost_remember';
    private const REMEMBER_DAYS = 60;
    private const MAX_ATTEMPTS = 5;
    private const ATTEMPT_WINDOW = 900;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('ipahost');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => base_path(),
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string)(86400 * 7));
        session_start();
    }

    public static function check(): bool
    {
        if (!empty($_SESSION['auth'])) {
            return true;
        }
        return self::loginFromRememberCookie();
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            $next = $_SERVER['QUERY_STRING'] ?? '';
            redirect(url('login', $next !== '' ? ['next' => '?' . $next] : []));
        }
    }

    public static function attempt(string $password, bool $remember): bool
    {
        $ip = client_ip();
        Db::run('DELETE FROM login_attempts WHERE created_at < ?', [time() - 86400]);
        if (self::throttled($ip)) {
            return false;
        }
        $hash = (string)Settings::get('password_hash', '');
        if ($hash === '' || !password_verify($password, $hash)) {
            Db::insert('login_attempts', ['ip' => $ip, 'created_at' => time()]);
            log_event('login_failed');
            usleep(random_int(300000, 900000));
            return false;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Settings::set('password_hash', password_hash($password, PASSWORD_DEFAULT));
        }
        Db::run('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
        self::establish();
        if ($remember) {
            self::issueRememberCookie();
        }
        log_event('login');
        return true;
    }

    public static function throttled(string $ip): bool
    {
        $count = (int)Db::val(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > ?',
            [$ip, time() - self::ATTEMPT_WINDOW]
        );
        return $count >= self::MAX_ATTEMPTS;
    }

    public static function recordFailure(): void
    {
        Db::insert('login_attempts', ['ip' => client_ip(), 'created_at' => time()]);
    }

    public static function establish(): void
    {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function logout(bool $everywhere = false): void
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if ($everywhere) {
            Db::run('DELETE FROM remember_tokens');
        } elseif (is_string($cookie) && str_contains($cookie, ':')) {
            Db::run('DELETE FROM remember_tokens WHERE selector = ?', [explode(':', $cookie, 2)[0]]);
        }
        self::setCookie(self::REMEMBER_COOKIE, '', time() - 3600);
        $_SESSION = [];
        session_regenerate_id(true);
    }

    private static function issueRememberCookie(): void
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + self::REMEMBER_DAYS * 86400;
        Db::insert('remember_tokens', [
            'selector' => $selector,
            'hash' => hash('sha256', $validator),
            'expires_at' => $expires,
        ]);
        self::setCookie(self::REMEMBER_COOKIE, "$selector:$validator", $expires);
    }

    private static function loginFromRememberCookie(): bool
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if (!is_string($cookie) || !str_contains($cookie, ':')) {
            return false;
        }
        [$selector, $validator] = explode(':', $cookie, 2);
        $row = Db::one('SELECT * FROM remember_tokens WHERE selector = ?', [$selector]);
        if (!$row || $row['expires_at'] < time() || !hash_equals($row['hash'], hash('sha256', $validator))) {
            self::setCookie(self::REMEMBER_COOKIE, '', time() - 3600);
            return false;
        }
        self::establish();
        return true;
    }

    private static function setCookie(string $name, string $value, int $expires): void
    {
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => base_path(),
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function verifyCsrf(): void
    {
        $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
            if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') || isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
                json_out(['error' => 'Session expired. Reload the page and try again.'], 419);
            }
            abort(419, 'Your session expired. Go back, reload the page and try again.');
        }
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . h(self::csrfToken()) . '">';
    }

    public static function checkApiToken(): bool
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        $token = '';
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            $token = $m[1];
        } elseif (!empty($_SERVER['HTTP_X_API_TOKEN'])) {
            $token = (string)$_SERVER['HTTP_X_API_TOKEN'];
        }
        $stored = (string)Settings::get('api_token_hash', '');
        if ($token === '' || $stored === '') {
            return false;
        }
        return hash_equals($stored, hash('sha256', $token));
    }
}
