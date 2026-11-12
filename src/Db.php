<?php

declare(strict_types=1);

namespace DeepFind;

use PDO;

/**
 * Accès MySQL/MariaDB (PDO, requêtes préparées uniquement).
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        return self::$pdo ??= self::connect();
    }

    /** Nouvelle connexion indépendante (utile pour les lectures non bufferisées pendant un import). */
    public static function connect(bool $buffered = true): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Config::get('DB_HOST', '127.0.0.1'),
            Config::int('DB_PORT', 3306),
            Config::get('DB_NAME', 'deepfind')
        );
        $pdo = new PDO($dsn, Config::get('DB_USER', 'deepfind'), Config::get('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => $buffered,
        ]);
        $pdo->exec("SET time_zone = '" . (new \DateTime())->format('P') . "'");
        return $pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** @param array<string,mixed> $data */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(',', array_map(static fn ($c) => "`$c`", $cols)),
            implode(',', array_fill(0, count($cols), '?'))
        );
        self::exec($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(',', array_map(static fn ($c) => "`$c` = ?", array_keys($data)));
        return self::exec("UPDATE `$table` SET $set WHERE $where", [...array_values($data), ...$params]);
    }
}
