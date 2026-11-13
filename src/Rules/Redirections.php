<?php

declare(strict_types=1);

namespace DeepFind\Rules;

use DeepFind\Db;
use DeepFind\Search\Relevance;
use DeepFind\Store\Store;
use DeepFind\Text;

/**
 * Redirections : pour certains termes (exacts ou larges), en continu ou sur une période, le widget propose un lien vers une page
 * en haut des résultats ; en mode automatique, la recherche validée (Entrée, loupe) ouvre directement cette page.
 * Remplace l'ancien format « une requête exacte = une URL » (table redirects), converti par migrateLegacy().
 */
final class Redirections
{
    /** @return list<array> */
    public static function all(string $lang): array
    {
        return array_map([self::class, 'decode'], Db::all('SELECT * FROM redirections WHERE project_id = ? AND lang = ? ORDER BY active DESC, name, id',
            [\DeepFind\Project::id(), $lang]));
    }

    public static function find(int $id, string $lang): ?array
    {
        $row = Db::one('SELECT * FROM redirections WHERE id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]);
        return $row ? self::decode($row) : null;
    }

    private static function decode(array $r): array
    {
        return ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'active' => (int) $r['active'] === 1, 'auto' => (int) $r['auto'] === 1,
            'url' => (string) $r['url'], 'terms' => json_decode((string) $r['terms'], true) ?: [], 'starts_at' => $r['starts_at'] ?: null, 'ends_at' => $r['ends_at'] ?: null];
    }

    /** Redirection de cette recherche (la plus récente qui correspond), ou null. */
    public static function forQuery(string $lang, string $q): ?array
    {
        $qNorm = Text::normalize($q);
        if ($qNorm === '') {
            return null;
        }
        $today = date('Y-m-d');
        $store = Store::get();
        $key = 'redirections:' . \DeepFind\Project::id() . ':' . $lang . ':' . $today . ':' . \DeepFind\CacheVersion::get();
        $list = $store->get($key);
        if (!is_array($list)) {
            $list = array_values(array_filter(self::all($lang), static fn ($r) => $r['active'] && $r['terms'] !== [] && CustomResults::period($r, $today) === 'current'));
            usort($list, static fn ($a, $b) => $b['id'] <=> $a['id']);
            $store->set($key, $list, 300);
        }
        $qWords = Relevance::words($q, $lang);
        foreach ($list as $r) {
            if (BoostRules::termsMatch($r['terms'], $qNorm, $qWords, $lang)) {
                return $r;
            }
        }
        return null;
    }

    /** @return array{0:?array, 1:list<string>} */
    public static function sanitize(array $in): array
    {
        $errors = [];
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 190, 'UTF-8');
        if ($name === '') {
            $errors[] = 'Le nom est obligatoire.';
        }
        $url = mb_substr(trim((string) ($in['url'] ?? '')), 0, 500, 'UTF-8');
        if (!preg_match('#^https?://[^\s]+$#i', $url)) {
            $errors[] = 'URL de destination complète attendue (https://…).';
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
        if ($errors !== []) {
            return [null, $errors];
        }
        return [['name' => $name, 'active' => !empty($in['active']), 'auto' => !empty($in['auto']), 'url' => $url, 'terms' => array_values($terms),
            'starts_at' => $start, 'ends_at' => $end], []];
    }

    private static function date(mixed $v): ?string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
    }

    public static function save(string $lang, array $r, ?int $id): int
    {
        $row = ['name' => $r['name'], 'active' => $r['active'] ? 1 : 0, 'auto' => $r['auto'] ? 1 : 0, 'url' => $r['url'], 'terms' => Text::json($r['terms']),
            'starts_at' => $r['starts_at'], 'ends_at' => $r['ends_at'], 'updated_at' => date('Y-m-d H:i:s')];
        if ($id) {
            Db::update('redirections', $row, 'id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]);
        } else {
            $id = Db::insert('redirections', $row + ['project_id' => \DeepFind\Project::id(), 'lang' => $lang, 'created_at' => $row['updated_at']]);
        }
        \DeepFind\CacheVersion::bump();
        return (int) $id;
    }

    public static function delete(int $id, string $lang): void
    {
        Db::exec('DELETE FROM redirections WHERE id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]);
        \DeepFind\CacheVersion::bump();
    }

    /** Ancien format (table redirects) → redirections automatiques en correspondance exacte (même comportement qu'avant). */
    public static function migrateLegacy(): int
    {
        $rows = Db::all('SELECT * FROM redirects ORDER BY id');
        if ($rows === []) {
            return 0;
        }
        foreach ($rows as $r) {
            $now = date('Y-m-d H:i:s');
            Db::insert('redirections', ['project_id' => (int) $r['project_id'], 'lang' => $r['lang'], 'name' => 'Redirection « ' . $r['query'] . ' »',
                'active' => 1, 'auto' => 1, 'url' => (string) $r['url'], 'terms' => Text::json([['term' => (string) $r['query'], 'match' => 'exact']]),
                'starts_at' => null, 'ends_at' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
        Db::exec('DELETE FROM redirects WHERE id IN (' . implode(',', array_map(static fn ($r) => (int) $r['id'], $rows)) . ')');
        \DeepFind\CacheVersion::bump();
        return count($rows);
    }
}
