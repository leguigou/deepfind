<?php

declare(strict_types=1);

namespace DeepFind\Push;

use DeepFind\Security\Secrets;
use DeepFind\Settings;

/**
 * Envoi de notifications Web Push sans service tiers ni librairie : standard des navigateurs.
 *  - VAPID (RFC 8292) : le serveur s'identifie auprès du service d'envoi du navigateur (Google, Mozilla, Apple…) par un jeton
 *    signé ES256 avec la clé privée de l'installation (générée une fois, gardée chiffrée avec APP_SECRET).
 *  - Chiffrement du message (RFC 8291, aes128gcm) : seul le navigateur abonné peut le lire (ECDH P-256 + HKDF + AES-128-GCM).
 * OpenSSL et cURL (extensions PHP déjà requises) suffisent.
 */
final class WebPush
{
    /** En-tête DER d'une clé publique EC P-256 (SubjectPublicKeyInfo), suivi des 65 octets du point non compressé. */
    private const P256_SPKI = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        $s = strtr(trim($s), '-_', '+/');
        return (string) base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /** Nouvelle paire de clés P-256 : publique (point non compressé, base64url) et privée (PEM). @return array{public:string, private:string} */
    public static function generateKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new \RuntimeException('Notifications : OpenSSL ne sait pas créer de clé P-256.');
        }
        openssl_pkey_export($key, $pem);
        return ['public' => self::b64u(self::rawPublic($key)), 'private' => (string) $pem];
    }

    /** Point public non compressé (0x04 || x || y, 65 octets) d'une clé EC. */
    public static function rawPublic(\OpenSSLAsymmetricKey $key): string
    {
        $d = openssl_pkey_get_details($key);
        return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** Clé publique OpenSSL à partir d'un point non compressé (65 octets). */
    public static function publicFromRaw(string $raw): \OpenSSLAsymmetricKey
    {
        if (strlen($raw) !== 65 || $raw[0] !== "\x04") {
            throw new \InvalidArgumentException('Clé publique P-256 invalide.');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::P256_SPKI) . $raw), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $k = openssl_pkey_get_public($pem);
        if ($k === false) {
            throw new \InvalidArgumentException('Clé publique P-256 illisible.');
        }
        return $k;
    }

    /** Clés VAPID de l'installation (créées à la première demande). @return array{public:string, private:string} */
    public static function vapid(): array
    {
        $pub = Settings::get('push_vapid_public');
        $priv = Secrets::decrypt(Settings::get('push_vapid_private') ?: null);
        if ($pub === '' || $priv === null || $priv === '') {
            $k = self::generateKeys();
            Settings::set('push_vapid_private', (string) Secrets::encrypt($k['private']));
            Settings::set('push_vapid_public', $k['public']);
            return $k;
        }
        return ['public' => $pub, 'private' => $priv];
    }

    /**
     * Message chiffré pour un abonné (corps de la requête, Content-Encoding: aes128gcm).
     * @param string $uaPublic clé p256dh de l'abonné (base64url) @param string $authSecret clé auth (base64url)
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, ?string $salt = null, ?\OpenSSLAsymmetricKey $ephemeral = null): string
    {
        $uaRaw = self::b64uDecode($uaPublic);
        $auth = self::b64uDecode($authSecret);
        if (strlen($auth) !== 16) {
            throw new \InvalidArgumentException('Clé auth invalide.');
        }
        $as = $ephemeral ?? openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $asRaw = self::rawPublic($as);
        $shared = openssl_pkey_derive(self::publicFromRaw($uaRaw), $as, 32);
        if ($shared === false) {
            throw new \RuntimeException('Notifications : échange de clés impossible.');
        }
        $salt ??= random_bytes(16);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaRaw . $asRaw, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        // un seul enregistrement : texte + délimiteur 0x02
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Notifications : chiffrement impossible.');
        }
        return $salt . pack('N', 4096) . chr(65) . $asRaw . $cipher . $tag;
    }

    /** Déchiffrement côté navigateur (tests, vérification). */
    public static function decrypt(string $body, \OpenSSLAsymmetricKey $uaPrivate, string $authSecret): string
    {
        $salt = substr($body, 0, 16);
        $idlen = ord($body[20]);
        $asRaw = substr($body, 21, $idlen);
        $rest = substr($body, 21 + $idlen);
        $uaRaw = self::rawPublic($uaPrivate);
        $shared = openssl_pkey_derive(self::publicFromRaw($asRaw), $uaPrivate, 32);
        $ikm = hash_hkdf('sha256', (string) $shared, 32, "WebPush: info\0" . $uaRaw . $asRaw, self::b64uDecode($authSecret));
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($rest, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($rest, -16));
        if ($plain === false) {
            throw new \RuntimeException('Déchiffrement impossible.');
        }
        // texte || 0x02 (dernier enregistrement) || zéros de remplissage
        return substr(rtrim($plain, "\0"), 0, -1);
    }

    /** Clé privée P-256 à partir de sa valeur brute (32 octets) et de son point public (tests : vecteurs de la RFC 8291). */
    public static function privateFromRaw(string $d, string $pubRaw): \OpenSSLAsymmetricKey
    {
        $der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $pubRaw;
        $k = openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n");
        if ($k === false) {
            throw new \InvalidArgumentException('Clé privée P-256 illisible.');
        }
        return $k;
    }

    /** En-tête Authorization VAPID pour l'adresse d'envoi (jeton valable 12 h). */
    public static function vapidHeader(string $endpoint, string $subject, ?array $keys = null, ?int $now = null): string
    {
        $keys ??= self::vapid();
        $p = parse_url($endpoint);
        $aud = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        $head = self::b64u('{"typ":"JWT","alg":"ES256"}');
        $claims = self::b64u(json_encode(['aud' => $aud, 'exp' => ($now ?? time()) + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        if (!openssl_sign($head . '.' . $claims, $der, $keys['private'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Notifications : signature VAPID impossible.');
        }
        return 'vapid t=' . $head . '.' . $claims . '.' . self::b64u(self::derToRaw($der)) . ', k=' . $keys['public'];
    }

    /** Signature ECDSA DER → r || s (64 octets), format attendu par JWT ES256. */
    public static function derToRaw(string $der): string
    {
        $pos = 2;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = ltrim(substr($der, $pos + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }

    /**
     * Envoie le même message à plusieurs abonnés (50 requêtes en parallèle).
     * @param list<array{id:int, endpoint:string, p256dh:string, auth:string}> $subs
     * @return array<int, int> identifiant d'abonné → code HTTP (0 : erreur réseau ou message impossible à chiffrer)
     */
    public static function send(array $subs, string $payload, string $subject, int $ttl = 86400, string $urgency = 'normal'): array
    {
        $keys = self::vapid();
        $out = [];
        foreach (array_chunk($subs, 50) as $chunk) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($chunk as $s) {
                try {
                    $body = self::encrypt($payload, $s['p256dh'], $s['auth']);
                    $auth = self::vapidHeader($s['endpoint'], $subject, $keys);
                } catch (\Throwable) {
                    $out[(int) $s['id']] = 0;
                    continue;
                }
                $ch = curl_init($s['endpoint']);
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_NOPROXY => '127.0.0.1,localhost',
                    CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => ['Authorization: ' . $auth, 'Content-Encoding: aes128gcm',
                        'Content-Type: application/octet-stream', 'TTL: ' . $ttl, 'Urgency: ' . $urgency]]);
                curl_multi_add_handle($mh, $ch);
                $handles[(int) $s['id']] = $ch;
            }
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($running && $status === CURLM_OK);
            foreach ($handles as $id => $ch) {
                $out[$id] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }
        return $out;
    }
}
