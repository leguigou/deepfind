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

    public function stats(): array
    {
        $cats = [];
        $it = new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/', APC_ITER_KEY | APC_ITER_MEM_SIZE);
        foreach ($it as $e) {
            $cat = strstr(substr($e['key'], strlen($this->prefix)), ':', true) ?: 'autre';
            $cats[$cat]['entries'] = ($cats[$cat]['entries'] ?? 0) + 1;
            $cats[$cat]['bytes'] = ($cats[$cat]['bytes'] ?? 0) + (int) $e['mem_size'];
        }
        $sma = @apcu_sma_info(true);
        $info = @apcu_cache_info(true);
        $total = $sma ? (int) $sma['num_seg'] * (int) $sma['seg_size'] : 0;
        return [
            'categories' => $cats,
            'memory' => $sma ? ['used' => $total - (int) $sma['avail_mem'], 'total' => $total] : null,
            'hits' => isset($info['num_hits']) ? (int) $info['num_hits'] : null,
            'misses' => isset($info['num_misses']) ? (int) $info['num_misses'] : null,
        ];
    }

    public function purge(array $prefixes): int
    {
        $n = 0;
        foreach ($prefixes as $p) {
            $it = new \APCUIterator('/^' . preg_quote($this->prefix . $p, '/') . '/', APC_ITER_KEY);
            $n += $it->getTotalCount();
            apcu_delete($it);
        }
        return $n;
    }
}
