<?php

declare(strict_types=1);

namespace DeepFind\Store;

use DeepFind\Db;

/** Solution de repli sans extension supplémentaire (table kv_store). Plus lent qu'APCu/Redis. */
final class MysqlStore implements KvStore
{
    public function get(string $key): mixed
    {
        $row = Db::one('SELECT v FROM kv_store WHERE k = ? AND expires_at >= ?', [$key, time()]);
        return $row === null ? null : unserialize($row['v'], ['allowed_classes' => false]);
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $exp = time() + max(1, $ttl);
        $v = serialize($value);
        Db::exec('INSERT INTO kv_store (k, v, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = ?, expires_at = ?', [$key, $v, $exp, $v, $exp]);
    }

    public function incr(string $key, int $ttl): int
    {
        $now = time();
        $exp = $now + max(1, $ttl);
        // L'ordre des affectations compte : v est recalculé avant expires_at (qui garde l'ancienne valeur pendant le calcul de v).
        Db::exec(
            "INSERT INTO kv_store (k, v, expires_at) VALUES (?, 'i:1;', ?)
             ON DUPLICATE KEY UPDATE
               v = IF(expires_at < ?, 'i:1;', CONCAT('i:', CAST(SUBSTRING(v, 3, LENGTH(v) - 3) AS UNSIGNED) + 1, ';')),
               expires_at = IF(expires_at < ?, ?, expires_at)",
            [$key, $exp, $now, $now, $exp]
        );
        $v = Db::value('SELECT v FROM kv_store WHERE k = ?', [$key]);
        return (int) substr((string) $v, 2);
    }

    public function delete(string $key): void
    {
        Db::exec('DELETE FROM kv_store WHERE k = ?', [$key]);
    }

    public function name(): string
    {
        return 'mysql';
    }

    public function stats(): array
    {
        $cats = [];
        $rows = Db::all(
            "SELECT SUBSTRING_INDEX(k, ':', 1) AS cat, COUNT(*) AS n, SUM(LENGTH(k) + LENGTH(v)) AS b
             FROM kv_store WHERE expires_at >= ? GROUP BY cat",
            [time()]
        );
        foreach ($rows as $r) {
            $cats[$r['cat']] = ['entries' => (int) $r['n'], 'bytes' => (int) $r['b']];
        }
        return ['categories' => $cats, 'memory' => null, 'hits' => null, 'misses' => null];
    }

    public function purge(array $prefixes): int
    {
        $n = 0;
        foreach ($prefixes as $p) {
            $n += Db::exec('DELETE FROM kv_store WHERE k LIKE ?', [addcslashes($p, '%_\\') . '%']);
        }
        return $n;
    }

    public static function purgeExpired(): int
    {
        return Db::exec('DELETE FROM kv_store WHERE expires_at < ?', [time()]);
    }
}
