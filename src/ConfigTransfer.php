<?php

declare(strict_types=1);

namespace DeepFind;

use DeepFind\Meili\MeiliClient;
use DeepFind\Rules\RulesRepository;
use DeepFind\Search\Relevance;

/**
 * Copie de configuration entre projets (ex. Test → Prod), avec sauvegarde automatique de la cible.
 * Jamais copiés : statistiques, journaux, clics, historique des imports, clés publiques, produits indexés.
 */
final class ConfigTransfer
{
    /** Paramètres « pertinence & score métier » */
    public const RELEVANCE_SETTINGS = [
        'searchable_fields', 'matching_mode', 'min_relevance', 'attribute_first', 'name_position_weight', 'rerank_window',
        'biz_importance', 'out_of_stock_last', 'w_stock', 'w_margin', 'w_popularity', 'w_clicks', 'w_new', 'w_manual_boost',
        'popularity_ref', 'clicks_ref', 'new_days', 'scores_nightly', 'scores_cron',
    ];

    /** Paramètres de recherche et d'import */
    public const SEARCH_SETTINGS = ['posts_limit', 'max_limit', 'cache_ttl', 'facet_values_limit', 'log_searches', 'max_drop_percent', 'widget_enter_submit'];

    public const SECTIONS = [
        'relevance' => 'Pertinence & score métier (ordre des champs, correspondance, poids, bonus début du nom…)',
        'search' => 'Paramètres de recherche et d\'import (cache, articles affichés, seuil de sécurité…)',
        'synonyms' => 'Synonymes & alias',
        'rules' => 'Règles de résultats (épingler, remonter, masquer)',
        'redirects' => 'Redirections',
        'boost_rules' => 'Règles de boost (conditions et termes)',
        'sources' => 'Flux : mapping et planification des imports',
    ];

    /** Configuration complète d'un projet (sert aussi de sauvegarde). */
    public static function export(int $pid): array
    {
        $settings = Settings::projectValues($pid);
        $pick = static fn (array $names) => array_intersect_key($settings, array_flip($names));
        $strip = static fn (array $rows, array $drop) => array_map(static fn ($r) => array_diff_key($r, array_flip($drop)), $rows);
        return [
            'version' => 1,
            'exported_at' => date('c'),
            'relevance' => $pick(self::RELEVANCE_SETTINGS),
            'search' => $pick(self::SEARCH_SETTINGS),
            'synonyms' => $strip(Db::all('SELECT * FROM synonyms WHERE project_id = ? ORDER BY id', [$pid]), ['id', 'project_id']),
            'rules' => $strip(Db::all('SELECT * FROM query_rules WHERE project_id = ? ORDER BY id', [$pid]), ['id', 'project_id']),
            'redirects' => $strip(Db::all('SELECT * FROM redirects WHERE project_id = ? ORDER BY id', [$pid]), ['id', 'project_id']),
            'boost_rules' => $strip(Db::all('SELECT * FROM boost_rules WHERE project_id = ? ORDER BY id', [$pid]), ['id', 'project_id']),
            'sources' => $strip(
                Db::all('SELECT code, kind, lang, url, delta_url, auth_header, mapping, active, cron_full, cron_delta FROM sources WHERE project_id = ? ORDER BY id', [$pid]),
                []
            ),
        ];
    }

    /**
     * Ce qui changera dans la cible.
     * @return array<string, array{from:int|string, to:int|string, detail:list<string>}>
     */
    public static function summary(array $data, int $to, array $sections): array
    {
        $target = self::export($to);
        $out = [];
        foreach ($sections as $sec) {
            if (!isset(self::SECTIONS[$sec])) {
                continue;
            }
            if ($sec === 'relevance' || $sec === 'search') {
                $names = $sec === 'relevance' ? self::RELEVANCE_SETTINGS : self::SEARCH_SETTINGS;
                $detail = [];
                foreach ($names as $n) {
                    $a = $target[$sec][$n] ?? Settings::DEFAULTS[$n] ?? '';
                    $b = $data[$sec][$n] ?? Settings::DEFAULTS[$n] ?? '';
                    if ((string) $a !== (string) $b) {
                        $detail[] = (Settings::LABELS[$n] ?? $n) . ' : ' . self::display($n, (string) $a) . ' → ' . self::display($n, (string) $b);
                    }
                }
                $out[$sec] = ['from' => count($detail) . ' changement(s)', 'to' => '', 'detail' => $detail];
                continue;
            }
            if ($sec === 'sources') {
                $codes = array_column($target['sources'], 'code');
                $detail = [];
                foreach ($data['sources'] as $s) {
                    $detail[] = $s['code'] . (in_array($s['code'], $codes, true) ? ' : mapping et planification remplacés' : ' : nouveau flux (créé inactif)');
                }
                $out[$sec] = ['from' => count($data['sources']), 'to' => count($target['sources']), 'detail' => $detail];
                continue;
            }
            $out[$sec] = ['from' => count($data[$sec] ?? []), 'to' => count($target[$sec] ?? []), 'detail' => []];
        }
        return $out;
    }

