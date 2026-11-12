<?php

declare(strict_types=1);

namespace DeepFind;

final class Text
{
    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i',
        'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ö' => 'o', 'ø' => 'o', 'œ' => 'oe', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
    ];

    /** Normalisation des requêtes pour les règles et les statistiques : minuscules, sans accents, espaces réduits. */
    public static function normalize(string $s, int $max = 100): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = strtr($s, self::ACCENTS);
        $s = preg_replace('/[\s\x{00A0}]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/[^\p{L}\p{N} \-_.\/\'+]/u', '', $s) ?? $s;
        return mb_substr(trim($s), 0, $max, 'UTF-8');
    }

    public static function stripHtml(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/li|/h\d)[^>]*>#i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    public static function truncate(string $s, int $max): string
    {
        return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') : $s;
    }

    public static function randomKey(string $prefix, int $bytes = 16): string
    {
        return $prefix . bin2hex(random_bytes($bytes));
    }

    public static function json(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
