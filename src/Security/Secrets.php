<?php

declare(strict_types=1);

namespace DeepFind\Security;

use DeepFind\Config;

/**
 * Chiffrement des secrets stockés en base (ex. mot de passe d'un flux protégé par .htpasswd).
 * libsodium (secretbox), clé dérivée de APP_SECRET : la base seule ne suffit pas pour lire le mot de passe.
 * Si APP_SECRET change, les secrets ne sont plus lisibles : il faut les ressaisir.
 */
final class Secrets
{
    private const PREFIX = 'v1:';

    private static function key(?string $secret = null): string
    {
        return hash_hkdf('sha256', $secret ?? (string) Config::get('APP_SECRET', ''), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'deepfind-secrets-v1');
    }

    public static function encrypt(string $plain, ?string $secret = null): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key($secret)));
    }

    /** Texte en clair, ou null si illisible (APP_SECRET changé, valeur abîmée). */
    public static function decrypt(?string $stored, ?string $secret = null): ?string
    {
        if ($stored === null || !str_starts_with($stored, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key($secret));
        return $plain === false ? null : $plain;
    }
}
