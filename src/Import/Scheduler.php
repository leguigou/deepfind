<?php

declare(strict_types=1);

namespace DeepFind\Import;

use DeepFind\Db;

/**
 * Planificateur : lancé chaque minute par le cron système (php bin/console schedule:run).
 * Pour chaque source active, met en file d'attente les imports dont l'heure est arrivée
 * (planification réglée dans l'extranet, rubrique Flux & mapping).
 */
final class Scheduler
{
    /** @return list<string> imports mis en file d'attente */
    public static function tick(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $minute = $now->setTime((int) $now->format('G'), (int) $now->format('i'));
        $queued = [];
        foreach (Db::all('SELECT * FROM sources WHERE active = 1') as $s) {
            foreach (['full' => 'cron_full', 'delta' => 'cron_delta'] as $type => $col) {
                $expr = trim((string) ($s[$col] ?? ''));
                if ($expr === '' || ($type === 'delta' && ($s['kind'] !== 'products' || trim((string) $s['delta_url']) === ''))) {
                    continue;
                }
                if (!Cron::matches($expr, $minute)) {
                    continue;
                }
                $stampCol = 'scheduled_' . $type . '_at';
                // Verrou « une seule fois par minute », même si deux planificateurs tournent en même temps.
                $claimed = Db::exec(
                    "UPDATE sources SET `$stampCol` = ? WHERE id = ? AND (`$stampCol` IS NULL OR `$stampCol` < ?)",
                    [$minute->format('Y-m-d H:i:s'), $s['id'], $minute->format('Y-m-d H:i:s')]
                );
                if ($claimed !== 1) {
                    continue;
                }
                $pending = Db::value("SELECT id FROM import_runs WHERE source_id = ? AND status IN ('queued','running') LIMIT 1", [$s['id']]);
                if ($pending !== null) {
                    continue; // import précédent pas terminé : on saute ce créneau
                }
                $id = Importer::queue((int) $s['id'], $type, 'cron');
                $queued[] = $s['code'] . ' ' . $type . ' #' . $id;
            }
        }
        // Recalcul des scores chaque nuit (clics des 30 derniers jours, boosts datés) : par projet et par langue
        foreach (array_keys(\DeepFind\Project::all()) as $pid) {
            \DeepFind\Project::use($pid);
            $scoresCron = trim(\DeepFind\Settings::get('scores_cron'));
            if (\DeepFind\Settings::get('scores_nightly') !== '1' || $scoresCron === '' || !Cron::matches($scoresCron, $minute)) {
                continue;
            }
            $stamp = $minute->format('Y-m-d H:i:s');
            $claimed = Db::exec(
                'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = IF(value < ?, ?, value)',
                ['scores_scheduled_at_' . $pid, $stamp, $stamp, $stamp]
            );
            if ($claimed > 0) {
                foreach (Db::all("SELECT MIN(id) AS id, lang FROM sources WHERE project_id = ? AND kind = 'products' AND active = 1 GROUP BY lang", [$pid]) as $s) {
                    if (Db::value("SELECT r.id FROM import_runs r JOIN sources x ON x.id = r.source_id WHERE x.project_id = ? AND x.lang = ? AND r.status IN ('queued','running') LIMIT 1", [$pid, $s['lang']]) === null) {
                        $queued[] = 'scores ' . \DeepFind\Project::current()['code'] . ' ' . $s['lang'] . ' #' . Importer::queue((int) $s['id'], 'scores', 'cron');
                    }
                }
            }
        }
        $t = $now->format('Y-m-d H:i:s');
        Db::exec("INSERT INTO settings (name, value) VALUES ('scheduler_last_run', ?) ON DUPLICATE KEY UPDATE value = ?", [$t, $t]);
        return $queued;
    }
}
