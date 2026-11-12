<?php

declare(strict_types=1);

namespace DeepFind;

/**
 * Numéro de version des données (index, règles, paramètres) partagé entre la ligne de commande et le web.
 * Il fait partie des clés de cache : le changer rend instantanément obsolètes toutes les réponses en cache,
 * y compris dans la mémoire APCu du serveur web qu'un import lancé en CLI ne peut pas vider lui-même.
 * Stocké dans un petit fichier (lecture quasi gratuite à chaque requête) et recopié en base.
 */
final class CacheVersion
{
    private static ?string $v = null;

    public static function get(): string
    {
        if (self::$v !== null) {
            return self::$v;
        }
        $v = @file_get_contents(self::file());
        if ($v === false || trim($v) === '') {
            $v = (string) (Db::value("SELECT value FROM settings WHERE name = 'rules_version'") ?? '1');
            @file_put_contents(self::file(), $v);
        }
        return self::$v = trim($v);
    }

    public static function bump(): string
    {
        $v = (string) hrtime(true);
        $tmp = self::file() . '.' . getmypid();
        if (@file_put_contents($tmp, $v) !== false) {
            @chmod($tmp, 0664);
            @rename($tmp, self::file());
        }
        Db::exec("INSERT INTO settings (name, value) VALUES ('rules_version', ?) ON DUPLICATE KEY UPDATE value = ?", [$v, $v]);
        return self::$v = $v;
    }

    /** Pour les tests et les processus longs (worker). */
    public static function reset(): void
    {
        self::$v = null;
    }

    private static function file(): string
    {
        return Config::varDir('cache') . '/data_version';
    }
}
