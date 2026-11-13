<?php

declare(strict_types=1);

namespace DeepFind\Rules;

use DeepFind\Db;
use DeepFind\Search\Filter;
use DeepFind\Search\Relevance;
use DeepFind\Settings;
use DeepFind\Store\Store;
use DeepFind\Text;

/**
 * Résultats personnalisés : pour certains termes de recherche (exacts ou larges), en continu ou sur une période,
 *  - mode « first » : produits sélectionnés affichés en premier, puis les résultats naturels ;
 *  - mode « only »  : seuls les produits sélectionnés sont affichés ;
 *  - produits exclus (masqués pour ces recherches).
 * Les produits sont choisis un par un (SKU, dans l'ordre voulu) ou par règle (conditions sur des champs filtrables).
 * Remplace les anciennes « règles de résultats » (épingler / remonter / masquer), converties par migrateQueryRules().
 */
final class CustomResults
{
    /** Produits ajoutés par règle en mode « first » (au-delà, ils restent dans les résultats naturels). */
    public const RULE_ITEMS_MAX = 48;

    /** Champs utilisables dans les règles (filtrables par le moteur) : [libellé, type]. */
    public const FIELDS = [
        'brand' => ['Marque', 'list'],
        'category_names' => ['Catégorie', 'list'],
        'type' => ['Type de produit', 'list'],
        'sku' => ['SKU', 'list'],
        'in_stock' => ['Disponibilité', 'list'],
        'on_sale' => ['En promotion', 'list'],
        'is_new' => ['Nouveauté', 'list'],
        'final_price' => ['Prix (€)', 'num'],
        'margin_rate' => ['Marge (%)', 'num'],
    ];

    public const LIST_OPS = ['in' => 'est', 'not_in' => 'n\'est pas'];
    public const NUM_OPS = ['gte' => '≥', 'lte' => '≤'];

    /** @return array<string, array{0:string,1:string}> champs fixes + attributs filtrables (attr.<code>) */
    public static function fields(string $lang): array
    {
        $out = self::FIELDS;
        foreach (Settings::json('attr_labels_' . $lang) as $code => $label) {
            $out['attr.' . $code] = [(string) $label, 'list'];
        }
        return $out;
    }

    // ------------------------------------------------------------------ Lecture / écriture

    /** @return list<array> */
    public static function all(string $lang): array
    {
        return array_map([self::class, 'decode'], Db::all('SELECT * FROM custom_results WHERE project_id = ? AND lang = ? ORDER BY active DESC, name, id',
            [\DeepFind\Project::id(), $lang]));
    }

