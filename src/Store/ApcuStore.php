<?php

declare(strict_types=1);

namespace DeepFind\Store;

/** Mémoire partagée du serveur PHP (le plus rapide, une seule machine). Extension php-apcu. */
final class ApcuStore implements KvStore
{
    public function __construct(private readonly string $prefix = 'df:')
    {
    }

    public static function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function get(string $key): mixed
    {
        $ok = false;
        $v = apcu_fetch($this->prefix . $key, $ok);
        return $ok ? $v : null;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        apcu_store($this->prefix . $key, $value, $ttl);
    }

    public function incr(string $key, int $ttl): int
    {
        $k = $this->prefix . $key;
        apcu_add($k, 0, $ttl);
        $ok = false;
        $v = apcu_inc($k, 1, $ok, $ttl);
        return $ok ? (int) $v : 1;
    }

    public function delete(string $key): void
    {
        apcu_delete($this->prefix . $key);
    }

    public function name(): string
    {
        return 'apcu';
    }
}
