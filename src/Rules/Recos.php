<?php

declare(strict_types=1);

namespace DeepFind\Rules;

use DeepFind\Db;
use DeepFind\Store\Store;

/**
 * Recommandations calculées à partir de l'activité du site (sans donnée personnelle, totaux par jour) :
 *  - « souvent achetés ensemble » : produits d'une même commande (reco_pairs, kind b) ;
 *  - « les visiteurs ont aussi regardé » : produits ouverts par un même visiteur dans la même journée de mesure
 *    (clic dans la recherche ou un carrousel, fiche produit, ajout au panier ; reco_pairs, kind v) ;
 *  - « tendances » : produits dont l'intérêt (clics, paniers, commandes) monte sur les 7 derniers jours.
 * La liste des produits regardés d'un visiteur n'existe qu'en mémoire, le temps de sa journée (empreinte du jour).
 */
final class Recos
{
    /** Paires gardées (jours). */
    public const WINDOW_DAYS = 90;
    /** Produits regardés retenus par visiteur et par journée ; produits d'une commande pris en compte. */
    public const MAX_VIEWED = 20;
    public const MAX_ORDER_ITEMS = 20;
    /** Tendances : période récente comparée aux 4 semaines précédentes. */
    public const TREND_DAYS = 7;

    public const KINDS = ['b' => 'achetés ensemble', 'v' => 'regardés ensemble'];

    /** Paires (a → b et b → a) pour des produits vus ou achetés ensemble. @param list<string> $ids */
    public static function pairs(string $kind, array $ids, ?string $day = null): void
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($v) => substr(trim((string) $v), 0, 64), $ids), 'strlen')));
        if (!isset(self::KINDS[$kind]) || count($ids) < 2) {
            return;
        }
        $ids = array_slice($ids, 0, self::MAX_ORDER_ITEMS);
        $rows = [];
        $params = [];
        $pid = \DeepFind\Project::id();
        $day ??= \DeepFind\Logging\Audience::measureDay();
        foreach ($ids as $a) {
            foreach ($ids as $b) {
                if ($a !== $b) {
                    $rows[] = '(?, ?, ?, ?, ?, 1)';
                    array_push($params, $pid, $kind, $day, $a, $b);
                }
            }
        }
        Db::exec('INSERT INTO reco_pairs (project_id, kind, day, a, b, n) VALUES ' . implode(', ', $rows) . ' ON DUPLICATE KEY UPDATE n = n + 1', $params);
    }

    /**
     * Produit regardé par le visiteur (empreinte du jour) : paire avec chacun des produits qu'il a déjà regardés aujourd'hui.
     * Liste gardée en mémoire seulement (30 h au plus), jamais en base.
     */
    public static function viewed(string $vhash, string $productId, ?string $day = null): void
    {
        $productId = substr(trim($productId), 0, 64);
        if ($vhash === '' || $productId === '') {
            return;
        }
        $store = Store::get();
        $key = 'rv:' . \DeepFind\Project::id() . ':' . $vhash;
        $seen = $store->get($key);
        $seen = is_array($seen) ? $seen : [];
        if (in_array($productId, $seen, true)) {
            return;
        }
        if ($seen !== []) {
            $rows = [];
            $params = [];
            $pid = \DeepFind\Project::id();
            $day ??= \DeepFind\Logging\Audience::measureDay();
            foreach ($seen as $other) {
                $rows[] = '(?, ?, ?, ?, ?, 1)';
                array_push($params, $pid, 'v', $day, $productId, $other);
                $rows[] = '(?, ?, ?, ?, ?, 1)';
                array_push($params, $pid, 'v', $day, $other, $productId);
            }
            Db::exec('INSERT INTO reco_pairs (project_id, kind, day, a, b, n) VALUES ' . implode(', ', $rows) . ' ON DUPLICATE KEY UPDATE n = n + 1', $params);
        }
        $seen[] = $productId;
        $store->set($key, array_slice($seen, -self::MAX_VIEWED), 30 * 3600);
    }

    /**
     * Produits le plus souvent achetés (b) ou regardés (v) avec ceux de $ids, du plus fréquent au moins fréquent.
     * @param list<string> $ids @param list<string> $exclude @return list<string>
     */
    public static function related(string $kind, array $ids, int $limit = 48, array $exclude = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), 'strlen')));
        if (!isset(self::KINDS[$kind]) || $ids === []) {
            return [];
        }
        $ids = array_slice($ids, 0, 30);
        $store = Store::get();
        $key = 'reco:' . \DeepFind\Project::id() . ':' . $kind . ':' . md5(implode(',', $ids)) . ':' . $limit;
        $cached = $store->get($key);
        if (!is_array($cached)) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $cached = array_map(static fn ($r) => (string) $r['b'], Db::all(
                "SELECT b, SUM(n) AS s FROM reco_pairs WHERE project_id = ? AND kind = ? AND a IN ($in) AND day >= ? AND b NOT IN ($in)
                 GROUP BY b ORDER BY s DESC, b LIMIT " . max(1, min(200, $limit + 30)),
                [\DeepFind\Project::id(), $kind, ...$ids, date('Y-m-d', time() - self::WINDOW_DAYS * 86400), ...$ids]));
            $store->set($key, $cached, 600);
        }
        return array_slice(array_values(array_diff($cached, $exclude)), 0, $limit);
    }

    /**
     * Tendances : intérêt des 7 derniers jours (clics + 3 × paniers + 5 × commandes) rapporté à la moyenne hebdomadaire des
     * 4 semaines d'avant. Un produit nouveau qui démarre fort monte ; un produit toujours fort mais stable reste derrière.
     * @return list<string> identifiants, du plus « montant » au moins
     */
    public static function trending(int $limit = 48): array
    {
        $store = Store::get();
        $key = 'reco:trend:' . \DeepFind\Project::id() . ':' . \DeepFind\Logging\Audience::measureDay() . ':' . $limit;
        $cached = $store->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $today = \DeepFind\Logging\Audience::measureDay();
        $recentFrom = date('Y-m-d', strtotime($today . ' -' . (self::TREND_DAYS - 1) . ' days'));
        $prevFrom = date('Y-m-d', strtotime($recentFrom . ' -28 days'));
        // sous-requête : MariaDB refuse un calcul sur des totaux nommés dans ORDER BY
        $rows = Db::all(
            "SELECT product_id, recent, prev FROM (
                SELECT product_id,
                    SUM(IF(day >= ?, clicks + 3 * carts + 5 * orders, 0)) AS recent,
                    SUM(IF(day < ?, clicks + 3 * carts + 5 * orders, 0)) / 4 AS prev
                FROM audience_products WHERE project_id = ? AND day >= ? GROUP BY product_id
             ) t WHERE recent >= 3 ORDER BY recent / (prev + 3) DESC, recent DESC, product_id LIMIT " . max(1, min(200, $limit)),
            [$recentFrom, $recentFrom, \DeepFind\Project::id(), $prevFrom]);
        $ids = array_map(static fn ($r) => (string) $r['product_id'], $rows);
        $store->set($key, $ids, 600);
        return $ids;
    }

    public static function purge(): int
    {
        return Db::exec('DELETE FROM reco_pairs WHERE day < ? LIMIT 200000', [date('Y-m-d', time() - self::WINDOW_DAYS * 86400)]);
    }
}