    public static function find(int $id, string $lang): ?array
    {
        $row = Db::one('SELECT * FROM custom_results WHERE id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]);
        return $row ? self::decode($row) : null;
    }

    private static function decode(array $row): array
    {
        $j = static fn ($v) => json_decode((string) $v, true) ?: [];
        return [
            'id' => (int) $row['id'], 'name' => (string) $row['name'], 'active' => (int) $row['active'] === 1,
            'starts_at' => $row['starts_at'] ?: null, 'ends_at' => $row['ends_at'] ?: null,
            'mode' => $row['mode'] === 'only' ? 'only' : 'first', 'terms' => $j($row['terms']),
            'include_items' => $j($row['include_items']), 'include_rules' => $j($row['include_rules']),
            'exclude_items' => $j($row['exclude_items']), 'exclude_rules' => $j($row['exclude_rules']),
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    /** « current » (en cours), « scheduled » (pas encore commencé) ou « expired » (terminé). */
    public static function period(array $cr, ?string $today = null): string
    {
        $today ??= date('Y-m-d');
        if (!empty($cr['starts_at']) && $cr['starts_at'] > $today) {
            return 'scheduled';
        }
        if (!empty($cr['ends_at']) && $cr['ends_at'] < $today) {
            return 'expired';
        }
        return 'current';
    }

    /** Résultats actifs aujourd'hui (cache invalidé par CacheVersion ; la date du jour fait partie de la clé). */
    public static function active(string $lang): array
    {
        $today = date('Y-m-d');
        $store = Store::get();
        $key = 'customres:' . \DeepFind\Project::id() . ':' . $lang . ':' . $today . ':' . \DeepFind\CacheVersion::get();
        $cached = $store->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $list = array_values(array_filter(self::all($lang), static fn ($c) => $c['active'] && $c['terms'] !== [] && self::period($c, $today) === 'current'));
        $store->set($key, $list, 300);
        return $list;
    }

    /** Résultats personnalisés qui s'appliquent à cette recherche. @return list<array> */
    public static function forQuery(string $lang, string $q): array
    {
        $qNorm = Text::normalize($q);
        if ($qNorm === '') {
            return [];
        }
        $list = self::active($lang);
        if ($list === []) {
            return [];
        }
        $qWords = Relevance::words($q, $lang);
        return array_values(array_filter($list, static fn ($c) => BoostRules::termsMatch($c['terms'], $qNorm, $qWords, $lang)));
    }

    private static function date(mixed $v): ?string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
    }

    /**
     * Valide et nettoie un résultat envoyé par le formulaire.
     * @return array{0:?array, 1:list<string>}
     */
    public static function sanitize(array $in, string $lang): array
    {
        $errors = [];
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 190, 'UTF-8');
        if ($name === '') {
            $errors[] = 'Le nom est obligatoire.';
        }
        $start = $end = null;
        if (($in['duration'] ?? 'always') === 'period') {
            $start = self::date($in['starts_at'] ?? '');
            $end = self::date($in['ends_at'] ?? '');
            if ($start === null || $end === null) {
                $errors[] = 'Choisissez la date de début et la date de fin de la période.';
            } elseif ($end < $start) {
                $errors[] = 'La date de fin est antérieure à la date de début.';
            }
        }
        $terms = [];
        foreach (array_slice((array) ($in['terms'] ?? []), 0, 200) as $t) {
            $term = mb_strtolower(mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($t['term'] ?? ''))), 0, 100, 'UTF-8'), 'UTF-8');
            $match = ($t['match'] ?? '') === 'broad' ? 'broad' : 'exact';
            if (Text::normalize($term) !== '') {
                $terms[$term . '|' . $match] = ['term' => $term, 'match' => $match];
            }
        }
        if ($terms === []) {
            $errors[] = 'Ajoutez au moins un terme de recherche.';
        }
        $items = static fn ($list) => array_values(array_unique(array_filter(array_map(
            static fn ($s) => mb_strtoupper(mb_substr(trim((string) $s), 0, 190, 'UTF-8'), 'UTF-8'),
            array_slice((array) $list, 0, 500)
        ), 'strlen')));
        [$incRules, $e1] = self::sanitizeRules((array) ($in['include_rules'] ?? []), $lang);
        [$excRules, $e2] = self::sanitizeRules((array) ($in['exclude_rules'] ?? []), $lang);
        $errors = [...$errors, ...$e1, ...$e2];
        $inc = $items($in['include_items'] ?? []);
        $exc = array_values(array_diff($items($in['exclude_items'] ?? []), $inc));
        $mode = ($in['mode'] ?? '') === 'only' ? 'only' : 'first';
        if ($inc === [] && $incRules === [] && ($mode === 'only' || ($exc === [] && $excRules === []))) {
            $errors[] = $mode === 'only'
                ? 'Mode « uniquement les résultats sélectionnés » : ajoutez au moins un produit ou une règle dans « Résultats inclus ».'
                : 'Ajoutez au moins un résultat inclus ou exclu.';
        }
        if ($errors !== []) {
            return [null, array_values(array_unique($errors))];
        }
        return [[
            'name' => $name, 'active' => !empty($in['active']), 'starts_at' => $start, 'ends_at' => $end, 'mode' => $mode,
            'terms' => array_values($terms), 'include_items' => $inc, 'include_rules' => $incRules,
            'exclude_items' => $exc, 'exclude_rules' => $excRules,
        ], []];
    }

    /** @return array{0:list<array>, 1:list<string>} */
    private static function sanitizeRules(array $rules, string $lang): array
    {
        $fields = self::fields($lang);
        $out = [];
        $errors = [];
        foreach (array_slice($rules, 0, 20) as $rule) {
            $conds = [];
            foreach (array_slice((array) ($rule['conditions'] ?? []), 0, 20) as $c) {
                $field = (string) ($c['field'] ?? '');
                if (!isset($fields[$field])) {
                    continue;
                }
                if ($fields[$field][1] === 'num') {
                    $v = str_replace(',', '.', trim((string) ($c['value'] ?? '')));
                    if (!is_numeric($v)) {
                        $errors[] = 'Règle « ' . $fields[$field][0] . ' » : valeur numérique attendue.';
                        continue;
                    }
                    $conds[] = ['field' => $field, 'op' => ($c['op'] ?? '') === 'lte' ? 'lte' : 'gte', 'value' => (float) $v];
                    continue;
                }
                $values = array_values(array_unique(array_filter(array_map(
                    static fn ($v) => mb_substr(trim((string) $v), 0, 190, 'UTF-8'),
                    array_slice((array) ($c['values'] ?? []), 0, 500)
                ), 'strlen')));
                if ($values === []) {
                    $errors[] = 'Règle « ' . $fields[$field][0] . ' » : choisissez au moins une valeur.';
                    continue;
                }
                $conds[] = ['field' => $field, 'op' => ($c['op'] ?? '') === 'not_in' ? 'not_in' : 'in', 'values' => $values];
            }
            if ($conds !== []) {
                $out[] = ['match_all' => ($rule['match'] ?? 'all') !== 'any', 'conditions' => $conds];
            }
        }
        return [$out, $errors];
    }

    /** @return int identifiant */
    public static function save(string $lang, array $c, ?int $id): int
    {
        $row = [
            'name' => $c['name'], 'active' => $c['active'] ? 1 : 0, 'starts_at' => $c['starts_at'], 'ends_at' => $c['ends_at'],
            'mode' => $c['mode'], 'terms' => Text::json($c['terms']),
            'include_items' => Text::json(array_values($c['include_items'])), 'include_rules' => Text::json($c['include_rules']),
            'exclude_items' => Text::json(array_values($c['exclude_items'])), 'exclude_rules' => Text::json($c['exclude_rules']),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($id) {
            Db::update('custom_results', $row, 'id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]);
        } else {
            $id = Db::insert('custom_results', $row + ['project_id' => \DeepFind\Project::id(), 'lang' => $lang, 'created_at' => $row['updated_at']]);
        }
        \DeepFind\CacheVersion::bump();
        return (int) $id;
    }

    public static function delete(int $id, string $lang): void
    {
        Db::exec('DELETE FROM custom_results WHERE id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]);
        \DeepFind\CacheVersion::bump();
    }

    // ------------------------------------------------------------------ Filtres moteur

    /** Expression Meilisearch d'une règle (conditions toutes / au moins une), ou null si vide. */
    public static function ruleFilter(array $rule): ?string
    {
        $parts = [];
        foreach ($rule['conditions'] ?? [] as $c) {
            $field = $c['field'] === 'sku' ? 'all_skus' : (str_starts_with($c['field'], 'attr.') ? 'attr.' . \DeepFind\Import\Mapper::code(substr($c['field'], 5)) : $c['field']);
            if (!preg_match('/^[a-z_]+(\.[a-z0-9_]+)?$/', $field)) {
                continue;
            }
            if (isset($c['value'])) {
                $v = (float) $c['value'];
                if ($c['field'] === 'margin_rate') {
                    $v /= 100;
                }
                $parts[] = $field . ' ' . ($c['op'] === 'lte' ? '<=' : '>=') . ' ' . rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
                continue;
            }
            $values = $c['field'] === 'sku' ? array_map(static fn ($s) => mb_strtoupper((string) $s, 'UTF-8'), $c['values']) : array_map('strval', $c['values']);
            $parts[] = ($c['op'] ?? 'in') === 'not_in' ? Filter::notIn($field, $values) : Filter::in($field, $values);
        }
        if ($parts === []) {
            return null;
        }
        return '(' . implode(!empty($rule['match_all']) ? ' AND ' : ' OR ', $parts) . ')';
    }

    /**
     * Ce qui s'applique à une recherche (plusieurs résultats personnalisés se combinent).
     * @return array{ids: list<int>, names: array<int,string>, only: bool, include_items: list<string>, item_owner: array<string,int>,
     *               include_filters: list<string>, rule_owner: ?int, exclude_items: list<string>, exclude_filters: list<string>}
     */
    public static function plan(array $matched): array
    {
        $p = ['ids' => [], 'names' => [], 'only' => false, 'include_items' => [], 'item_owner' => [], 'include_filters' => [], 'rule_owner' => null,
            'exclude_items' => [], 'exclude_filters' => []];
        foreach ($matched as $c) {
            $p['ids'][] = $c['id'];
            $p['names'][$c['id']] = $c['name'];
            $p['only'] = $p['only'] || $c['mode'] === 'only';
            foreach ($c['include_items'] as $sku) {
                $sku = mb_strtoupper((string) $sku, 'UTF-8');
                if (!isset($p['item_owner'][$sku])) {
                    $p['include_items'][] = $sku;
                    $p['item_owner'][$sku] = $c['id'];
                }
            }
            foreach ($c['include_rules'] as $r) {
                $f = self::ruleFilter($r);
                if ($f !== null) {
                    $p['include_filters'][] = $f;
                    $p['rule_owner'] ??= $c['id'];
                }
            }
            foreach ($c['exclude_items'] as $sku) {
                $p['exclude_items'][] = mb_strtoupper((string) $sku, 'UTF-8');
            }
            foreach ($c['exclude_rules'] as $r) {
                $f = self::ruleFilter($r);
                if ($f !== null) {
                    $p['exclude_filters'][] = 'NOT ' . $f;
                }
            }
        }
        $p['exclude_items'] = array_values(array_diff(array_unique($p['exclude_items']), $p['include_items']));
        return $p;
    }

    // ------------------------------------------------------------------ Épingler depuis l'aperçu interne

    public static function pinName(string $q): string
    {
        return 'Recherche « ' . Text::normalize($q) . ' »';
    }

    /** Produits choisis un par un pour cette recherche exacte : SKU => [id du résultat, position]. */
    public static function pinsFor(string $lang, string $q): array
    {
        $out = [];
        foreach (self::forQuery($lang, $q) as $c) {
            foreach ($c['include_items'] as $i => $sku) {
                $out[mb_strtoupper((string) $sku, 'UTF-8')] ??= ['id' => $c['id'], 'position' => $i + 1, 'name' => $c['name']];
            }
        }
        return $out;
    }

    /** Épingle un produit à une position pour une recherche exacte (crée ou complète le résultat « Recherche « q » »). */
    public static function pin(string $lang, string $q, string $sku, int $position): int
    {
        $qn = Text::normalize($q);
        $sku = mb_strtoupper(trim($sku), 'UTF-8');
        $target = null;
        foreach (self::all($lang) as $c) {
            if ($c['terms'] === [['term' => $qn, 'match' => 'exact']] && $c['starts_at'] === null && $c['ends_at'] === null) {
                $target = $c;
                break;
            }
        }
        $target ??= ['name' => self::pinName($q), 'active' => true, 'starts_at' => null, 'ends_at' => null, 'mode' => 'first',
            'terms' => [['term' => $qn, 'match' => 'exact']], 'include_items' => [], 'include_rules' => [], 'exclude_items' => [], 'exclude_rules' => []];
        $target['active'] = true;
        $list = array_values(array_filter($target['include_items'], static fn ($s) => mb_strtoupper((string) $s, 'UTF-8') !== $sku));
        array_splice($list, max(0, min(count($list), $position - 1)), 0, [$sku]);
        $target['include_items'] = $list;
        $target['exclude_items'] = array_values(array_diff($target['exclude_items'], [$sku]));
        return self::save($lang, $target, $target['id'] ?? null);
    }

    /** Retire un produit choisi un par un ; supprime le résultat s'il ne contient plus rien. */
    public static function unpin(string $lang, int $id, string $sku): void
    {
        $c = self::find($id, $lang);
        if ($c === null) {
            return;
        }
        $sku = mb_strtoupper(trim($sku), 'UTF-8');
        $c['include_items'] = array_values(array_filter($c['include_items'], static fn ($s) => mb_strtoupper((string) $s, 'UTF-8') !== $sku));
        if ($c['include_items'] === [] && $c['include_rules'] === [] && $c['exclude_items'] === [] && $c['exclude_rules'] === []) {
            self::delete($id, $lang);
            return;
        }
        if ($c['mode'] === 'only' && $c['include_items'] === [] && $c['include_rules'] === []) {
            $c['mode'] = 'first';
        }
        self::save($lang, $c, $id);
    }

    // ------------------------------------------------------------------ Conversion des anciennes règles de résultats

    /**
     * Les règles « épingler / remonter / masquer » d'une requête deviennent un résultat personnalisé (terme exact, mode « first ») :
     * produits remontés puis épinglés (par position) en « inclus », masqués en « exclus ». Une période différente donne un résultat
     * distinct ; une règle terminée n'est pas reprise. Les produits désactivés partout (requête « * ») restent dans query_rules.
     */
    public static function migrateQueryRules(): int
    {
        $today = date('Y-m-d');
        $rows = Db::all("SELECT * FROM query_rules WHERE query <> '*' ORDER BY project_id, lang, query, starts_at, ends_at, (action = 'pin'), position, id");
        if ($rows === []) {
            return 0;
        }
        $groups = [];
        foreach ($rows as $r) {
            $k = $r['project_id'] . '|' . $r['lang'] . '|' . $r['query'] . '|' . ($r['starts_at'] ?? '') . '|' . ($r['ends_at'] ?? '');
            $groups[$k][] = $r;
        }
        $n = 0;
        foreach ($groups as $list) {
            $f = $list[0];
            Db::exec('DELETE FROM query_rules WHERE id IN (' . implode(',', array_map(static fn ($r) => (int) $r['id'], $list)) . ')');
            if (!empty($f['ends_at']) && $f['ends_at'] < $today) {
                continue;
            }
            $inc = $exc = [];
            foreach ($list as $r) {
                $sku = mb_strtoupper(trim((string) $r['sku']), 'UTF-8');
                if ($r['action'] === 'hide') {
                    $exc[] = $sku;
                } else {
                    $inc[] = $sku;
                }
            }
            $now = date('Y-m-d H:i:s');
            Db::insert('custom_results', [
                'project_id' => (int) $f['project_id'], 'lang' => $f['lang'], 'name' => self::pinName((string) $f['query']), 'active' => 1,
                'starts_at' => $f['starts_at'] ?: null, 'ends_at' => $f['ends_at'] ?: null, 'mode' => 'first',
                'terms' => Text::json([['term' => (string) $f['query'], 'match' => 'exact']]),
                'include_items' => Text::json(array_values(array_unique($inc))), 'include_rules' => '[]',
                'exclude_items' => Text::json(array_values(array_diff(array_unique($exc), $inc))), 'exclude_rules' => '[]',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $n++;
        }
        \DeepFind\CacheVersion::bump();
        return $n;
    }

    // ------------------------------------------------------------------ Statistiques

    /**
     * Impressions (recherches finales où le résultat s'est appliqué), clics sur ses produits, CTR, sur N jours.
     * @return array<int, array{impressions:int, clicks:int, ctr:?float}>
     */
    public static function stats(string $lang, array $ids, int $days): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        foreach ($ids as $id) {
            $out[(int) $id] = ['impressions' => 0, 'clicks' => 0, 'ctr' => null];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (Db::all("SELECT custom_result_id AS id, COUNT(*) AS n FROM click_logs WHERE project_id = ? AND lang = ? AND created_at >= ? AND custom_result_id IN ($in) GROUP BY custom_result_id",
            [\DeepFind\Project::id(), $lang, $since, ...array_map('intval', $ids)]) as $r) {
            $out[(int) $r['id']]['clicks'] = (int) $r['n'];
        }
        foreach (Db::all("SELECT custom_ids FROM search_logs WHERE project_id = ? AND lang = ? AND created_at >= ? AND is_final = 1 AND custom_ids IS NOT NULL",
            [\DeepFind\Project::id(), $lang, $since]) as $r) {
            foreach (array_filter(explode(',', (string) $r['custom_ids'])) as $id) {
                if (isset($out[(int) $id])) {
                    $out[(int) $id]['impressions']++;
                }
            }
        }
        foreach ($out as &$s) {
            $s['ctr'] = $s['impressions'] > 0 ? round(100 * $s['clicks'] / $s['impressions'], 1) : null;
        }
        unset($s);
        return $out;
    }

    /** Résumé lisible d'une règle d'inclusion / exclusion. */
    public static function describeRule(array $rule, string $lang): string
    {
        $fields = self::fields($lang);
        $parts = [];
        foreach ($rule['conditions'] as $c) {
            $label = $fields[$c['field']][0] ?? $c['field'];
            if (isset($c['value'])) {
                $parts[] = $label . ' ' . self::NUM_OPS[$c['op']] . ' ' . rtrim(rtrim(number_format((float) $c['value'], 2, ',', ''), '0'), ',');
            } else {
                $static = BoostRules::STATIC_VALUES[$c['field']] ?? [];
                $vals = array_map(static fn ($v) => $static[$v] ?? $v, $c['values']);
                $parts[] = $label . ' ' . self::LIST_OPS[$c['op'] ?? 'in'] . ' ' . implode(', ', array_slice($vals, 0, 6)) . (count($vals) > 6 ? ' (+' . (count($vals) - 6) . ')' : '');
            }
        }
        return implode(!empty($rule['match_all']) ? ' ET ' : ' OU ', $parts);
    }
}
