<?php

declare(strict_types=1);

namespace DeepFind\Rules;

use DeepFind\Db;
use DeepFind\Search\Relevance;
use DeepFind\Settings;
use DeepFind\Store\Store;
use DeepFind\Text;

/**
 * Règles de boost : « multiplier par 1,5 la pertinence des produits de la catégorie Plongée
 * (et de la marque Cressi) quand on cherche palme ».
 * Appliquées par SearchService au réordonnancement des premiers résultats (tri « pertinence » uniquement).
 */
final class BoostRules
{
    public const BOOST_MIN = 0.0;
    public const BOOST_MAX = 10.0;

    /** Champs utilisables dans les conditions : [libellé, type] — list = valeurs à cocher, num = comparaison. */
    public const FIELDS = [
        'brand' => ['Marque', 'list'],
        'category_names' => ['Catégorie', 'list'],
        'type' => ['Type de produit', 'list'],
        'sku' => ['SKU', 'list'],
        'in_stock' => ['Disponibilité', 'list'],
        'on_sale' => ['En promotion', 'list'],
        'is_new' => ['Nouveauté', 'list'],
        'final_price' => ['Prix (€)', 'num'],
        'discount_percent' => ['Remise (%)', 'num'],
        'margin_rate' => ['Marge (%)', 'num'],
        'sales_30d' => ['Ventes 30 j', 'num'],
    ];

    /** Valeurs fixes des champs oui/non. */
    public const STATIC_VALUES = [
        'in_stock' => ['1' => 'En stock', '0' => 'Rupture'],
        'on_sale' => ['1' => 'Oui', '0' => 'Non'],
        'is_new' => ['1' => 'Oui', '0' => 'Non'],
    ];

    public const OPS = ['gte' => '≥', 'lte' => '≤'];

    /** Coefficients proposés pour le boost rapide d'un produit (aperçu interne). */
    public const QUICK = ['0' => '×0 (fin de liste)', '0.5' => '×0,5', '0.75' => '×0,75', '1' => 'Aucun', '1.25' => '×1,25', '1.5' => '×1,5', '2' => '×2', '3' => '×3', '5' => '×5'];

    /** Champs du document à récupérer pour évaluer les conditions. */
    public const DOC_FIELDS = ['brand', 'category_names', 'type', 'sku', 'all_skus', 'in_stock', 'on_sale', 'is_new', 'final_price',
        'discount_percent', 'margin_rate', 'sales_30d', 'attr'];

    /** @return array<string, array{0:string,1:string}> champs fixes + attributs (attr.<code>) */
    public static function fields(string $lang): array
    {
        $out = self::FIELDS;
        foreach (Settings::json('attr_labels_' . $lang) as $code => $label) {
            $out['attr.' . $code] = [(string) $label, 'list'];
        }
        return $out;
    }

    // ------------------------------------------------------------------ Lecture / écriture

    /** @return list<array> règles du projet et de la langue (décodées) */
    public static function all(string $lang): array
    {
        return array_map([self::class, 'decode'], Db::all('SELECT * FROM boost_rules WHERE project_id = ? AND lang = ? ORDER BY active DESC, name, id',
            [\DeepFind\Project::id(), $lang]));
    }

    /** Règles actives (cache invalidé par CacheVersion). @return list<array> */
    public static function active(string $lang): array
    {
        $store = Store::get();
        $key = 'boostrules:' . \DeepFind\Project::id() . ':' . $lang . ':' . \DeepFind\CacheVersion::get();
        $cached = $store->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $rules = array_values(array_filter(self::all($lang), static fn ($r) => $r['active'] && $r['conditions'] !== [] && abs($r['boost'] - 1.0) > 0.0001));
        $store->set($key, $rules, 300);
        return $rules;
    }

