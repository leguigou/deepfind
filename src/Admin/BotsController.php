<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Db;
use DeepFind\Security\BotGuard;
use DeepFind\Security\ClientIp;
use DeepFind\Settings;
use DeepFind\Store\Store;

/** Robots & blocages : IP les plus actives, événements, blocages manuels/automatiques. */
final class BotsController
{
    public function index(): void
    {
        $hours = (int) ($_GET['hours'] ?? 24);
        $hours = in_array($hours, [1, 6, 24, 168], true) ? $hours : 24;
        $since = date('Y-m-d H:i:s', time() - $hours * 3600);
        $top = Db::all(
            'SELECT l.ip_hash, MAX(l.ip_masked) AS ip_masked, COUNT(*) AS n, COUNT(DISTINCT l.query_norm) AS uniq,
                    MAX(l.user_agent) AS ua, MIN(l.created_at) AS first_at, MAX(l.created_at) AS last_at,
                    (SELECT COUNT(*) FROM bot_events b WHERE b.ip_hash = l.ip_hash AND b.created_at >= ?) AS events,
                    (SELECT 1 FROM ip_blocks k WHERE k.ip_hash = l.ip_hash AND (k.blocked_until IS NULL OR k.blocked_until > NOW())) AS blocked
             FROM search_logs l WHERE l.created_at >= ?
             GROUP BY l.ip_hash ORDER BY n DESC LIMIT 50',
            [$since, $since]
        );
        $repeated = Db::all(
            'SELECT ip_hash, MAX(ip_masked) AS ip_masked, query_norm, COUNT(*) AS n FROM search_logs
             WHERE created_at >= ? GROUP BY ip_hash, query_norm HAVING n >= 20 ORDER BY n DESC LIMIT 30',
            [$since]
        );
        View::render('bots', [
            'active' => 'bots',
            'hours' => $hours,
            'top' => $top,
            'repeated' => $repeated,
            'blocks' => Db::all('SELECT * FROM ip_blocks WHERE blocked_until IS NULL OR blocked_until > NOW() ORDER BY created_at DESC'),
            'events' => Db::all('SELECT * FROM bot_events ORDER BY id DESC LIMIT 100'),
            'eventStats' => Db::all('SELECT reason, COUNT(*) AS n, COUNT(DISTINCT ip_hash) AS ips FROM bot_events WHERE created_at >= ? GROUP BY reason', [$since]),
            'thresholds' => [
                'Par minute' => Settings::int('rate_per_minute'),
                'Par heure' => Settings::int('rate_per_hour'),
                'Même requête / min' => Settings::int('same_query_per_minute'),
                'Blocage après (minutes en dépassement)' => Settings::int('violations_before_block'),
                'Durée de blocage (min)' => Settings::int('block_minutes'),
            ],
        ], 'Robots & blocages');
    }

    public function block(): void
    {
        $guard = new BotGuard(Store::get());
        $minutes = max(0, (int) ($_POST['minutes'] ?? 1440));
        $reason = trim((string) ($_POST['reason'] ?? '')) ?: 'Blocage manuel';
        $hash = trim((string) ($_POST['ip_hash'] ?? ''));
        $ip = trim((string) ($_POST['ip'] ?? ''));
        if ($ip !== '') {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                Auth::flash('error', 'Adresse IP invalide.');
                App::back('/admin/bots');
                return;
            }
            $guard->block(ClientIp::hash($ip), $ip, 'manual', $reason, $minutes);
        } elseif (preg_match('/^[a-f0-9]{16}$/', $hash)) {
            $masked = (string) (Db::value('SELECT ip_masked FROM search_logs WHERE ip_hash = ? ORDER BY id DESC LIMIT 1', [$hash]) ?? '');
            $guard->block($hash, '', 'manual', $reason, $minutes, $masked);
        } else {
            Auth::flash('error', 'IP ou empreinte manquante.');
            App::back('/admin/bots');
            return;
        }
        Auth::flash('ok', 'IP bloquée ' . ($minutes > 0 ? 'pour ' . $minutes . ' minutes' : 'définitivement') . '.');
        App::back('/admin/bots');
    }

    public function unblock(): void
    {
        $hash = (string) ($_POST['ip_hash'] ?? '');
        if (preg_match('/^[a-f0-9]{16}$/', $hash)) {
            (new BotGuard(Store::get()))->unblock($hash);
            Auth::flash('ok', 'IP débloquée.');
        }
        App::back('/admin/bots');
    }
}
