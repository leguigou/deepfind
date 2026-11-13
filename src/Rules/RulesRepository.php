<?php

declare(strict_types=1);

namespace DeepFind\Rules;

use DeepFind\Db;
use DeepFind\Store\Store;
use DeepFind\Text;

/**
 * Synonymes, règles par requête (épingler / remonter / masquer) et redirections.
 */
final class RulesRepository
{
    public const ACTIONS = ['pin' => 'Épingler à une position', 'boost' => 'Remonter en tête', 'hide' => 'Masquer'];

    // ------------------------------------------------------------------ Synonymes

    /** Format attendu par Meilisearch : terme => [synonymes]. */
    public static function meiliSynonyms(string $lang): array
    {
        $map = [];
        foreach (Db::all('SELECT type, term, synonyms FROM synonyms WHERE project_id = ? AND lang = ?', [\DeepFind\Project::id(), $lang]) as $row) {
            $term = mb_strtolower(trim($row['term']), 'UTF-8');
            $syns = array_values(array_filter(array_map(static fn ($s) => mb_strtolower(trim($s), 'UTF-8'), explode('|', $row['synonyms'])), 'strlen'));
            if ($term === '' || $syns === []) {
                continue;
            }
            if ($row['type'] === 'alias') {
                $map[$term] = array_values(array_unique(array_merge($map[$term] ?? [], $syns)));
                continue;
            }
            $group = array_values(array_unique(array_merge([$term], $syns)));
            foreach ($group as $w) {
                $others = array_values(array_diff($group, [$w]));
                $map[$w] = array_values(array_unique(array_merge($map[$w] ?? [], $others)));
            }
        }
        return $map;
    }

    // ------------------------------------------------------------------ Règles compilées (utilisées par l'API)

