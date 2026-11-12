<?php

declare(strict_types=1);

namespace DeepFind;

/**
 * Numéro de version des données d'un projet (index, règles, paramètres), partagé entre la ligne de commande et le web.
 * Il fait partie des clés de cache : le changer rend instantanément obsolètes toutes les réponses en cache du projet,
 * y compris dans la mémoire APCu du serveur web qu'un import lancé en CLI ne peut pas vider lui-même.
 * Stocké dans un petit fichier par projet (lecture quasi gratuite à chaque requête) et recopié en base.
 */
final class CacheVersion
{
    /** @var array<int,string> */
    private static array $v = [];

    public static function get(?int $projectId = null): string
    {
        $pid = $projectId ?? Project::id();
        if (isset(self::$v[$pid])) {
            return self::$v[$pid];
        }
        $v = @file_get_contents(self::file($pid));
        if ($v === false || trim($v) === '') {
            $v = (string) (Db::value('SELECT value FROM settings WHERE name = ?', ['data_version_' . $pid]) ?? ('1-' . $pid));
            @file_put_contents(self::file($pid), $v);
        }
        return self::$v[$pid] = trim($v);
    }

    public static function bump(?int $projectId = null): string
    {
        $pid = $projectId ?? Project::id();
        $v = hrtime(true) . '-' . $pid;
        $tmp = self::file($pid) . '.' . getmypid();
        if (@file_put_contents($tmp, $v) !== false) {
            @chmod($tmp, 0664);
            @rename($tmp, self::file($pid));
        }
        Db::exec('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?', ['data_version_' . $pid, $v, $v]);
        return self::$v[$pid] = $v;
    }

    /** Pour les tests et les processus longs (worker). */
    public static function reset(): void
    {
        self::$v = [];
    }

    private static function file(int $pid): string
    {
        return Config::varDir('cache') . '/data_version_' . $pid;
    }
}
