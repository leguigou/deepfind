<?php

declare(strict_types=1);

namespace DeepFind;

/**
 * Lecture du fichier .env (clé=valeur). Les variables d'environnement système sont prioritaires.
 */
final class Config
{
    /** @var array<string,string> */
    private static array $values = [];

    public static function load(string $file): void
    {
        self::$values = [];
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            $len = strlen($val);
            if ($len >= 2 && (($val[0] === '"' && $val[$len - 1] === '"') || ($val[0] === "'" && $val[$len - 1] === "'"))) {
                $val = substr($val, 1, -1);
            }
            self::$values[$key] = $val;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        $v = self::$values[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key);
        return $v === null ? $default : (int) $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function isProd(): bool
    {
        return self::get('APP_ENV', 'prod') === 'prod';
    }

    public static function indexPrefix(): string
    {
        return self::get('MEILI_INDEX_PREFIX', 'deepfind_');
    }

    public static function productIndex(string $lang): string
    {
        return self::indexPrefix() . 'products_' . $lang;
    }

    public static function postIndex(string $lang): string
    {
        return self::indexPrefix() . 'posts_' . $lang;
    }

    public static function varDir(string $sub = ''): string
    {
        $dir = APP_ROOT . '/var' . ($sub !== '' ? '/' . $sub : '');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}
