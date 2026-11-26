<?php

declare(strict_types=1);

namespace DeepFind\Rules;

use DeepFind\Db;
use DeepFind\Logging\Audience;
use DeepFind\Search\Filter;
use DeepFind\Text;

/**
 * Campagnes de produits à pousser (Recommandations › Campagnes) : une liste de produits (choisis un par un, et/ou par règles
 * comme les boosts : marque, catégorie, promotion…), une période, un objectif, et le suivi
 * de leurs résultats pendant la période comparés à la période précédente de même durée :
 * clics (recherche, carrousels), affichages dans les carrousels, position moyenne des clics dans la recherche, ajouts au panier,
 * commandes, chiffre d'affaires, et chiffre d'affaires par emplacement (où le produit a été cliqué avant d'être acheté).
 * Données : totaux par jour de la mesure d'audience (aucune donnée personnelle).
 */
final class Campaigns
{
    public const MAX_ITEMS = 100;
    /** Produits pris en compte par les règles (les plus nombreux sont ignorés au-delà ; le tableau en montre MAX_ITEMS). */
    public const RULE_LIMIT = 1000;
    /** Force de la mise en avant dans la recherche (coefficient, comme une règle de boost). */
    public const BOOSTS = ['1.5' => 'Légère (×1,5)', '2' => 'Moyenne (×2)', '3' => 'Forte (×3)'];
    /** Places en tête des carrousels : 1 à 6. */
    public const MAX_SLOTS = 6;
    /** Produits mis en avant dans les carrousels : les plus susceptibles d'être vendus (ventes, clics, score métier). */
    public const PICK_SORT = ['sales_30d:desc', 'clicks_30d:desc', 'biz_score:desc'];
    /** Sources de carrousel où la mise en avant s'applique (choix automatique) ; pas les produits choisis à la main, le panier, l'historique. */
    public const CAROUSEL_SOURCES = ['auto', 'similar', 'trending'];

    /** @return list<array> */
    public static function all(string $lang): array
    {
        return array_map([self::class, 'decode'], Db::all('SELECT * FROM campaigns WHERE project_id = ? AND lang = ? ORDER BY active DESC, COALESCE(ends_at, \'9999-12-31\') DESC, id DESC',
            [\DeepFind\Project::id(), $lang]));
    }

    public static function find(int $id, string $lang): ?array
    {
        $r = Db::one('SELECT * FROM campaigns WHERE id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]);
        return $r ? self::decode($r) : null;
    }

    public static function blank(): array
    {
        return ['id' => 0, 'name' => '', 'active' => true, 'starts_at' => null, 'ends_at' => null, 'objective' => '', 'items' => [], 'match_all' => true, 'conditions' => [],
            'highlight_search' => false, 'search_boost' => 2.0, 'highlight_carousels' => false, 'carousel_slots' => 2, 'created_at' => date('Y-m-d H:i:s')];
    }

    private static function decode(array $r): array
    {
        return ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'active' => (int) $r['active'] === 1, 'starts_at' => $r['starts_at'] ?: null,
            'ends_at' => $r['ends_at'] ?: null, 'objective' => (string) $r['objective'], 'items' => json_decode((string) $r['items'], true) ?: [],
            'match_all' => (int) ($r['match_all'] ?? 1) === 1, 'conditions' => json_decode((string) ($r['conditions'] ?? ''), true) ?: [],
            'highlight_search' => (int) ($r['highlight_search'] ?? 0) === 1, 'search_boost' => (float) ($r['search_boost'] ?? 2),
            'highlight_carousels' => (int) ($r['highlight_carousels'] ?? 0) === 1, 'carousel_slots' => (int) ($r['carousel_slots'] ?? 2), 'created_at' => (string) $r['created_at']];
    }