    /**
     * Applique une configuration à un projet (les sections choisies remplacent celles de la cible).
     * @return list<string> messages
     */
    public static function apply(int $to, array $data, array $sections, bool $includeUrls, bool $restore = false): array
    {
        $msgs = [];
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach (['relevance', 'search'] as $sec) {
                if (in_array($sec, $sections, true)) {
                    $names = $sec === 'relevance' ? self::RELEVANCE_SETTINGS : self::SEARCH_SETTINGS;
                    foreach ($names as $n) {
                        if (array_key_exists($n, $data[$sec] ?? [])) {
                            Db::exec('INSERT INTO project_settings (project_id, name, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = ?', [$to, $n, $data[$sec][$n], $data[$sec][$n]]);
                        } else {
                            Db::exec('DELETE FROM project_settings WHERE project_id = ? AND name = ?', [$to, $n]);
                        }
                    }
                    if ($sec === 'relevance') {
                        $now = date('Y-m-d H:i:s');
                        Db::exec("INSERT INTO project_settings (project_id, name, value) VALUES (?, 'weights_changed_at', ?) ON DUPLICATE KEY UPDATE value = ?", [$to, $now, $now]);
                    }
                    $msgs[] = self::SECTIONS[$sec] . ' : copié';
                }
            }
            foreach (['synonyms' => 'synonyms', 'rules' => 'query_rules', 'redirects' => 'redirects', 'boost_rules' => 'boost_rules'] as $sec => $table) {
                if (!in_array($sec, $sections, true)) {
                    continue;
                }
                Db::exec("DELETE FROM `$table` WHERE project_id = ?", [$to]);
                foreach ($data[$sec] ?? [] as $row) {
                    Db::insert($table, ['project_id' => $to] + $row);
                }
                $msgs[] = self::SECTIONS[$sec] . ' : ' . count($data[$sec] ?? []) . ' élément(s)';
            }
            if (in_array('sources', $sections, true)) {
                $now = date('Y-m-d H:i:s');
                $created = 0;
                $updated = 0;
                foreach ($data['sources'] ?? [] as $s) {
                    $existing = Db::one('SELECT id FROM sources WHERE project_id = ? AND code = ?', [$to, $s['code']]);
                    $fields = ['kind' => $s['kind'], 'lang' => $s['lang'], 'mapping' => $s['mapping'], 'cron_full' => $s['cron_full'], 'cron_delta' => $s['cron_delta'], 'updated_at' => $now];
                    if ($includeUrls) {
                        $fields += ['url' => $s['url'], 'delta_url' => $s['delta_url'], 'auth_header' => $s['auth_header']];
                    }
                    if ($restore) {
                        $fields['active'] = (int) $s['active'];
                    }
                    if ($existing !== null) {
                        Db::update('sources', $fields, 'id = ?', [$existing['id']]);
                        $updated++;
                    } else {
                        // Nouveau flux : créé inactif (pas d'import planifié tant qu'il n'est pas activé)
                        Db::insert('sources', $fields + [
                            'project_id' => $to, 'code' => $s['code'], 'url' => $s['url'], 'delta_url' => $s['delta_url'],
                            'auth_header' => $s['auth_header'], 'active' => $restore ? (int) $s['active'] : 0, 'created_at' => $now,
                        ]);
                        $created++;
                    }
                }
                $msgs[] = sprintf('Flux : %d mis à jour, %d créé(s)%s', $updated, $created, $created && !$restore ? ' (inactifs)' : '');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $msgs[] = self::applyToEngine($to);
        return $msgs;
    }

    /** Réglages de pertinence et synonymes envoyés aux index du projet ; caches du projet invalidés. */
    public static function applyToEngine(int $pid): string
    {
        return Project::with($pid, static function () use ($pid): string {
            Settings::reset();
            $meili = MeiliClient::admin();
            $n = 0;
            try {
                foreach (array_filter(array_map('trim', explode(',', (string) Config::get('APP_LANGS', 'fr')))) as $lang) {
                    $syn = RulesRepository::meiliSynonyms($lang);
                    $syn = $syn === [] ? new \stdClass() : $syn;
                    if ($meili->getIndex(Config::productIndex($lang)) !== null) {
                        $meili->updateSettings(Config::productIndex($lang), [
                            'searchableAttributes' => Relevance::searchableAttributes(),
                            'rankingRules' => Relevance::rankingRules(),
                            'synonyms' => $syn,
                        ]);
                        $n++;
                    }
                    if ($meili->getIndex(Config::postIndex($lang)) !== null) {
                        $meili->updateSettings(Config::postIndex($lang), ['synonyms' => $syn]);
                        $n++;
                    }
                }
                $msg = sprintf('Réglages envoyés à %d index du projet.', $n);
            } catch (\Throwable $e) {
                $msg = 'Configuration copiée mais non envoyée au moteur : ' . $e->getMessage();
            }
            CacheVersion::bump($pid);
            return $msg;
        });
    }

    public static function snapshot(int $pid, string $label): int
    {
        $id = Db::insert('config_snapshots', [
            'project_id' => $pid, 'label' => Text::truncate($label, 190), 'data' => Text::json(self::export($pid)), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        // On garde les 30 dernières sauvegardes par projet
        $old = Db::all('SELECT id FROM config_snapshots WHERE project_id = ? ORDER BY id DESC LIMIT 1000 OFFSET 30', [$pid]);
        foreach ($old as $o) {
            Db::exec('DELETE FROM config_snapshots WHERE id = ?', [$o['id']]);
        }
        return $id;
    }

    /** @return list<string> */
    public static function restore(int $snapshotId, int $pid): array
    {
        $snap = Db::one('SELECT * FROM config_snapshots WHERE id = ? AND project_id = ?', [$snapshotId, $pid]);
        if ($snap === null) {
            throw new \RuntimeException('Sauvegarde introuvable pour ce projet.');
        }
        $data = json_decode((string) $snap['data'], true);
        if (!is_array($data)) {
            throw new \RuntimeException('Sauvegarde illisible.');
        }
        self::snapshot($pid, 'Avant restauration de la sauvegarde #' . $snapshotId);
        // Sauvegarde antérieure à une section (ex. règles de boost) : cette section est laissée telle quelle.
        $sections = array_values(array_filter(array_keys(self::SECTIONS), static fn ($sec) => array_key_exists($sec, $data)));
        return self::apply($pid, $data, $sections, true, true);
    }

    /** Valeur lisible pour le résumé de copie. */
    private static function display(string $name, string $v): string
    {
        if ($name === 'searchable_fields') {
            $rows = json_decode($v, true);
            if (!is_array($rows) || $rows === []) {
                return 'ordre par défaut';
            }
            $labels = [];
            foreach ($rows as $r) {
                if (!empty($r['enabled']) && isset(Relevance::FIELDS[$r['field'] ?? ''])) {
                    $labels[] = mb_strtolower(Relevance::FIELDS[$r['field']][0]);
                }
            }
            return self::short(implode(', ', $labels));
        }
        if ($name === 'matching_mode') {
            return Relevance::MATCHING[$v][0] ?? Relevance::MATCHING['exact_then_approx'][0];
        }
        if ($name === 'biz_importance') {
            return Relevance::IMPORTANCE[$v][0] ?? Relevance::IMPORTANCE['low'][0];
        }
        if (in_array($name, Settings::BOOLEANS, true)) {
            return $v === '1' ? 'activé' : 'désactivé';
        }
        return self::short($v);
    }

    private static function short(string $v): string
    {
        return $v === '' ? '(vide)' : Text::truncate($v, 40) . (mb_strlen($v) > 40 ? '…' : '');
    }
}
