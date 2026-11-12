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
    ];

    /** @return list<string> colonnes ajoutées */
    public static function run(): array
    {
        $added = [];
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