    /** @return array{0:?array, 1:list<string>} */
    public static function sanitize(array $in, string $lang = 'fr'): array
    {
        $errors = [];
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 190, 'UTF-8');
        if ($name === '') {
            $errors[] = 'Le nom est obligatoire.';
        }
        $date = static fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $v)) && strtotime((string) $v) !== false ? trim((string) $v) : null;
        $start = $date($in['starts_at'] ?? '');
        $end = $date($in['ends_at'] ?? '');
        if ($start !== null && $end !== null && $end < $start) {
            $errors[] = 'La date de fin est antérieure à la date de début.';
        }
        $items = [];
        $raw = is_array($in['items'] ?? null) ? $in['items'] : preg_split('/[\s,;]+/u', (string) ($in['items'] ?? ''));
        foreach ($raw ?: [] as $sku) {
            $sku = mb_substr(trim((string) $sku), 0, 64, 'UTF-8');
            if ($sku !== '' && !in_array(mb_strtoupper($sku, 'UTF-8'), array_map(static fn ($s) => mb_strtoupper($s, 'UTF-8'), $items), true)) {
                $items[] = $sku;
            }
        }
        // règles : mêmes conditions que les boosts et les carrousels (« contient » accepté)
        [$rules, $condErrors] = CustomResults::sanitizeRules([['match' => ($in['match'] ?? 'all') === 'any' ? 'any' : 'all', 'conditions' => (array) ($in['conditions'] ?? [])]], $lang, true);
        array_push($errors, ...$condErrors);
        $conds = $rules[0]['conditions'] ?? [];
        if ($items === [] && $conds === [] && $condErrors === []) {
            $errors[] = 'Ajoutez au moins un produit à pousser, ou une règle.';
        }
        if (count($items) > self::MAX_ITEMS) {
            $errors[] = self::MAX_ITEMS . ' produits au plus par campagne.';
        }
        return $errors !== [] ? [null, $errors] : [['name' => $name, 'active' => !empty($in['active']), 'starts_at' => $start, 'ends_at' => $end,
            'objective' => mb_substr(trim((string) ($in['objective'] ?? '')), 0, 500, 'UTF-8'), 'items' => $items,
            'match_all' => $rules[0]['match_all'] ?? true, 'conditions' => $conds,
            'highlight_search' => !empty($in['highlight_search']), 'search_boost' => (float) (isset(self::BOOSTS[(string) ($in['search_boost'] ?? '')]) ? $in['search_boost'] : 2),
            'highlight_carousels' => !empty($in['highlight_carousels']), 'carousel_slots' => max(1, min(self::MAX_SLOTS, (int) ($in['carousel_slots'] ?? 2) ?: 2))], []];
    }

    public static function save(string $lang, array $c, ?int $id): int
    {
        $row = ['name' => $c['name'], 'active' => $c['active'] ? 1 : 0, 'starts_at' => $c['starts_at'], 'ends_at' => $c['ends_at'],
            'objective' => $c['objective'], 'items' => Text::json($c['items']),
            'match_all' => $c['match_all'] ? 1 : 0, 'conditions' => $c['conditions'] !== [] ? Text::json($c['conditions']) : null,
            'highlight_search' => $c['highlight_search'] ? 1 : 0, 'search_boost' => $c['search_boost'], 'highlight_carousels' => $c['highlight_carousels'] ? 1 : 0,
            'carousel_slots' => $c['carousel_slots'], 'updated_at' => date('Y-m-d H:i:s')];
        $pid = \DeepFind\Project::id();
        if ($id !== null && self::find($id, $lang) !== null) {
            Db::update('campaigns', $row, 'id = ? AND project_id = ? AND lang = ?', [$id, $pid, $lang]);
        } else {
            $id = Db::insert('campaigns', $row + ['project_id' => $pid, 'lang' => $lang, 'created_at' => $row['updated_at']]);
        }
        \DeepFind\CacheVersion::bump(); // mise en avant : recherche et carrousels recalculés tout de suite
        return $id;
    }

    public static function delete(int $id, string $lang): void
    {
        if (Db::exec('DELETE FROM campaigns WHERE id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]) > 0) {
            \DeepFind\CacheVersion::bump();
        }
    }

    /** à venir | en cours | terminée | désactivée */
    public static function status(array $c, ?string $today = null): string
    {
        $today ??= Audience::measureDay();
        if (!$c['active']) {
            return 'off';
        }
        if ($c['starts_at'] !== null && $c['starts_at'] > $today) {
            return 'upcoming';
        }
        return $c['ends_at'] !== null && $c['ends_at'] < $today ? 'ended' : 'current';
    }

    /**
     * Période suivie (début : date de début, sinon création ; fin : date de fin, sinon aujourd'hui) et période précédente de même durée.
     * @return array{from:string, to:string, prev_from:string, prev_to:string, days:int}
     */
    public static function period(array $c, ?string $today = null): array
    {
        $today ??= Audience::measureDay();
        // sans date de début : journée de mesure de la création (une campagne créée à 1 h du matin compte la journée en cours)
        $from = $c['starts_at'] ?? Audience::measureDay((int) strtotime((string) $c['created_at']));
        $to = min($c['ends_at'] ?? $today, $today);
        $to = max($to, $from);
        $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
        $prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
        return ['from' => $from, 'to' => $to, 'prev_from' => date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days')), 'prev_to' => $prevTo, 'days' => $days];
    }

    /** Produits de la campagne trouvés dans le catalogue : SKU demandé → document. @return array<string, array> */
    public static function products(string $lang, array $skus): array
    {
        $skus = array_values(array_filter(array_map(static fn ($s) => mb_strtoupper(trim((string) $s), 'UTF-8'), $skus), 'strlen'));
        if ($skus === []) {
            return [];
        }
        try {
            $res = \DeepFind\Meili\MeiliClient::search()->multiSearch([['indexUid' => \DeepFind\Config::productIndex($lang), 'q' => '',
                'filter' => [Filter::in('all_skus', $skus)], 'limit' => count($skus) * 2,
                'attributesToRetrieve' => ['id', 'sku', 'all_skus', 'name', 'image', 'in_stock', 'final_price', 'url', 'brand']]]);
        } catch (\Throwable $e) {
            error_log('[deepfind] campagnes : ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($res[0]['hits'] ?? [] as $doc) {
            foreach ((array) ($doc['all_skus'] ?? [$doc['sku'] ?? '']) as $s) {
                $s = mb_strtoupper((string) $s, 'UTF-8');
                if (in_array($s, $skus, true) && !isset($out[$s])) {
                    $out[$s] = $doc;
                }
            }
        }
        return $out;
    }

    /**
     * Produits du catalogue qui remplissent aujourd'hui les règles de la campagne (RULE_LIMIT au plus).
     * @return array{docs: list<array>, total: int, error: ?string}
     */
    public static function ruleProducts(string $lang, array $c): array
    {
        if (($c['conditions'] ?? []) === []) {
            return ['docs' => [], 'total' => 0, 'error' => null];
        }
        $filter = PageRules::filter(['match_all' => $c['match_all'], 'conditions' => $c['conditions']], $lang);
        try {
            $res = \DeepFind\Meili\MeiliClient::search()->multiSearch([['indexUid' => \DeepFind\Config::productIndex($lang), 'q' => '',
                'filter' => $filter !== null ? [$filter] : [], 'limit' => self::RULE_LIMIT,
                'attributesToRetrieve' => ['id', 'sku', 'all_skus', 'name', 'image', 'in_stock', 'final_price', 'url', 'brand']]]);
        } catch (\Throwable $e) {
            error_log('[deepfind] campagnes (règles) : ' . $e->getMessage());
            return ['docs' => [], 'total' => 0, 'error' => 'Catalogue indisponible : produits des règles non calculés.'];
        }
        $docs = array_values($res[0]['hits'] ?? []);
        return ['docs' => $docs, 'total' => max(count($docs), (int) ($res[0]['estimatedTotalHits'] ?? $res[0]['totalHits'] ?? 0)), 'error' => null];
    }

    /**
     * Résultats de la campagne.
     * @return array{period: array, rows: list<array>, total: array, prev: array, placements: list<array>}
     */
    public static function stats(array $c, string $lang): array
    {
        $p = self::period($c);
        $docs = self::products($lang, $c['items']);
        // produits des règles qui ne sont pas déjà dans la liste
        $manualIds = array_map(static fn ($d) => (string) $d['id'], $docs);
        $rule = self::ruleProducts($lang, $c);
        $ruleDocs = array_values(array_filter($rule['docs'], static fn ($d) => !in_array((string) $d['id'], $manualIds, true)));
        $ids = array_values(array_unique([...$manualIds, ...array_map(static fn ($d) => (string) $d['id'], $ruleDocs)]));
        $pid = \DeepFind\Project::id();
        $zero = ['clicks' => 0, 'carts' => 0, 'orders' => 0, 'revenue' => 0];
        $sum = static function (string $from, string $to) use ($ids, $pid): array {
            if ($ids === []) {
                return [];
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $out = [];
            foreach (Db::all("SELECT product_id, SUM(clicks) AS clicks, SUM(carts) AS carts, SUM(orders) AS orders, SUM(revenue) AS revenue
                FROM audience_products WHERE project_id = ? AND day BETWEEN ? AND ? AND product_id IN ($in) GROUP BY product_id", [$pid, $from, $to, ...$ids]) as $r) {
                $out[(string) $r['product_id']] = ['clicks' => (int) $r['clicks'], 'carts' => (int) $r['carts'], 'orders' => (int) $r['orders'], 'revenue' => (int) $r['revenue']];
            }
            return $out;
        };
        $now = $sum($p['from'], $p['to']);
        $before = $sum($p['prev_from'], $p['prev_to']);
        $views = [];
        $pos = [];
        $placements = [];
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::all("SELECT product_id, SUM(views) AS v FROM carousel_product_stats WHERE project_id = ? AND day BETWEEN ? AND ? AND product_id IN ($in) GROUP BY product_id",
                [$pid, $p['from'], $p['to'], ...$ids]) as $r) {
                $views[(string) $r['product_id']] = (int) $r['v'];
            }
            foreach (Db::all("SELECT doc_id, COUNT(*) AS n, AVG(position) AS pos FROM click_logs WHERE project_id = ? AND lang = ? AND doc_type = 'product'
                AND created_at BETWEEN ? AND ? AND doc_id IN ($in) GROUP BY doc_id", [$pid, $lang, $p['from'] . ' 00:00:00', $p['to'] . ' 23:59:59', ...$ids]) as $r) {
                $pos[(string) $r['doc_id']] = ['n' => (int) $r['n'], 'pos' => round((float) $r['pos'], 1)];
            }
            foreach (Db::all("SELECT placement, SUM(clicks) AS clicks, SUM(carts) AS carts, SUM(orders) AS orders, SUM(revenue) AS revenue FROM audience_attrib
                WHERE project_id = ? AND day BETWEEN ? AND ? AND product_id IN ($in) GROUP BY placement ORDER BY revenue DESC, clicks DESC", [$pid, $p['from'], $p['to'], ...$ids]) as $r) {
                $placements[] = ['placement' => (string) $r['placement'], 'label' => Audience::placementLabel((string) $r['placement'], $lang),
                    'clicks' => (int) $r['clicks'], 'carts' => (int) $r['carts'], 'orders' => (int) $r['orders'], 'revenue' => (int) $r['revenue']];
            }
        }
        $rows = [];
        $total = $zero + ['car_views' => 0];
        $prev = $zero;
        $lines = array_map(static fn ($sku) => [$sku, $docs[mb_strtoupper($sku, 'UTF-8')] ?? null, false], $c['items']);
        foreach ($ruleDocs as $d) {
            $lines[] = [(string) ($d['sku'] ?? $d['id']), $d, true];
        }
        foreach ($lines as [$sku, $doc, $byRule]) {
            $id = $doc ? (string) $doc['id'] : '';
            $m = $now[$id] ?? $zero;
            $b = $before[$id] ?? $zero;
            $rows[] = ['sku' => $sku, 'id' => $id, 'found' => $doc !== null, 'name' => (string) ($doc['name'] ?? ''), 'image' => (string) ($doc['image'] ?? ''),
                'in_stock' => !empty($doc['in_stock']), 'price' => $doc['final_price'] ?? null, 'car_views' => $views[$id] ?? 0,
                'search_clicks' => $pos[$id]['n'] ?? 0, 'avg_pos' => $pos[$id]['pos'] ?? null, 'prev' => $b, 'rule' => $byRule] + $m;
            foreach ($zero as $k => $_) {
                $total[$k] += $m[$k];
                $prev[$k] += $b[$k];
            }
            $total['car_views'] += $views[$id] ?? 0;
        }
        // tableau : produits choisis un par un, puis ceux des règles les plus actifs (MAX_ITEMS en tout) ; totaux sur tous
        $manualRows = array_values(array_filter($rows, static fn ($r) => !$r['rule']));
        $ruleRows = array_values(array_filter($rows, static fn ($r) => $r['rule']));
        usort($ruleRows, static fn ($a, $b) => [$b['revenue'], $b['orders'], $b['carts'], $b['clicks'], $b['car_views']] <=> [$a['revenue'], $a['orders'], $a['carts'], $a['clicks'], $a['car_views']]);
        $shown = array_slice($ruleRows, 0, max(0, self::MAX_ITEMS - count($manualRows)));
        return ['period' => $p, 'rows' => [...$manualRows, ...$shown], 'total' => $total, 'prev' => $prev, 'placements' => $placements,
            'rule' => ['count' => count($ruleDocs), 'total' => $rule['total'], 'hidden' => count($ruleRows) - count($shown), 'error' => $rule['error'],
                'capped' => $rule['total'] > self::RULE_LIMIT]];
    }

    // ------------------------------------------------------------------ Mise en avant pendant la campagne

    /** Jour calendaire (heure de Paris) : la mise en avant va du jour de début à 0 h au jour de fin à 23 h 59. */
    public static function today(?int $ts = null): string
    {
        return (new \DateTimeImmutable('@' . ($ts ?? time())))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d');
    }

    /** La mise en avant de cette campagne est-elle en cours ce jour-là ? */
    public static function isLive(array $c, ?string $day = null): bool
    {
        $day ??= self::today();
        return $c['active'] && ($c['highlight_search'] || $c['highlight_carousels'])
            && ($c['starts_at'] === null || $c['starts_at'] <= $day) && ($c['ends_at'] === null || $c['ends_at'] >= $day);
    }

    /**
     * Campagnes mises en avant aujourd'hui, avec les identifiants de leurs produits (liste + règles, catalogue du jour).
     * Cache : version des données (changée à chaque enregistrement, import, et au changement de jour par schedule:run).
     * @return list<array{id:int, name:string, search:bool, boost:float, carousels:bool, slots:int, ids:list<string>}>
     */
    public static function live(string $lang): array
    {
        $day = self::today();
        $store = \DeepFind\Store\Store::get();
        $key = 'camplive:' . \DeepFind\Project::id() . ':' . $lang . ':' . \DeepFind\CacheVersion::get() . ':' . $day;
        $cached = $store->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $out = [];
        foreach (Db::all('SELECT * FROM campaigns WHERE project_id = ? AND lang = ? AND active = 1 AND (highlight_search = 1 OR highlight_carousels = 1)',
            [\DeepFind\Project::id(), $lang]) as $r) {
            $c = self::decode($r);
            if (!self::isLive($c, $day)) {
                continue;
            }
            $ids = array_map(static fn ($d) => (string) $d['id'], self::products($lang, $c['items']));
            foreach (self::ruleProducts($lang, $c)['docs'] as $d) {
                $ids[] = (string) $d['id'];
            }
            $ids = array_values(array_unique($ids));
            if ($ids !== []) {
                $out[] = ['id' => $c['id'], 'name' => $c['name'], 'search' => $c['highlight_search'], 'boost' => $c['search_boost'],
                    'carousels' => $c['highlight_carousels'], 'slots' => $c['carousel_slots'], 'ids' => $ids];
            }
        }
        $store->set($key, $out, 600);
        return $out;
    }

    /** Règles de boost « virtuelles » des campagnes mises en avant dans la recherche (produits en stock seulement). @return list<array> */
    public static function searchRules(string $lang): array
    {
        $rules = [];
        foreach (self::live($lang) as $h) {
            if ($h['search']) {
                $rules[] = ['id' => 0, 'campaign' => $h['id'], 'name' => 'Campagne « ' . $h['name'] . ' »', 'active' => true, 'boost' => $h['boost'], 'terms' => [],
                    'match_all' => true, 'conditions' => [['field' => 'id', 'values' => $h['ids']], ['field' => 'in_stock', 'values' => ['1']]]];
            }
        }
        return $rules;
    }

    /**
     * Changement de jour (appelé chaque minute par schedule:run) : si l'ensemble des campagnes mises en avant change
     * (début ou fin), la version des données du projet change, et la recherche et les carrousels sont recalculés.
     * @return int projets mis à jour
     */
    public static function syncLive(): int
    {
        $n = 0;
        $day = self::today();
        foreach (Db::all('SELECT DISTINCT project_id FROM campaigns WHERE highlight_search = 1 OR highlight_carousels = 1') as $p) {
            $pid = (int) $p['project_id'];
            $live = [];
            foreach (Db::all('SELECT * FROM campaigns WHERE project_id = ? AND active = 1 AND (highlight_search = 1 OR highlight_carousels = 1)', [$pid]) as $r) {
                if (self::isLive(self::decode($r), $day)) {
                    $live[] = (int) $r['id'];
                }
            }
            $sig = $live === [] ? '-' : implode(',', $live);
            $name = 'campaign_live_' . $pid;
            if ((string) (Db::value('SELECT value FROM settings WHERE name = ?', [$name]) ?? '') !== $sig) {
                Db::exec('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?', [$name, $sig, $sig]);
                \DeepFind\CacheVersion::bump($pid);
                $n++;
            }
        }
        return $n;
    }
}
