<?php

declare(strict_types=1);

namespace DeepFind\Rules;

use DeepFind\Db;
use DeepFind\Store\Store;
use DeepFind\Text;

/**
 * Règles selon la page (Recommandations › Règles).
 * D'après l'adresse de la page où se trouve le visiteur (ex. contient « /nautisme/ »), sans terme de recherche ni période :
 *   - only      : n'afficher que les produits qui remplissent les conditions (filtre imposé) ;
 *   - prefilter : même filtre, mais le visiteur peut le retirer (bandeau « libellé ✕ » dans le widget) ;
 *   - exclude   : ne jamais afficher ces produits.
 * Seule la PREMIÈRE règle active (dans l'ordre de la liste) dont une adresse correspond s'applique.
 * Les conditions produits sont celles des résultats personnalisés (est / n'est pas, comparaison chiffrée) : elles deviennent
 * un filtre du moteur, donc le nombre de résultats, la pagination et les filtres du widget restent justes.
 */
final class PageRules
{
    public const ACTIONS = [
        'prefilter' => 'Filtre pré-coché : seuls ces produits au départ, le visiteur peut élargir à tout le catalogue',
        'only' => 'Afficher uniquement ces produits (filtre imposé)',
        'exclude' => 'Exclure ces produits',
    ];

    /** Comparaisons sur l'adresse (chemin de la page, sans le domaine ni les paramètres). */
    public const URL_OPS = ['contains' => 'contient', 'starts' => 'commence par', 'ends' => 'finit par', 'equals' => 'est exactement'];

    /** @return list<array> règles du projet et de la langue, dans l'ordre d'application */
    public static function all(string $lang): array
    {
        return array_map([self::class, 'decode'], Db::all('SELECT * FROM page_rules WHERE project_id = ? AND lang = ? ORDER BY position, id',
            [\DeepFind\Project::id(), $lang]));
    }

    public static function find(int $id, string $lang): ?array
    {
        $row = Db::one('SELECT * FROM page_rules WHERE id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]);
        return $row ? self::decode($row) : null;
    }

    /** Règles actives (cache invalidé par CacheVersion). @return list<array> */
    public static function active(string $lang): array
    {
        $store = Store::get();
        $key = 'pagerules:' . \DeepFind\Project::id() . ':' . $lang . ':' . \DeepFind\CacheVersion::get();
        $cached = $store->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $rules = array_values(array_filter(self::all($lang), static fn ($r) => $r['active'] && $r['urls'] !== [] && $r['conditions'] !== []));
        $store->set($key, $rules, 300);
        return $rules;
    }

    private static function decode(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'active' => (int) $row['active'] === 1,
            'position' => (int) $row['position'],
            'urls' => json_decode((string) $row['urls'], true) ?: [],
            'action' => isset(self::ACTIONS[$row['action']]) ? (string) $row['action'] : 'prefilter',
            'label' => (string) $row['label'],
            'match_all' => (int) $row['match_all'] === 1,
            'conditions' => json_decode((string) $row['conditions'], true) ?: [],
        ];
    }

    /** Chemin de page normalisé : minuscules, sans domaine, paramètres ni ancre (« /fr/nautisme/kayak.html »). */
    public static function normalizePath(string $page): string
    {
        $page = trim($page);
        if (preg_match('~^https?://~i', $page)) {
            $page = (string) (parse_url($page, PHP_URL_PATH) ?? '/');
        }
        $page = (string) preg_replace('~[?#].*$~s', '', $page);
        $page = rawurldecode($page);
        return mb_strtolower('/' . ltrim(mb_substr($page, 0, 300, 'UTF-8'), '/'), 'UTF-8');
    }

    public static function urlMatches(array $u, string $path): bool
    {
        $text = mb_strtolower(trim((string) ($u['text'] ?? '')), 'UTF-8');
        if ($text === '') {
            return false;
        }
        return match ($u['match'] ?? 'contains') {
            'starts' => str_starts_with($path, $text),
            'ends' => str_ends_with($path, $text),
            // « /fr/nautisme » et « /fr/nautisme/ » désignent la même page
            'equals' => rtrim($path, '/') === rtrim($text, '/'),
            default => str_contains($path, $text),
        };
    }