    /**
     * @return array{pins: array<string, list<array{sku:string,position:int}>>, boosts: array<string, list<string>>,
     *               hides: array<string, list<string>>, hide_all: list<string>, redirects: array<string,string>}
     */
    public static function compiled(string $lang): array
    {
        $store = Store::get();
        $key = 'rules:' . \DeepFind\Project::id() . ':' . $lang . ':' . self::version();
        $cached = $store->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $today = date('Y-m-d');
        $out = ['pins' => [], 'boosts' => [], 'hides' => [], 'hide_all' => [], 'redirects' => []];
        $rows = Db::all(
            'SELECT query, sku, action, position FROM query_rules
             WHERE project_id = ? AND lang = ? AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at >= ?)
             ORDER BY position IS NULL, position, id',
            [\DeepFind\Project::id(), $lang, $today, $today]
        );
        foreach ($rows as $r) {
            $q = $r['query'];
            $sku = mb_strtoupper($r['sku'], 'UTF-8');
            if ($r['action'] === 'hide') {
                if ($q === '*') {
                    $out['hide_all'][] = $sku;
                } else {
                    $out['hides'][$q][] = $sku;
                }
            } elseif ($r['action'] === 'pin') {
                $out['pins'][$q][] = ['sku' => $sku, 'position' => max(1, (int) $r['position'])];
            } else {
                $out['boosts'][$q][] = $sku;
            }
        }
        foreach (Db::all('SELECT query, url FROM redirects WHERE project_id = ? AND lang = ?', [\DeepFind\Project::id(), $lang]) as $r) {
            $out['redirects'][$r['query']] = $r['url'];
        }
        $store->set($key, $out, 300);
        return $out;
    }

    /** Numéro de version des règles : change à chaque modification (invalide les caches). */
    /** Version des données : voir CacheVersion (change après chaque import ou modification de règle). */
    public static function version(): string
    {
        return \DeepFind\CacheVersion::get();
    }

    public static function bumpVersion(): void
    {
        \DeepFind\CacheVersion::bump();
    }

    // ------------------------------------------------------------------ Import CSV

    /**
     * Lit un CSV (séparateur ; ou ,) avec ligne d'en-tête. Retourne les lignes en tableaux associatifs.
     * @return list<array<string,string>>
     */
    public static function readCsv(string $file): array
    {
        $content = (string) file_get_contents($file);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $content) ?: [], static fn ($l) => trim($l) !== '' && !str_starts_with(ltrim($l), '#')));
        if ($lines === []) {
            return [];
        }
        $sep = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(static fn ($h) => Text::normalize($h), str_getcsv($lines[0], $sep, '"', ''));
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $cells = str_getcsv($line, $sep, '"', '');
            $row = [];
            foreach ($header as $i => $h) {
                $row[$h] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return array{0:int,1:list<string>} [nb importés, erreurs] */
    public static function importSynonyms(string $file, string $lang, bool $replace): array
    {
        $rows = self::readCsv($file);
        $errors = [];
        $n = 0;
        Db::pdo()->beginTransaction();
        if ($replace) {
            Db::exec('DELETE FROM synonyms WHERE project_id = ? AND lang = ?', [\DeepFind\Project::id(), $lang]);
        }
        foreach ($rows as $i => $r) {
            $type = ($r['type'] ?? 'equivalent') === 'alias' ? 'alias' : 'equivalent';
            $term = $r['terme'] ?? $r['term'] ?? '';
            $syn = $r['synonymes'] ?? $r['synonyms'] ?? '';
            if ($term === '' || $syn === '') {
                $errors[] = 'Ligne ' . ($i + 2) . ' : terme ou synonymes vide';
                continue;
            }
            self::addSynonym($lang, $type, $term, $syn);
            $n++;
        }
        Db::pdo()->commit();
        return [$n, $errors];
    }

    public static function addSynonym(string $lang, string $type, string $term, string $synonyms): void
    {
        Db::insert('synonyms', [
            'project_id' => \DeepFind\Project::id(), 'lang' => $lang, 'type' => $type, 'term' => Text::truncate(trim($term), 190),
            'synonyms' => trim($synonyms), 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{0:int,1:list<string>} */
    public static function importRules(string $file, string $lang, bool $replace): array
    {
        $rows = self::readCsv($file);
        $errors = [];
        $n = 0;
        Db::pdo()->beginTransaction();
        if ($replace) {
            // Remplace les résultats personnalisés ; les produits désactivés partout (requête « * ») sont conservés
            Db::exec("DELETE FROM query_rules WHERE project_id = ? AND lang = ? AND query <> '*'", [\DeepFind\Project::id(), $lang]);
            Db::exec('DELETE FROM custom_results WHERE project_id = ? AND lang = ?', [\DeepFind\Project::id(), $lang]);
        }
        foreach ($rows as $i => $r) {
            $q = $r['requete'] ?? $r['query'] ?? '';
            $sku = $r['sku'] ?? '';
            $action = $r['action'] ?? '';
            if ($q === '' || $sku === '' || !isset(self::ACTIONS[$action])) {
                $errors[] = 'Ligne ' . ($i + 2) . ' : requête, sku ou action invalide';
                continue;
            }
            $pos = $r['position_ou_poids'] ?? $r['position'] ?? '';
            self::addRule($lang, $q, $sku, $action, $pos === '' ? null : (int) $pos, $r['debut'] ?? $r['starts_at'] ?? '', $r['fin'] ?? $r['ends_at'] ?? '');
            $n++;
        }
        Db::pdo()->commit();
        // Les lignes importées deviennent des résultats personnalisés (une par requête et par période)
        CustomResults::migrateQueryRules();
        return [$n, $errors];
    }

    public static function addRule(string $lang, string $query, string $sku, string $action, ?int $position, string $start, string $end): void
    {
        Db::insert('query_rules', [
            'project_id' => \DeepFind\Project::id(),
            'lang' => $lang,
            'query' => $query === '*' ? '*' : Text::normalize($query),
            'sku' => trim($sku),
            'action' => $action,
            'position' => $action === 'pin' ? max(1, (int) $position) : null,
            'starts_at' => self::date($start),
            'ends_at' => self::date($end),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{0:int,1:list<string>} */
    public static function importRedirects(string $file, string $lang, bool $replace): array
    {
        $rows = self::readCsv($file);
        $errors = [];
        $n = 0;
        Db::pdo()->beginTransaction();
        if ($replace) {
            Db::exec('DELETE FROM redirects WHERE project_id = ? AND lang = ?', [\DeepFind\Project::id(), $lang]);
        }
        foreach ($rows as $i => $r) {
            $q = $r['requete'] ?? $r['query'] ?? '';
            $url = $r['url'] ?? '';
            if ($q === '' || !preg_match('#^https?://#i', $url)) {
                $errors[] = 'Ligne ' . ($i + 2) . ' : requête vide ou URL invalide';
                continue;
            }
            self::addRedirect($lang, $q, $url);
            $n++;
        }
        Db::pdo()->commit();
        return [$n, $errors];
    }

    public static function addRedirect(string $lang, string $query, string $url): void
    {
        Db::exec(
            'INSERT INTO redirects (project_id, lang, query, url, created_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE url = ?',
            [\DeepFind\Project::id(), $lang, Text::normalize($query), $url, date('Y-m-d H:i:s'), $url]
        );
    }

    private static function date(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        $t = strtotime(str_contains($s, '/') ? implode('-', array_reverse(explode('/', $s))) : $s);
        return $t === false ? null : date('Y-m-d', $t);
    }
}
