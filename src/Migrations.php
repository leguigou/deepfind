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
        'sources' => [
            'cron_full' => 'VARCHAR(100) NULL AFTER active',
            'cron_delta' => 'VARCHAR(100) NULL AFTER cron_full',
            'scheduled_full_at' => 'DATETIME NULL AFTER cron_delta',
            'scheduled_delta_at' => 'DATETIME NULL AFTER scheduled_full_at',
        ],
        'search_logs' => [
            'approx' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER results',
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

    /** Colonnes ENUM à élargir : table => [colonne => [valeur requise, nouvelle définition]] */
    private const ENUMS = [
        'import_runs' => ['type' => ['scores', "ENUM('full','delta','scores') NOT NULL"]],
    ];

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
        return $added;
    }
}
