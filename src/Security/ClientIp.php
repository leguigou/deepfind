<?php

declare(strict_types=1);

namespace DeepFind\Security;

use DeepFind\Config;

final class ClientIp
{
    /** IP réelle du visiteur (X-Forwarded-For pris en compte uniquement derrière un proxy de confiance). */
    public static function resolve(array $server): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = array_filter(array_map('trim', explode(',', (string) Config::get('TRUSTED_PROXIES', ''))));
        if ($trusted === [] || !self::inList($remote, $trusted)) {
            return $remote;
        }
        $xff = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
        $chain = array_reverse(array_filter(array_map('trim', explode(',', $xff))));
        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) && !self::inList($ip, $trusted)) {
                return $ip;
            }
        }
        return $remote;
    }

    /** Empreinte non réversible (HMAC) : permet de compter/bloquer sans stocker l'IP en clair. */
    public static function hash(string $ip): string
    {
        return substr(hash_hmac('sha256', $ip, (string) Config::get('APP_SECRET', 'change-me')), 0, 16);
    }

    /** IP tronquée pour l'affichage (IPv4 : dernier octet masqué ; IPv6 : /48). */
    public static function mask(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);
            return $p[0] . '.' . $p[1] . '.' . $p[2] . '.x';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            $masked = substr((string) $bin, 0, 6) . str_repeat("\0", 10);
            return inet_ntop($masked) . '/48';
        }
        return 'inconnue';
    }

    /** @param list<string> $list IP ou plages CIDR */
    public static function inList(string $ip, array $list): bool
    {
        foreach ($list as $entry) {
            if ($entry === $ip || (str_contains($entry, '/') && self::inCidr($ip, $entry))) {
                return true;
            }
        }
        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr, 2) + [1 => ''];
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}
