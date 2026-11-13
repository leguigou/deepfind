<?php

declare(strict_types=1);

namespace DeepFind;

/**
 * Évolutions du schéma pour les installations existantes (rejouables sans risque).
 * Une nouvelle installation reçoit directement les colonnes via database/schema.sql.
 */
final class Migrations
{
    /** table => [colonne => définition] */
    private const COLUMNS = [
        'api_keys' => ['project_id' => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER id',],
        'synonyms' => ['project_id' => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER id',],
        'query_rules' => ['project_id' => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER id',],
        'redirects' => ['project_id' => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER id',],
        'product_boosts' => ['project_id' => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER id',],
        'click_logs' => ['project_id' => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER id',],
        'sources' => [
            'project_id' => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER id',
            'cron_full' => 'VARCHAR(100) NULL AFTER active',
            'cron_delta' => 'VARCHAR(100) NULL AFTER cron_full',
            'scheduled_full_at' => 'DATETIME NULL AFTER cron_delta',
            'scheduled_delta_at' => 'DATETIME NULL AFTER scheduled_full_at',
        ],
        'search_logs' => [
            'project_id' => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER id',
            'approx' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER results',
            'approx_results' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER approx',
        ],
        'import_runs' => [
            'phase' => 'VARCHAR(30) NULL AFTER items_rejected',
            'items_total' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER phase',
            'bytes_done' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER items_total',
            'bytes_total' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER bytes_done',
            'tasks_done' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER bytes_total',
            'tasks_total' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER tasks_done',
            'progress_at' => 'DATETIME NULL AFTER tasks_total',
        ],
    ];

    /**
     * Index à (re)créer : table => [nom => [colonnes, unique, anciens index à supprimer]]
     * (passage aux projets : les clés uniques incluent project_id).
     */
    private const INDEXES = [
        'sources' => ['uk_project_code' => ['project_id, code', true, ['uk_code']]],
        'synonyms' => ['idx_project_lang' => ['project_id, lang', false, ['idx_lang']]],
        'query_rules' => ['idx_project_lang_query' => ['project_id, lang, query', false, ['idx_lang_query']]],
        'product_boosts' => ['uk_project_lang_sku' => ['project_id, lang, sku', true, ['uk_lang_sku']]],
        'redirects' => ['uk_project_lang_query' => ['project_id, lang, query', true, ['uk_lang_query']]],
        'search_logs' => ['idx_project_created' => ['project_id, created_at', false, []]],
        'click_logs' => ['idx_project_created' => ['project_id, created_at', false, []]],
    ];

    /** Paramètres communs à tous les projets (restent dans la table settings). */
    public const GLOBAL_SETTINGS = [
        'rate_per_minute', 'rate_per_hour', 'same_query_per_minute', 'burst_multiplier', 'violations_before_block',
        'block_minutes', 'block_bad_user_agents', 'bad_user_agents', 'ip_allowlist', 'log_retention_days',
        'scheduler_last_run', 'scores_scheduled_at',
    ];

    /** Colonnes ENUM à élargir : table => [colonne => [valeur requise, nouvelle définition]] */
    private const ENUMS = [
        'import_runs' => ['type' => ['scores', "ENUM('full','delta','scores') NOT NULL"]],
    ];

    private static function indexExists(string $table, string $name): bool
    {
        return Db::value(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $name]
        ) !== null;
    }

    /** @return list<string> colonnes ajoutées ou modifiées */
    public static function run(): array
    {
        $added = [];
        foreach (self::ENUMS as $table => $cols) {
            foreach ($cols as $col => [$needle, $def]) {
                $type = (string) (Db::value(
                    'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $col]
                ) ?? '');
                if ($type !== '' && !str_contains($type, "'" . $needle . "'")) {
                    Db::pdo()->exec("ALTER TABLE `$table` MODIFY `$col` $def");
                    $added[] = "$table.$col (+$needle)";
                }
            }
        }
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => [$cols, $unique, $old]) {
                foreach ($old as $o) {
                    if (self::indexExists($table, $o)) {
                        Db::pdo()->exec("ALTER TABLE `$table` DROP INDEX `$o`");
                    }
                }
            }
        }
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $col => $def) {
                $exists = Db::value(
                    'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $col]
                );
                if ($exists === null) {
                    Db::pdo()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                    $added[] = "$table.$col";
                }
            }
        }
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => [$cols, $unique]) {
                if (!self::indexExists($table, $name)) {
                    Db::pdo()->exec("ALTER TABLE `$table` ADD " . ($unique ? 'UNIQUE ' : '') . "INDEX `$name` ($cols)");
                    $added[] = "$table.$name";
                }
            }
        }
        // « Boosts produits » remplacés par les règles de boost : chaque boost devient une règle « SKU » sur toutes les recherches.
        $n = \DeepFind\Rules\BoostRules::migrateProductBoosts();
        if ($n > 0) {
            $added[] = $n . ' boost(s) produit converti(s) en règles de boost (lancez scores:refresh pour retirer l\'ancien bonus du score métier)';
        }
        if (in_array('search_logs.approx_results', $added, true)) {
            $n = Db::exec('UPDATE search_logs SET approx_results = results, results = 0 WHERE approx = 1 AND results > 0');
            $added[] = $n . ' recherche(s) approchante(s) recomptée(s) à 0 résultat';
        }
        $default = Project::ensureDefault();
        if ($default !== null) {
            $added[] = 'projet « prod » créé';
            // Paramètres existants : ceux qui ne sont pas communs deviennent ceux du projet par défaut
            $in = implode(',', array_fill(0, count(self::GLOBAL_SETTINGS), '?'));
            $rows = Db::all("SELECT name, value FROM settings WHERE name NOT IN ($in) AND name <> 'rules_version'", self::GLOBAL_SETTINGS);
            foreach ($rows as $r) {
                Db::exec('INSERT IGNORE INTO project_settings (project_id, name, value) VALUES (?, ?, ?)', [$default, $r['name'], $r['value']]);
            }
            if ($rows !== []) {
                $added[] = count($rows) . ' paramètre(s) rattaché(s) au projet « prod »';
            }
        }
        return $added;
    }
}
