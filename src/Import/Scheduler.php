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
        $t = $now->format('Y-m-d H:i:s');
        Db::exec("INSERT INTO settings (name, value) VALUES ('scheduler_last_run', ?) ON DUPLICATE KEY UPDATE value = ?", [$t, $t]);
        return $queued;
    }
}
