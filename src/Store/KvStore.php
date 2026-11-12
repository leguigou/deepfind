<?php

declare(strict_types=1);

namespace DeepFind\Store;

/**
 * Petit stockage clé/valeur avec expiration : compteurs anti-robots, caches courts.
 */
interface KvStore
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttl): void;

    /** Incrémente et retourne la nouvelle valeur ; la clé expire $ttl secondes après sa création. */
    public function incr(string $key, int $ttl): int;

    public function delete(string $key): void;

    public function name(): string;

    /**
     * Statistiques par catégorie de clé (premier segment avant « : »).
     * @return array{categories: array<string, array{entries:int, bytes:int}>, memory: ?array{used:int, total:int}, hits: ?int, misses: ?int}
     */
    public function stats(): array;

    /** Supprime les clés commençant par l'un des préfixes ; retourne le nombre de clés supprimées. */
    public function purge(array $prefixes): int;
}
