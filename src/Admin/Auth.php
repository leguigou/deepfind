<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Config;
use DeepFind\Security\ClientIp;
use DeepFind\Store\Store;

/**
 * Accès à l'extranet par jeton unique (ADMIN_TOKEN du .env). Pas de gestion d'utilisateurs en V1.
 */
final class Auth
{
    private const IDLE = 7200;
    private const MAX_ATTEMPTS = 5;
    private const WINDOW = 900;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('DFADMIN');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => rtrim((string) Config::get('APP_BASE_PATH', ''), '/') . '/admin',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function check(): bool
    {
        if (($_SESSION['admin'] ?? false) !== true) {
            return false;
        }
        if (time() - (int) ($_SESSION['last'] ?? 0) > self::IDLE) {
            self::logout();
            return false;
        }
        $_SESSION['last'] = time();
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
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['last'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
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
