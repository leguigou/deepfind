<?php

declare(strict_types=1);

namespace DeepFind\Security;

use DeepFind\Db;
use DeepFind\Store\Store;
use DeepFind\Text;

/**
 * Clés publiques utilisées par le widget JavaScript. Une clé est liée à une liste de domaines autorisés.
 * Attention : une clé publique est visible dans le code de la page ; la vraie protection, ce sont
 * le contrôle du domaine d'origine + les seuils anti-robots.
 */
final class ApiKeys
{
    public static function find(string $publicKey): ?array
    {
        if (!preg_match('/^pk_[a-f0-9]{32}$/', $publicKey)) {
            return null;
        }
        $store = Store::get();
        $cached = $store->get('key:' . $publicKey);
        if (is_array($cached)) {
            return $cached['id'] ? $cached : null;
        }
        $row = Db::one('SELECT id, name, public_key, allowed_origins, langs, active FROM api_keys WHERE public_key = ?', [$publicKey]);
        $store->set('key:' . $publicKey, $row ?? ['id' => 0], 60);
        return $row;
    }

    public static function create(string $name, string $origins, string $langs): string
    {
        $key = Text::randomKey('pk_');
        Db::insert('api_keys', [
            'name' => Text::truncate(trim($name), 100),
            'public_key' => $key,
            'allowed_origins' => self::cleanOrigins($origins),
            'langs' => preg_replace('/[^a-z,]/', '', strtolower($langs)) ?: 'fr',
            'active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $key;
    }

    public static function forget(string $publicKey): void
    {
        Store::get()->delete('key:' . $publicKey);
    }

    public static function cleanOrigins(string $origins): string
    {
        $hosts = [];
        foreach (preg_split('/[\s,]+/', strtolower($origins)) ?: [] as $o) {
            $o = trim($o);
            if ($o === '') {
                continue;
            }
            $host = parse_url(str_contains($o, '://') ? $o : 'https://' . $o, PHP_URL_HOST);
            if ($host) {
                $hosts[] = $host;
            }
        }
        return implode(',', array_unique($hosts));
    }

    /**
     * Vérifie le domaine appelant (en-tête Origin, sinon Referer).
     * Retourne l'origine à renvoyer dans Access-Control-Allow-Origin ('' = même domaine, pas d'en-tête), ou null si refusé.
     */
    public static function checkOrigin(array $key, array $server): ?string
    {
        $origin = (string) ($server['HTTP_ORIGIN'] ?? '');
        $source = $origin !== '' ? $origin : (string) ($server['HTTP_REFERER'] ?? '');
        $host = $source !== '' ? strtolower((string) parse_url($source, PHP_URL_HOST)) : '';
        $allowed = array_filter(explode(',', (string) $key['allowed_origins']));
        $self = strtolower((string) parse_url('http://' . ($server['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        if ($allowed === []) {
            return $origin !== '' ? $origin : '*';
        }
        if ($host === '') {
            return null;
        }
        if ($host === $self) {
            // Même domaine que l'API (ex. page de démo) : pas d'en-tête CORS nécessaire.
            return $origin !== '' ? $origin : '';
        }
        foreach ($allowed as $a) {
            if ($host === $a || str_ends_with($host, '.' . ltrim($a, '*.'))) {
                return $origin !== '' ? $origin : 'https://' . $host;
            }
        }
        return null;
    }
}
