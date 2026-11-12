<?php

declare(strict_types=1);

namespace DeepFind\Import;

/** Avancement lisible d'un import à partir de sa ligne import_runs (pourcentage, étape, temps restant). */
final class Progress
{
    public const PHASES = [
        'download' => 'Téléchargement du flux',
        'count' => 'Comptage des éléments',
        'read' => 'Lecture et préparation des produits',
        'group' => 'Regroupement parents / enfants',
        'index' => 'Indexation dans le moteur',
        'swap' => 'Bascule vers le nouvel index',
        'done' => 'Terminé',
    ];

    /** Bornes [début, fin] de chaque étape dans la barre globale (en %). */
    private const SPANS = [
        'download' => [0, 10], 'count' => [10, 12], 'read' => [12, 75], 'group' => [75, 80],
        'index' => [80, 97], 'swap' => [97, 99], 'done' => [100, 100],
    ];

    public static function of(array $run): array
    {
        $status = (string) $run['status'];
        $phase = (string) ($run['phase'] ?? '');
        $total = (int) ($run['items_total'] ?? 0);
        $read = (int) $run['items_read'];
        $active = in_array($status, ['queued', 'running'], true);

        if ($status === 'queued') {
            return self::out($run, 0, 'En attente du worker', 'L\'import démarre dès qu\'un worker est disponible.', $active);
        }
        if ($status === 'success' || $phase === 'done') {
            return self::out($run, 100, 'Terminé', self::counts($run), false);
        }
        if (!$active) {
            return self::out($run, self::percent($run, $phase, $total, $read), $status === 'aborted' ? 'Bloqué (index en ligne conservé)' : 'Échec', (string) $run['message'], false);
        }

        $detail = match ($phase) {
            'download' => (int) $run['bytes_total'] > 0
                ? sprintf('%s / %s', self::mb((int) $run['bytes_done']), self::mb((int) $run['bytes_total']))
                : self::mb((int) $run['bytes_done']) . ' reçus',
            'count' => 'Lecture rapide du fichier (' . self::mb((int) $run['bytes_total']) . ')',
            'read' => $total > 0 ? sprintf('%s / %s éléments', number_format($read, 0, ',', ' '), number_format($total, 0, ',', ' ')) : number_format($read, 0, ',', ' ') . ' éléments',
            'group' => number_format((int) $run['items_indexed'], 0, ',', ' ') . ' produits construits',
            'index' => sprintf('lot %d / %d traité par Meilisearch', (int) $run['tasks_done'], (int) $run['tasks_total']),
            'swap' => 'Contrôle du nombre de produits puis mise en ligne instantanée',
            default => '',
        };
        return self::out($run, self::percent($run, $phase, $total, $read), self::PHASES[$phase] ?? 'Démarrage', $detail, true);
    }

    private static function percent(array $run, string $phase, int $total, int $read): int
    {
        [$a, $b] = self::SPANS[$phase] ?? [0, 0];
        $ratio = match ($phase) {
            'download' => (int) $run['bytes_total'] > 0 ? (int) $run['bytes_done'] / (int) $run['bytes_total'] : 0.5,
            'read' => $total > 0 ? min(1, $read / $total) : 0,
            'index' => (int) $run['tasks_total'] > 0 ? (int) $run['tasks_done'] / (int) $run['tasks_total'] : 0,
            default => 0.5,
        };
        return (int) floor($a + ($b - $a) * $ratio);
    }

    private static function out(array $run, int $pct, string $label, string $detail, bool $active): array
    {
        $elapsed = !empty($run['started_at']) ? max(0, (!empty($run['finished_at']) ? strtotime($run['finished_at']) : time()) - strtotime($run['started_at'])) : 0;
        $eta = $active && $pct >= 5 && $pct < 100 ? (int) round($elapsed * (100 - $pct) / $pct) : null;
        return [
            'id' => (int) $run['id'],
            'status' => (string) $run['status'],
            'phase' => (string) ($run['phase'] ?? ''),
            'active' => $active,
            'percent' => $pct,
            'label' => $label,
            'detail' => $detail,
            'items_read' => (int) $run['items_read'],
            'items_total' => (int) ($run['items_total'] ?? 0),
            'items_indexed' => (int) $run['items_indexed'],
            'items_rejected' => (int) $run['items_rejected'],
            'elapsed' => self::duration($elapsed),
            'eta' => $eta !== null ? self::duration($eta) : null,
            'message' => (string) ($run['message'] ?? ''),
        ];
    }

    private static function counts(array $run): string
    {
        return sprintf('%s indexés, %s rejetés', number_format((int) $run['items_indexed'], 0, ',', ' '), number_format((int) $run['items_rejected'], 0, ',', ' '));
    }

    private static function mb(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', ' ') . ' Mo';
    }

    public static function duration(int $s): string
    {
        return $s < 60 ? $s . ' s' : intdiv($s, 60) . ' min ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . ' s';
    }
}
