<?php

declare(strict_types=1);

namespace DeepFind\Store;

/** Redis (extension php-redis). À privilégier si plusieurs serveurs web. */
final class RedisStore implements KvStore
{
    private \Redis $redis;

    public function __construct(string $url, private readonly string $prefix = 'df:')
    {
        $p = parse_url($url) ?: [];
        $this->redis = new \Redis();
        $this->redis->connect($p['host'] ?? '127.0.0.1', (int) ($p['port'] ?? 6379), 0.5);
        if (!empty($p['pass'])) {
            $this->redis->auth(isset($p['user']) ? [$p['user'], $p['pass']] : $p['pass']);
        }
        $db = (int) ltrim($p['path'] ?? '', '/');
        if ($db > 0) {
            $this->redis->select($db);
        }
    }

    public function get(string $key): mixed
    {
        $v = $this->redis->get($this->prefix . $key);
        return $v === false ? null : unserialize($v, ['allowed_classes' => false]);
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $this->redis->setex($this->prefix . $key, max(1, $ttl), serialize($value));
    }

    public function incr(string $key, int $ttl): int
    {
        $k = $this->prefix . $key;
        $v = (int) $this->redis->incr($k);
        if ($v === 1) {
            $this->redis->expire($k, max(1, $ttl));
        }
        return $v;
    }

    public function delete(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }

    public function name(): string
    {
        return 'redis';
    }

    public function stats(): array
    {
        $cats = [];
        $it = null;
        $this->redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
        while (($keys = $this->redis->scan($it, $this->prefix . '*', 500)) !== false) {
            foreach ($keys as $k) {
                $cat = strstr(substr($k, strlen($this->prefix)), ':', true) ?: 'autre';
                $cats[$cat]['entries'] = ($cats[$cat]['entries'] ?? 0) + 1;
                $cats[$cat]['bytes'] = ($cats[$cat]['bytes'] ?? 0) + (int) $this->redis->strlen($k);
            }
        }
        $info = $this->redis->info('memory');
        $stats = $this->redis->info('stats');
        return [
            'categories' => $cats,
            'memory' => isset($info['used_memory']) ? ['used' => (int) $info['used_memory'], 'total' => (int) ($info['maxmemory'] ?? 0)] : null,
            'hits' => isset($stats['keyspace_hits']) ? (int) $stats['keyspace_hits'] : null,
            'misses' => isset($stats['keyspace_misses']) ? (int) $stats['keyspace_misses'] : null,
        ];
    }

    public function purge(array $prefixes): int
    {
        $n = 0;
        $this->redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
        foreach ($prefixes as $p) {
            $it = null;
            while (($keys = $this->redis->scan($it, $this->prefix . $p . '*', 500)) !== false) {
                if ($keys !== []) {
                    $n += (int) $this->redis->del($keys);
                }
            }
        }
        return $n;
    }
}