    /** Première règle active dont une adresse correspond à la page, ou null. */
    public static function match(string $lang, string $page): ?array
    {
        if (trim($page) === '') {
            return null;
        }
        $path = self::normalizePath($page);
        foreach (self::active($lang) as $rule) {
            foreach ($rule['urls'] as $u) {
                if (self::urlMatches($u, $path)) {
                    return $rule;
                }
            }
        }
        return null;
    }

    /** Filtre du moteur pour les produits de la règle (avant inversion pour « exclure »), ou null. */
    public static function filter(array $rule, ?string $lang = null): ?string
    {
        $conds = [];
        foreach ($rule['conditions'] as $c) {
            $conds[] = isset(CustomResults::CONTAINS_OPS[$c['op'] ?? '']) ? self::expandContains($c, $lang ?? 'fr') : $c;
        }
        return CustomResults::ruleFilter(['match_all' => $rule['match_all'], 'conditions' => $conds]);
    }

    /** Valeur introuvable : « est » ne garde aucun produit, « n'est pas » les garde tous. */
    private const NO_VALUE = '__aucune_valeur__';

    /**
     * « Catégorie contient alimentation » → « Catégorie est Alimentation, Alimentation chien… » : valeurs du catalogue
     * (recherche dans les valeurs du champ par le moteur, 100 au plus par texte) qui contiennent l'un des textes,
     * sans tenir compte des majuscules ni des accents. Mis en cache, recalculé après chaque import.
     */
    public static function expandContains(array $c, string $lang): array
    {
        $field = (string) $c['field'];
        $facet = $field === 'sku' ? 'all_skus' : (str_starts_with($field, 'attr.') ? 'attr.' . \DeepFind\Import\Mapper::code(substr($field, 5)) : $field);
        $texts = array_values(array_filter(array_map(static fn ($t) => Text::fold((string) $t), (array) ($c['values'] ?? [])), 'strlen'));
        $store = Store::get();
        $key = 'prcontains:' . \DeepFind\Project::id() . ':' . $lang . ':' . \DeepFind\CacheVersion::get() . ':' . md5($facet . '|' . implode('|', $texts));
        $values = $store->get($key);
        if (!is_array($values)) {
            $values = [];
            try {
                $meili = \DeepFind\Meili\MeiliClient::search();
                $uid = \DeepFind\Config::productIndex($lang);
                foreach ($texts as $t) {
                    // recherche par le texte (mots et débuts de mots) + valeurs les plus fréquentes (texte au milieu d'un mot)
                    foreach ([$t, ''] as $q) {
                        foreach ($meili->facetSearch($uid, $facet, $q) as $h) {
                            if (str_contains(Text::fold($h['value']), $t)) {
                                $values[$h['value']] = true;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log('[deepfind] règle de page « contient » : ' . $e->getMessage());
            }
            $values = array_keys($values);
            $store->set($key, $values, 3600);
        }
        $values = $values === [] ? [self::NO_VALUE] : array_map('strval', $values);
        return ['field' => $field, 'op' => ($c['op'] ?? '') === 'not_contains' ? 'not_in' : 'in', 'values' => $values];
    }

    /**
     * Valide une règle envoyée par le formulaire.
     * @return array{0:?array, 1:list<string>}
     */
    public static function sanitize(array $in, string $lang): array
    {
        $errors = [];
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 190, 'UTF-8');
        if ($name === '') {
            $errors[] = 'Le nom de la règle est obligatoire.';
        }
        $urls = [];
        foreach (array_slice((array) ($in['urls'] ?? []), 0, 30) as $u) {
            $text = mb_substr(trim((string) ($u['text'] ?? '')), 0, 300, 'UTF-8');
            if (preg_match('~^https?://~i', $text)) {
                $text = (string) (parse_url($text, PHP_URL_PATH) ?? '');
            }
            $match = isset(self::URL_OPS[$u['match'] ?? '']) ? (string) $u['match'] : 'contains';
            if ($text !== '' && !in_array(['match' => $match, 'text' => $text], $urls, true)) {
                $urls[] = ['match' => $match, 'text' => $text];
            }
        }
        if ($urls === []) {
            $errors[] = 'Indiquez au moins une adresse de page (ex. contient « /nautisme/ »).';
        }
        $action = isset(self::ACTIONS[$in['action'] ?? '']) ? (string) $in['action'] : 'prefilter';
        [$rules, $condErrors] = CustomResults::sanitizeRules([['match' => ($in['match'] ?? 'all') === 'any' ? 'any' : 'all',
            'conditions' => (array) ($in['conditions'] ?? [])]], $lang, true);
        $errors = [...$errors, ...$condErrors];
        if (($rules[0]['conditions'] ?? []) === [] && $condErrors === []) {
            $errors[] = 'Ajoutez au moins une condition sur les produits (ex. Catégorie est « Nautisme »).';
        }
        if ($errors !== []) {
            return [null, $errors];
        }
        return [[
            'name' => $name, 'active' => !empty($in['active']), 'urls' => $urls, 'action' => $action,
            'label' => mb_substr(trim((string) ($in['label'] ?? '')), 0, 100, 'UTF-8'),
            'match_all' => $rules[0]['match_all'], 'conditions' => $rules[0]['conditions'],
        ], []];
    }

    /** @return int identifiant */
    public static function save(string $lang, array $rule, ?int $id): int
    {
        $row = [
            'name' => $rule['name'], 'active' => $rule['active'] ? 1 : 0, 'urls' => Text::json($rule['urls']), 'action' => $rule['action'],
            'label' => $rule['label'], 'match_all' => $rule['match_all'] ? 1 : 0, 'conditions' => Text::json($rule['conditions']),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($id !== null && self::find($id, $lang) !== null) {
            Db::update('page_rules', $row, 'id = ? AND project_id = ?', [$id, \DeepFind\Project::id()]);
        } else {
            // nouvelle règle : à la fin de la liste (elle ne prend pas le pas sur les règles existantes)
            $pos = (int) Db::value('SELECT COALESCE(MAX(position), 0) + 1 FROM page_rules WHERE project_id = ? AND lang = ?', [\DeepFind\Project::id(), $lang]);
            $id = Db::insert('page_rules', $row + ['project_id' => \DeepFind\Project::id(), 'lang' => $lang, 'position' => $pos, 'created_at' => date('Y-m-d H:i:s')]);
        }
        \DeepFind\CacheVersion::bump();
        return $id;
    }

    public static function setActive(int $id, string $lang, bool $on): void
    {
        Db::exec('UPDATE page_rules SET active = ?, updated_at = ? WHERE id = ? AND project_id = ? AND lang = ?',
            [$on ? 1 : 0, date('Y-m-d H:i:s'), $id, \DeepFind\Project::id(), $lang]);
        \DeepFind\CacheVersion::bump();
    }

    /** Nouvel ordre d'application (identifiants dans l'ordre voulu ; les autres gardent leur rang, après). */
    public static function reorder(string $lang, array $ids): void
    {
        $known = array_column(self::all($lang), 'id');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => in_array($i, $known, true))));
        $order = [...$ids, ...array_values(array_diff($known, $ids))];
        foreach ($order as $pos => $id) {
            Db::exec('UPDATE page_rules SET position = ? WHERE id = ? AND project_id = ?', [$pos + 1, $id, \DeepFind\Project::id()]);
        }
        \DeepFind\CacheVersion::bump();
    }

    public static function delete(int $id, string $lang): void
    {
        Db::exec('DELETE FROM page_rules WHERE id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]);
        \DeepFind\CacheVersion::bump();
    }

    /** Résumé lisible : « contient /nautisme/ ou commence par /fr/kayak ». */
    public static function describeUrls(array $rule): string
    {
        return implode(' ou ', array_map(static fn ($u) => (self::URL_OPS[$u['match']] ?? $u['match']) . ' « ' . $u['text'] . ' »', $rule['urls']));
    }
}
