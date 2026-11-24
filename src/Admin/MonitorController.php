<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Http\Response;
use DeepFind\Logging\Metrics;

/** Analyse › Temps réel & charge : page (graphiques dessinés par monitor.js) et données JSON, rafraîchies en direct. */
final class MonitorController
{
    /** Périodes proposées : clé => [libellé, secondes]. */
    public const RANGES = ['1h' => ['1 heure', 3600], '6h' => ['6 heures', 21600], '24h' => ['24 heures', 86400], '5d' => ['5 jours', 432000]];

    public function index(): void
    {
        View::render('monitor', ['active' => 'monitor', 'ranges' => self::RANGES], 'Temps réel & charge');
    }

    /** ?range=1h|6h|24h|5d, ou ?since=AAAA-MM-JJTHH:MM (depuis un instant, 5 jours au plus) : la fin est toujours « maintenant ». */
    public function data(): void
    {
        $now = time();
        // minutes écoulées versées tout de suite (même sans trafic sur le site depuis)
        Metrics::maybeFlush($now);
        $since = (string) ($_GET['since'] ?? '');
        if ($since !== '' && ($ts = strtotime($since)) !== false) {
            $from = max($now - 5 * 86400, min($now - 300, $ts));
        } else {
            $range = self::RANGES[(string) ($_GET['range'] ?? '1h')] ?? self::RANGES['1h'];
            $from = $now - $range[1];
        }
        $data = Metrics::dashboard(\DeepFind\Project::id(), $from, $now);
        Response::json($data + ['generated_at' => $now], 200, ['Cache-Control' => 'no-store']);
    }
}
