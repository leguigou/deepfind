<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Config;
use DeepFind\Db;
use DeepFind\Meili\MeiliClient;
use DeepFind\Store\Store;

final class DashboardController
{
    public function index(): void
    {
        $meili = MeiliClient::admin();
        $healthy = $meili->health();
        $indexes = [];
        foreach (Auth::langs() as $lang) {
            foreach (['Produits' => Config::productIndex($lang), 'Articles' => Config::postIndex($lang)] as $label => $uid) {
                $count = null;
                if ($healthy) {
                    try {
                        $count = $meili->getIndex($uid) !== null ? (int) ($meili->stats($uid)['numberOfDocuments'] ?? 0) : null;
                    } catch (\Throwable) {
                        $count = null;
                    }
                }
                $indexes[] = ['lang' => $lang, 'label' => $label, 'uid' => $uid, 'count' => $count];
            }
        }
        $warnings = [];
        if (strlen((string) Config::get('ADMIN_TOKEN', '')) < 32) {
            $warnings[] = 'ADMIN_TOKEN fait moins de 32 caractères : générez-en un plus long (php bin/console admin:token).';
        }
        if (in_array(Config::get('APP_SECRET', ''), ['', 'change-me'], true)) {
            $warnings[] = 'APP_SECRET n\'est pas défini : les empreintes d\'IP ne sont pas sécurisées.';
        }
        if (Store::get()->name() === 'mysql') {
            $warnings[] = 'Compteurs anti-robots stockés en MySQL (APCu/Redis indisponible) : fonctionnel mais moins rapide.';
        }
        if ((int) Db::value('SELECT COUNT(*) FROM api_keys WHERE active = 1') === 0) {
            $warnings[] = 'Aucune clé publique active : créez-en une dans « Clés & intégration ».';
        }
        $scheduled = (int) Db::value("SELECT COUNT(*) FROM sources WHERE active = 1 AND (COALESCE(cron_full, '') <> '' OR COALESCE(cron_delta, '') <> '')");
        $lastTick = (string) (Db::value("SELECT value FROM settings WHERE name = 'scheduler_last_run'") ?? '');
        if ($scheduled > 0 && ($lastTick === '' || strtotime($lastTick) < time() - 300)) {
            $warnings[] = 'Des imports sont planifiés mais le planificateur ne tourne pas'
                . ($lastTick !== '' ? ' (dernier passage : ' . date('d/m H:i', (int) strtotime($lastTick)) . ')' : '')
                . ' : ajoutez « * * * * * php bin/console schedule:run » à la crontab (voir README).';
        }
        $stale = (int) Db::value("SELECT COUNT(*) FROM import_runs WHERE status = 'queued' AND created_at < NOW() - INTERVAL 5 MINUTE");
        if ($stale > 0) {
            $warnings[] = $stale . ' import(s) en attente depuis plus de 5 minutes : le worker (Supervisor) ou le cron « queue:work » ne tourne pas ?';
        }

        View::render('dashboard', [
            'active' => 'dashboard',
            'healthy' => $healthy,
            'indexes' => $indexes,
            'warnings' => $warnings,
            'store' => Store::get()->name(),
            'runs' => Db::all('SELECT r.*, s.code FROM import_runs r JOIN sources s ON s.id = r.source_id ORDER BY r.id DESC LIMIT 8'),
            'kpi' => Db::one(
                'SELECT COUNT(*) AS searches, COALESCE(SUM(results = 0), 0) AS zero, COALESCE(AVG(took_ms), 0) AS took,
                        COUNT(DISTINCT ip_hash) AS visitors
                 FROM search_logs WHERE created_at >= NOW() - INTERVAL 1 DAY AND is_final = 1'
            ),
            'blocked' => (int) Db::value('SELECT COUNT(*) FROM ip_blocks WHERE blocked_until IS NULL OR blocked_until > NOW()'),
            'botEvents' => (int) Db::value('SELECT COUNT(*) FROM bot_events WHERE created_at >= NOW() - INTERVAL 1 DAY'),
        ], 'Tableau de bord');
    }
}
