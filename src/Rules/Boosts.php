<?php

declare(strict_types=1);

namespace DeepFind\Rules;

use DeepFind\Db;
use DeepFind\Text;

/**
 * Signaux « métier » ajoutés aux produits en plus du flux :
 *  - boosts saisis dans l'extranet (-10 à +10, avec dates facultatives) ;
 *  - nombre de clics sur 30 jours (popularité dans la recherche).
 * Ils entrent dans le score métier (biz_score) calculé par ProductBuilder.
 */
final class Boosts
{
    /** @return array<string,int> SKU (majuscules) => boost actif aujourd'hui */
    public static function active(string $lang): array
    {
        $today = date('Y-m-d');
        $out = [];
        foreach (Db::all(
            'SELECT sku, boost FROM product_boosts WHERE lang = ? AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at >= ?)',
            [$lang, $today, $today]
        ) as $r) {
            $out[mb_strtoupper($r['sku'], 'UTF-8')] = max(-10, min(10, (int) $r['boost']));
        }
        return $out;
    }

    /** @return array<string,int> identifiant du document => clics produits sur 30 jours */
    public static function clicks(string $lang, int $days = 30): array
    {
        $out = [];
        foreach (Db::all(
            "SELECT doc_id, COUNT(*) AS n FROM click_logs WHERE lang = ? AND doc_type = 'product' AND created_at >= ? GROUP BY doc_id",
            [$lang, date('Y-m-d H:i:s', time() - $days * 86400)]
        ) as $r) {
            $out[(string) $r['doc_id']] = (int) $r['n'];
        }
        return $out;
    }

    public static function save(string $lang, string $sku, int $boost, string $start, string $end, string $note): void
    {
        $sku = trim($sku);
        $boost = max(-10, min(10, $boost));
        $start = self::date($start);
        $end = self::date($end);
        $note = Text::truncate(trim($note), 190);
        Db::exec(
            'INSERT INTO product_boosts (lang, sku, boost, starts_at, ends_at, note, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE boost = ?, starts_at = ?, ends_at = ?, note = ?',
            [$lang, $sku, $boost, $start, $end, $note, $boost, $start, $end, $note]
        );
    }

    private static function date(string $s): ?string
    {
        $s = trim($s);
        $t = $s !== '' ? strtotime($s) : false;
        return $t === false ? null : date('Y-m-d', $t);
    }
}