    private static function decode(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'active' => (int) $row['active'] === 1,
            'boost' => (float) $row['boost'],
            'terms' => json_decode((string) $row['terms'], true) ?: [],
            'match_all' => (int) $row['match_all'] === 1,
            'conditions' => json_decode((string) $row['conditions'], true) ?: [],
        ];
    }

    /**
     * Valide et nettoie une règle envoyée par le formulaire.
     * @return array{0:?array, 1:list<string>} [règle propre ou null, erreurs]
     */
    public static function sanitize(array $in, string $lang): array
    {
        $errors = [];
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 190, 'UTF-8');
        if ($name === '') {
            $errors[] = 'Le nom de la règle est obligatoire.';
        }
        $boost = (float) str_replace(',', '.', (string) ($in['boost'] ?? '1'));
        if ($boost < self::BOOST_MIN || $boost > self::BOOST_MAX) {
            $errors[] = sprintf('Le boost doit être compris entre %s et %s.', self::BOOST_MIN, self::BOOST_MAX);
        }
        $terms = [];
        if (!empty($in['terms_enabled'])) {
            foreach (array_slice((array) ($in['terms'] ?? []), 0, 200) as $t) {
                // affiché tel que saisi (accents conservés) ; comparé sous forme normalisée
                $term = mb_strtolower(mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($t['term'] ?? ''))), 0, 100, 'UTF-8'), 'UTF-8');
                if (Text::normalize($term) !== '') {
                    $terms[$term . '|' . (($t['match'] ?? '') === 'broad' ? 'broad' : 'exact')] = ['term' => $term, 'match' => ($t['match'] ?? '') === 'broad' ? 'broad' : 'exact'];
                }
            }
            if ($terms === []) {
                $errors[] = 'Ajoutez au moins un terme de recherche, ou désactivez « Termes de recherche ».';
            }
        }
        $fields = self::fields($lang);
        $conds = [];
        foreach (array_slice((array) ($in['conditions'] ?? []), 0, 30) as $c) {
            $field = (string) ($c['field'] ?? '');
            if (!isset($fields[$field])) {
                continue;
            }
            if ($fields[$field][1] === 'num') {
                $v = str_replace(',', '.', trim((string) ($c['value'] ?? '')));
                if (!is_numeric($v)) {
                    $errors[] = 'Condition « ' . $fields[$field][0] . ' » : valeur numérique attendue.';
                    continue;
                }
                $conds[] = ['field' => $field, 'op' => ($c['op'] ?? '') === 'lte' ? 'lte' : 'gte', 'value' => (float) $v];
            } else {
                $values = array_values(array_unique(array_filter(array_map(
                    static fn ($v) => mb_substr(trim((string) $v), 0, 190, 'UTF-8'),
                    array_slice((array) ($c['values'] ?? []), 0, 500)
                ), 'strlen')));
                if ($values === []) {
                    $errors[] = 'Condition « ' . $fields[$field][0] . ' » : choisissez au moins une valeur.';
                    continue;
                }
                $conds[] = ['field' => $field, 'values' => $values];
            }
        }
        if ($conds === [] && $errors === []) {
            $errors[] = 'Ajoutez au moins une condition (sinon la règle boosterait tous les produits de la même façon).';
        }
        if ($errors !== []) {
            return [null, $errors];
        }
        return [[
            'name' => $name, 'active' => !empty($in['active']), 'boost' => round($boost, 2), 'terms' => array_values($terms),
            'match_all' => ($in['match'] ?? 'all') !== 'any', 'conditions' => $conds,
        ], []];
    }

    public static function save(string $lang, array $rule, ?int $id): void
    {
        $row = [
            'name' => $rule['name'], 'active' => $rule['active'] ? 1 : 0, 'boost' => $rule['boost'],
            'terms' => Text::json($rule['terms']), 'match_all' => $rule['match_all'] ? 1 : 0,
            'conditions' => Text::json($rule['conditions']),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($id) {
            Db::update('boost_rules', $row, 'id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]);
        } else {
            Db::insert('boost_rules', $row + ['project_id' => \DeepFind\Project::id(), 'lang' => $lang, 'created_at' => $row['updated_at']]);
        }
        \DeepFind\CacheVersion::bump();
    }

    /** Nom de la règle créée par le boost rapide d'un produit (aperçu interne). */
    public static function productRuleName(string $sku): string
    {
        return 'Produit ' . mb_strtoupper(trim($sku), 'UTF-8');
    }

    /** Règle « boost rapide » d'un produit : sans terme, une seule condition SKU = ce produit. */
    public static function productRule(string $lang, string $sku): ?array
    {
        $sku = mb_strtoupper(trim($sku), 'UTF-8');
        foreach (self::all($lang) as $r) {
            if (self::quickSku($r) === $sku) {
                return $r;
            }
        }
        return null;
    }

    /** @return array<string, float> SKU => coefficient des règles « boost rapide » */
    public static function productBoosts(string $lang): array
    {
        $out = [];
        foreach (self::all($lang) as $r) {
            $sku = self::quickSku($r);
            if ($sku !== null && !isset($out[$sku])) {
                $out[$sku] = $r['boost'];
            }
        }
        return $out;
    }

    private static function quickSku(array $r): ?string
    {
        if ($r['terms'] !== [] || count($r['conditions']) !== 1 || ($r['conditions'][0]['field'] ?? '') !== 'sku' || count($r['conditions'][0]['values'] ?? []) !== 1) {
            return null;
        }
        return mb_strtoupper((string) $r['conditions'][0]['values'][0], 'UTF-8');
    }

    /** Boost rapide : crée, modifie ou supprime (coefficient 1) la règle du produit. */
    public static function setProductBoost(string $lang, string $sku, float $boost): void
    {
        $sku = mb_strtoupper(trim($sku), 'UTF-8');
        $existing = self::productRule($lang, $sku);
        if (abs($boost - 1.0) < 0.0001) {
            if ($existing !== null) {
                Db::exec('DELETE FROM boost_rules WHERE id = ? AND project_id = ?', [$existing['id'], \DeepFind\Project::id()]);
                \DeepFind\CacheVersion::bump();
            }
            return;
        }
        $rule = $existing ?? ['name' => self::productRuleName($sku), 'terms' => [], 'match_all' => true,
            'conditions' => [['field' => 'sku', 'values' => [$sku]]]];
        $rule['active'] = true;
        $rule['boost'] = max(self::BOOST_MIN, min(self::BOOST_MAX, round($boost, 2)));
        self::save($lang, $rule, $existing['id'] ?? null);
    }

    /**
     * Conversion des anciens « boosts produits » (-10 à +10, points de score métier) en règles de boost :
     * +n → ×(1 + n/10) (+10 = ×2), -n → ×(1 - n/20) (-10 = ×0,5). Les règles n'ont pas de dates : un boost terminé n'est pas
     * repris, un boost pas encore commencé devient une règle inactive, une date de fin est rappelée dans le nom. Rejouable.
     */
    public static function migrateProductBoosts(): int
    {
        $exists = Db::value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_boosts'");
        if ($exists === null) {
            return 0;
        }
        $n = 0;
        $today = date('Y-m-d');
        foreach (Db::all('SELECT * FROM product_boosts ORDER BY id') as $b) {
            if (!empty($b['ends_at']) && $b['ends_at'] < $today) {
                Db::exec('DELETE FROM product_boosts WHERE id = ?', [$b['id']]);
                continue;
            }
            $future = !empty($b['starts_at']) && $b['starts_at'] > $today;
            $suffix = trim((string) $b['note']) !== '' ? ' — ' . trim((string) $b['note']) : '';
            if ($future) {
                $suffix .= ' (prévu le ' . date('d/m/Y', (int) strtotime((string) $b['starts_at'])) . ')';
            }
            if (!empty($b['ends_at'])) {
                $suffix .= ' (jusqu\'au ' . date('d/m/Y', (int) strtotime((string) $b['ends_at'])) . ')';
            }
            $v = (int) $b['boost'];
            $sku = mb_strtoupper(trim((string) $b['sku']), 'UTF-8');
            $now = date('Y-m-d H:i:s');
            Db::insert('boost_rules', [
                'project_id' => (int) $b['project_id'], 'lang' => $b['lang'],
                'name' => mb_substr(self::productRuleName($sku) . $suffix, 0, 190, 'UTF-8'),
                'active' => $future ? 0 : 1, 'boost' => round($v >= 0 ? 1 + $v / 10 : 1 + $v / 20, 2), 'terms' => '[]', 'match_all' => 1,
                'conditions' => Text::json([['field' => 'sku', 'values' => [$sku]]]),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            Db::exec('DELETE FROM product_boosts WHERE id = ?', [$b['id']]);
            $n++;
        }
        if ($n > 0) {
            \DeepFind\CacheVersion::bump();
        }
        return $n;
    }

    // ------------------------------------------------------------------ Application

    /** Règles actives qui concernent cette recherche (sans terme = toutes les recherches). @return list<array> */
    public static function forQuery(string $lang, string $q): array
    {
        $rules = self::active($lang);
        if ($rules === []) {
            return [];
        }
        $qNorm = Text::normalize($q);
        $qWords = Relevance::words($q, $lang);
        return array_values(array_filter($rules, static fn ($r) => self::termsMatch($r['terms'], $qNorm, $qWords, $lang)));
    }

    /**
     * exact : la recherche est exactement le terme (accents et majuscules ignorés) ;
     * large : chaque mot du terme est présent dans la recherche (pluriel et début de mot tolérés).
     */
    public static function termsMatch(array $terms, string $qNorm, array $qWords, string $lang): bool
    {
        if ($terms === []) {
            return true;
        }
        foreach ($terms as $t) {
            if ($t['match'] === 'exact') {
                if (Text::normalize($t['term']) === $qNorm) {
                    return true;
                }
                continue;
            }
            $tw = Relevance::words($t['term'], $lang);
            if ($tw === []) {
                continue;
            }
            $all = true;
            foreach ($tw as $w) {
                $found = false;
                foreach ($qWords as $qw) {
                    if (Relevance::matchesAny([$w], $qw) || Relevance::matchesAny([$qw], $w)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }
        return false;
    }

    /** Le produit remplit-il les conditions de la règle ? */
    public static function matches(array $rule, array $doc): bool
    {
        foreach ($rule['conditions'] as $c) {
            $ok = self::conditionMatches($c, $doc);
            if ($rule['match_all'] && !$ok) {
                return false;
            }
            if (!$rule['match_all'] && $ok) {
                return true;
            }
        }
        return $rule['match_all'];
    }

    private static function conditionMatches(array $c, array $doc): bool
    {
        $field = $c['field'];
        if (isset($c['op'])) {
            $v = (float) ($doc[$field] ?? 0);
            if ($field === 'margin_rate') {
                $v *= 100;
            }
            return $c['op'] === 'lte' ? $v <= (float) $c['value'] : $v >= (float) $c['value'];
        }
        if (str_starts_with($field, 'attr.')) {
            $have = (array) ($doc['attr'][substr($field, 5)] ?? []);
        } elseif ($field === 'sku') {
            $have = array_merge([(string) ($doc['sku'] ?? '')], (array) ($doc['all_skus'] ?? []));
        } else {
            $raw = $doc[$field] ?? null;
            $have = is_bool($raw) ? [$raw ? '1' : '0'] : (array) $raw;
        }
        $lower = static fn ($s) => mb_strtolower(trim((string) (is_bool($s) ? (int) $s : $s)), 'UTF-8');
        $have = array_flip(array_map($lower, $have));
        foreach ($c['values'] as $want) {
            if (isset($have[$lower($want)])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Coefficient total pour un produit (les coefficients de plusieurs règles se multiplient).
     * @return array{0:float, 1:list<array{name:string, boost:float}>}
     */
    public static function factor(array $rules, array $doc): array
    {
        $f = 1.0;
        $applied = [];
        foreach ($rules as $r) {
            if (self::matches($r, $doc)) {
                $f *= $r['boost'];
                $applied[] = ['name' => $r['name'], 'boost' => $r['boost']];
            }
        }
        return [$f, $applied];
    }

    /** Résumé lisible d'une règle : « Catégorie : Plongée, Glisse ET Prix (€) ≥ 50 ». */
    public static function describe(array $rule, string $lang): string
    {
        $fields = self::fields($lang);
        $parts = [];
        foreach ($rule['conditions'] as $c) {
            $label = $fields[$c['field']][0] ?? $c['field'];
            if (isset($c['op'])) {
                $parts[] = $label . ' ' . self::OPS[$c['op']] . ' ' . rtrim(rtrim(number_format((float) $c['value'], 2, ',', ''), '0'), ',');
            } else {
                $static = self::STATIC_VALUES[$c['field']] ?? [];
                $vals = array_map(static fn ($v) => $static[$v] ?? $v, $c['values']);
                $parts[] = $label . ' : ' . implode(', ', array_slice($vals, 0, 6)) . (count($vals) > 6 ? ' (+' . (count($vals) - 6) . ')' : '');
            }
        }
        return implode($rule['match_all'] ? ' ET ' : ' OU ', $parts);
    }
}
