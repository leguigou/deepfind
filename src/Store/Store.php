<?php

declare(strict_types=1);

namespace DeepFind\Store;

use DeepFind\Config;

final class Store
{
    private static ?KvStore $instance = null;

    /** RATE_STORE=apcu|redis|mysql (repli automatique sur mysql si l'extension n'est pas disponible). */
    public static function get(): KvStore
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        $wanted = Config::get('RATE_STORE', 'apcu');
        $prefix = Config::indexPrefix();
        try {
            if ($wanted === 'redis' && class_exists(\Redis::class)) {
                return self::$instance = new RedisStore(Config::get('REDIS_URL', 'redis://127.0.0.1:6379'), $prefix);
            }
            if ($wanted === 'apcu' && ApcuStore::available()) {
                return self::$instance = new ApcuStore($prefix);
            }
        } catch (\Throwable $e) {
            error_log('[deepfind] store ' . $wanted . ' indisponible, repli MySQL : ' . $e->getMessage());
        }
        return self::$instance = new MysqlStore();
    }

    public static function set(?KvStore $store): void
    {
        self::$instance = $store;
    }
}
