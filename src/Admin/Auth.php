<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Config;
use DeepFind\Security\ClientIp;
use DeepFind\Store\MysqlStore;
use DeepFind\Store\Store;

/**
 * Accès à l'extranet par jeton unique (ADMIN_TOKEN du .env). Pas de gestion d'utilisateurs en V1.
 */
final class Auth
{
    private const IDLE = 7200;
    private const REMEMBER_TTL = 10 * 86400;
    private const REMEMBER_COOKIE = 'DFADMIN_REMEMBER';
    private const MAX_ATTEMPTS = 5;
    private const WINDOW = 900;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('DFADMIN');
        session_set_cookie_params(['lifetime' => 0] + self::cookieOptions());
        session_start();
    }

    public static function check(bool $restore = false): bool
    {
        $now = time();
        if (($_SESSION['admin'] ?? false) === true
            && $now - (int) ($_SESSION['last'] ?? 0) <= self::IDLE
            && $now < (int) ($_SESSION['remember_until'] ?? PHP_INT_MAX)
        ) {
            $_SESSION['last'] = $now;
            return true;
        }
        unset($_SESSION['admin'], $_SESSION['last'], $_SESSION['remember_until']);
        if (!$restore || ($key = self::rememberKey()) === null) {
            return false;
        }
        $expires = (new MysqlStore())->get($key);
        if (!is_int($expires) || $expires <= $now) {
            self::setRememberCookie('', $now - 3600);
            return false;
        }
        self::openSession($expires);
        return true;
    }

    /** @return true|string true si connecté, sinon message d'erreur */
    public static function attempt(string $token): bool|string
    {
        $expected = (string) Config::get('ADMIN_TOKEN', '');
        if (strlen($expected) < 24) {
            return 'ADMIN_TOKEN absent ou trop court (24 caractères minimum) dans le fichier .env.';
        }
        $ip = ClientIp::resolve($_SERVER);
        $key = 'login:' . ClientIp::hash($ip) . ':' . intdiv(time(), self::WINDOW);
        $store = Store::get();
        $tries = (int) ($store->get($key) ?? 0);
        if ($tries >= self::MAX_ATTEMPTS) {
            return 'Trop de tentatives. Réessayez dans 15 minutes.';
        }
        if (!hash_equals($expected, trim($token))) {
            $store->incr($key, self::WINDOW + 60);
            usleep(300_000);
            return 'Jeton invalide.';
        }
        $store = new MysqlStore();
        $previous = self::rememberKey();
        $value = bin2hex(random_bytes(32));
        $expires = time() + self::REMEMBER_TTL;
        $store->set(self::rememberKey($value), $expires, self::REMEMBER_TTL);
        if ($previous !== null) {
            $store->delete($previous);
        }
        self::setRememberCookie($value, $expires);
        self::openSession($expires);
        return true;
    }

    public static function logout(): void
    {
        if (($key = self::rememberKey()) !== null) {
            (new MysqlStore())->delete($key);
        }
        self::setRememberCookie('', time() - 3600);
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private static function openSession(int $expires): void
    {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['last'] = time();
        $_SESSION['remember_until'] = $expires;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }

    private static function rememberKey(?string $value = null): ?string
    {
        $value ??= $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        $token = (string) Config::get('ADMIN_TOKEN', '');
        if (!is_string($value) || !preg_match('/\A[0-9a-f]{64}\z/', $value) || strlen($token) < 24) {
            return null;
        }
        return 'admin-remember:' . hash_hmac('sha256', $value, $token);
    }

    private static function setRememberCookie(string $value, int $expires): void
    {
        setcookie(self::REMEMBER_COOKIE, $value, ['expires' => $expires] + self::cookieOptions());
    }

    private static function cookieOptions(): array
    {
        return [
            'path' => rtrim((string) Config::get('APP_BASE_PATH', ''), '/') . '/admin',
            'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
                || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            'httponly' => true,
            'samesite' => 'Strict',
        ];
    }

    public static function csrf(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    }

    public static function checkCsrf(): bool
    {
        return is_string($_POST['_csrf'] ?? null) && hash_equals(self::csrf(), $_POST['_csrf']);
    }

    public static function flash(string $type, string $msg): void
    {
        $_SESSION['flash'][] = [$type, $msg];
    }

    /** @return list<array{0:string,1:string}> */
    public static function takeFlash(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }

    public static function lang(): string
    {
        $langs = self::langs();
        $l = (string) ($_SESSION['lang'] ?? $langs[0]);
        return in_array($l, $langs, true) ? $l : $langs[0];
    }

    /** @return list<string> */
    public static function langs(): array
    {
        $l = array_values(array_filter(array_map('trim', explode(',', (string) Config::get('APP_LANGS', 'fr')))));
        return $l ?: ['fr'];
    }
}
