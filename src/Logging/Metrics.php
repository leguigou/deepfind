<?php

declare(strict_types=1);

namespace DeepFind\Logging;

use DeepFind\Db;
use DeepFind\Store\KvStore;
use DeepFind\Store\Store;

/**
 * Supervision (Analyse › Temps réel & charge).
 * Chaque appel de l'API publique incrémente des compteurs de la minute dans le stockage clé/valeur (APCu / Redis : aucune
 * écriture en base par requête). Une fois par minute, le premier appel qui suit verse les minutes écoulées dans metrics_minute
 * (+ charge de la machine dans server_minute) ; l'extranet ajoute la minute en cours, lue en mémoire : les chiffres sont à jour
 * à la seconde. Visiteurs actifs : empreinte du jour de la mesure d'audience (sans IP), une écriture par visiteur et par minute
 * au plus dans live_visitors (lignes de plus de 15 minutes supprimées). Conservation : 6 jours.
 */
final class Metrics
{
    public const COUNTERS = ['requests', 'searches', 'searches_cached', 'searches_empty', 'pageviews', 'clicks', 'configs', 'events',
        'errors', 'denied', 'visitors', 'ms_sum', 's_ms_sum', 'h50', 'h100', 'h200', 'h500', 'h1000', 'hmore'];
    /** Répartition des temps de réponse des recherches (ms) : colonne => borne haute exclue. */
    public const BUCKETS = ['h50' => 50, 'h100' => 100, 'h200' => 200, 'h500' => 500, 'h1000' => 1000, 'hmore' => PHP_INT_MAX];
    public const RETENTION_DAYS = 6;
    /** Visiteur « actif » : vu dans les 5 dernières minutes. */
    public const ACTIVE_SECONDS = 300;
    private const TTL = 3600;

    /** Projet de la requête en cours (fixé après le contrôle de la clé) ; null = requête non comptée. */
    public static ?int $project = null;
    /** Recherche en cours : réponse venue du cache, aucun résultat. */
    public static array $search = [];

    private static function key(int $pid, int $minute, string $counter): string
    {
        return 'mt:' . $pid . ':' . $minute . ':' . $counter;
    }

    /** Type de requête d'après le chemin de l'API ; null = non compté (santé, pré-vol CORS). */
    public static function kind(string $path): ?string
    {
        return match ($path) {
            '/api/v1/search' => 'searches', '/api/v1/click' => 'clicks', '/api/v1/visit' => 'pageviews', '/api/v1/config' => 'configs',
            '/api/v1/popin', '/api/v1/carousel', '/api/v1/carousels' => 'events',
            default => null,
        };
    }

    /**
     * Compte une requête de l'API (appelé à la fin de son traitement, réponse déjà envoyée).
     * @param int $ms durée jusqu'à l'envoi de la réponse
     */
    public static function record(string $path, int $status, int $ms, string $ip, string $ua, ?int $now = null, ?KvStore $store = null): void
    {
        $kind = self::kind($path);
        if ($kind === null || self::$project === null) {
            return;
        }
        $now ??= time();
        $store ??= Store::get();
        $pid = self::$project;
        $minute = intdiv($now, 60);
        $inc = static fn (string $c, int $by = 1) => $by === 1 ? $store->incr(self::key($pid, $minute, $c), self::TTL) : $store->incrBy(self::key($pid, $minute, $c), $by, self::TTL);
        $inc('requests');
        $inc($kind);
        $ms = max(0, min(600000, $ms));
        if ($ms > 0) {
            $inc('ms_sum', $ms);
        }
        if ($status >= 500) {
            $inc('errors');
        } elseif (in_array($status, [401, 403, 429], true)) {
            $inc('denied');
        }
        if ($kind === 'searches' && $status === 200) {
            if ($ms > 0) {
                $inc('s_ms_sum', $ms);
            }
            foreach (self::BUCKETS as $col => $limit) {
                if ($ms < $limit) {
                    $inc($col);
                    break;
                }
            }
            if (!empty(self::$search['cached'])) {
                $inc('searches_cached');
            }
            if (!empty(self::$search['empty'])) {
                $inc('searches_empty');
            }
        }
        // visiteurs : refusés (robots, IP bloquées) non comptés
        if ($status < 400 && $ip !== '') {
            try {
                $vh = Audience::visitorHash($ip, $ua, date('Y-m-d', $now));
                if ($store->incr('mv:' . $pid . ':' . $minute . ':' . $vh, 150) === 1) {
                    $inc('visitors');
                    // dernière activité : une écriture par visiteur et par minute au plus
                    Db::exec('INSERT INTO live_visitors (project_id, vhash, last_seen) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_seen = GREATEST(last_seen, ?)',
                        [$pid, $vh, $now, $now]);
                }
            } catch (\Throwable $e) {
                error_log('[deepfind] supervision : ' . $e->getMessage());
            }
        }
        self::maybeFlush($now, $store);
    }

    /** Verse les minutes écoulées si ce n'est pas déjà fait (un seul processus à la fois). */
    public static function maybeFlush(?int $now = null, ?KvStore $store = null): void
    {
        $now ??= time();
        $store ??= Store::get();
        $current = intdiv($now, 60);
        $done = (int) ($store->get('mt:flushed') ?? 0);
        if ($done >= $current - 1) {
            return;
        }
        if ($store->incr('mt:lock:' . $current, 120) !== 1) {
            return;
        }
        try {
            self::flush($current, $done, $store);
        } catch (\Throwable $e) {
            error_log('[deepfind] supervision (versement) : ' . $e->getMessage());
        }
    }

    /** Verse dans metrics_minute les minutes de ]$done ; $current[ (30 au plus), pour tous les projets, puis la charge de la machine. */
    public static function flush(int $current, int $done, KvStore $store): void
    {
        $from = max($done + 1, $current - 30);
        $projects = array_map('intval', array_keys(\DeepFind\Project::all()));
        for ($m = $from; $m < $current; $m++) {
            foreach ($projects as $pid) {
                $row = [];
                foreach (self::COUNTERS as $c) {
                    $k = self::key($pid, $m, $c);
                    $v = (int) ($store->get($k) ?? 0);
                    if ($v > 0) {
                        $row[$c] = $v;
                        $store->delete($k);
                    }
                }
                if ($row === []) {
                    continue;
                }
                $cols = array_keys($row);
                Db::exec('INSERT INTO metrics_minute (project_id, minute, ' . implode(', ', $cols) . ') VALUES (?, ?' . str_repeat(', ?', count($cols)) . ')
                    ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($c) => "$c = $c + VALUES($c)", $cols)),
                    [$pid, date('Y-m-d H:i:00', $m * 60), ...array_values($row)]);
            }
        }
        self::sampleServer($current - 1);
        $store->set('mt:flushed', $current - 1, 86400 * 7);
        // ménage : une fois par heure
        if ($current % 60 === 0 || $done === 0) {
            self::purge($current * 60);
        }
    }

    /** Charge de la machine pour la minute donnée (première mesure gardée). */
    public static function sampleServer(int $minute): void
    {
        $s = self::server();
        Db::exec('INSERT IGNORE INTO server_minute (minute, load1, cpus, mem_pct) VALUES (?, ?, ?, ?)',
            [date('Y-m-d H:i:00', $minute * 60), $s['load1'], $s['cpus'], $s['mem_pct']]);
    }

    /** @return array{load1: float, cpus: int, mem_pct: ?float} */
    public static function server(): array
    {
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0.0]) : [0.0];
        $cpus = 1;
        if (is_readable('/proc/cpuinfo')) {
            $cpus = max(1, (int) preg_match_all('/^processor\s*:/m', (string) @file_get_contents('/proc/cpuinfo')));
        }
        $mem = null;
        if (is_readable('/proc/meminfo')) {
            $info = (string) @file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)/', $info, $t) && preg_match('/MemAvailable:\s+(\d+)/', $info, $a) && (int) $t[1] > 0) {
                $mem = round(100 * (1 - (int) $a[1] / (int) $t[1]), 1);
            }
        }
        return ['load1' => round((float) $load[0], 2), 'cpus' => $cpus, 'mem_pct' => $mem];
    }

    public static function purge(int $now): void
    {
        $limit = date('Y-m-d H:i:00', $now - self::RETENTION_DAYS * 86400);
        Db::exec('DELETE FROM metrics_minute WHERE minute < ? LIMIT 50000', [$limit]);
        Db::exec('DELETE FROM server_minute WHERE minute < ? LIMIT 50000', [$limit]);
        Db::exec('DELETE FROM live_visitors WHERE last_seen < ?', [$now - 900]);
    }

    /** Minute en cours (pas encore versée), lue en mémoire. @return array<string,int> */
    public static function current(int $pid, ?int $now = null, ?KvStore $store = null): array
    {
        $now ??= time();
        $store ??= Store::get();
        $out = [];
        foreach (self::COUNTERS as $c) {
            $out[$c] = (int) ($store->get(self::key($pid, intdiv($now, 60), $c)) ?? 0);
        }
        return $out;
    }

    public static function activeVisitors(int $pid, ?int $now = null): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM live_visitors WHERE project_id = ? AND last_seen >= ?', [$pid, ($now ?? time()) - self::ACTIVE_SECONDS]);
    }

    // ------------------------------------------------------------------ Lecture pour l'extranet

    /** Pas des courbes selon la durée affichée (≈ 60 à 120 points). */
    public static function step(int $seconds): int
    {
        return match (true) {
            $seconds <= 3600 => 60, $seconds <= 3 * 3600 => 120, $seconds <= 6 * 3600 => 300, $seconds <= 12 * 3600 => 600,
            $seconds <= 24 * 3600 => 900, $seconds <= 2 * 86400 => 1800, default => 3600,
        };
    }

    /**
     * Données de la page : séries par pas de temps, indicateurs de la période et de la période précédente, carte jour × heure,
     * répartition des temps de réponse.
     */
    public static function dashboard(int $pid, int $from, int $to, ?KvStore $store = null): array
    {
        $store ??= Store::get();
        $from = intdiv($from, 60) * 60;
        // durée en minutes entières : « 1 heure » reste une heure malgré l'arrondi de $from à la minute
        $step = self::step(intdiv($to - $from, 60) * 60);
        $start = intdiv($from, $step) * $step;
        $n = intdiv($to - $start, $step) + 1;
        $empty = array_fill_keys(self::COUNTERS, 0);
        $buckets = array_fill(0, $n, $empty);
        $minutes = array_fill(0, $n, 0);
        $rows = Db::all('SELECT UNIX_TIMESTAMP(minute) AS t, ' . implode(', ', self::COUNTERS) . ' FROM metrics_minute WHERE project_id = ? AND minute >= ? AND minute <= ?',
            [$pid, date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $to)]);
        // minute en cours, encore en mémoire
        $live = self::current($pid, $to, $store);
        if (array_sum($live) > 0) {
            $rows[] = ['t' => intdiv($to, 60) * 60] + $live;
        }
        $totals = $empty;
        foreach ($rows as $r) {
            $i = intdiv((int) $r['t'] - $start, $step);
            if ($i < 0 || $i >= $n || (int) $r['t'] < $from) {
                continue;
            }
            foreach (self::COUNTERS as $c) {
                $buckets[$i][$c] += (int) $r[$c];
                $totals[$c] += (int) $r[$c];
            }
        }
        // nombre de minutes écoulées par pas (dernier pas partiel) : moyenne des visiteurs par minute
        for ($i = 0; $i < $n; $i++) {
            $b0 = max($start + $i * $step, $from);
            $b1 = min($start + ($i + 1) * $step, $to + 60);
            $minutes[$i] = max(1, intdiv(max(0, $b1 - $b0), 60));
        }
        $server = [];
        foreach (Db::all('SELECT UNIX_TIMESTAMP(minute) AS t, load1, cpus, mem_pct FROM server_minute WHERE minute >= ? AND minute <= ?',
            [date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $to)]) as $r) {
            $i = intdiv((int) $r['t'] - $start, $step);
            if ($i >= 0 && $i < $n) {
                $server[$i][] = [(float) $r['load1'] / max(1, (int) $r['cpus']) * 100, $r['mem_pct'] === null ? null : (float) $r['mem_pct']];
            }
        }
        $series = ['t' => [], 'searches' => [], 'pageviews' => [], 'clicks' => [], 'events' => [], 'visitors' => [], 'avg_ms' => [], 'p95_ms' => [],
            'cpu' => [], 'mem' => [], 'errors' => [], 'denied' => []];
        foreach ($buckets as $i => $b) {
            $series['t'][] = $start + $i * $step;
            foreach (['searches', 'pageviews', 'clicks', 'events', 'errors', 'denied'] as $c) {
                $series[$c][] = $b[$c];
            }
            $series['visitors'][] = round($b['visitors'] / $minutes[$i], 1);
            $ns = self::histCount($b);
            $series['avg_ms'][] = $ns > 0 ? (int) round($b['s_ms_sum'] / $ns) : null;
            $series['p95_ms'][] = $ns > 0 ? self::percentile($b, 0.95) : null;
            $cpu = array_column($server[$i] ?? [], 0);
            $mem = array_values(array_filter(array_column($server[$i] ?? [], 1), static fn ($v) => $v !== null));
            $series['cpu'][] = $cpu ? round(max($cpu), 1) : null;
            $series['mem'][] = $mem ? round(max($mem), 1) : null;
        }
        $now = self::server();
        return [
            'from' => $from, 'to' => $to, 'step' => $step, 'series' => $series,
            'kpi' => self::kpis($totals, $to - $from + 60) + [
                'active' => self::activeVisitors($pid, $to),
                'searches_per_min' => self::recentRate($pid, $to, $store),
                'cpu_now' => round($now['load1'] / max(1, $now['cpus']) * 100, 1), 'load1' => $now['load1'], 'cpus' => $now['cpus'], 'mem_now' => $now['mem_pct'],
                'final_searches' => self::finalSearches($pid, $from, $to),
            ],
            'previous' => self::kpis(self::totals($pid, $from - ($to - $from + 60), $from - 1), $to - $from + 60),
            'histogram' => array_map(static fn ($c) => $totals[$c], array_combine(array_keys(self::BUCKETS), array_keys(self::BUCKETS))),
            'heatmap' => self::heatmap($pid, $to),
        ];
    }

    /** Indicateurs d'une période à partir de ses totaux. */
    private static function kpis(array $t, int $seconds): array
    {
        $ns = self::histCount($t);
        return [
            'requests' => $t['requests'], 'searches' => $t['searches'], 'pageviews' => $t['pageviews'], 'clicks' => $t['clicks'],
            'errors' => $t['errors'], 'denied' => $t['denied'],
            'error_rate' => $t['requests'] > 0 ? round(100 * $t['errors'] / $t['requests'], 2) : null,
            'cache_rate' => $t['searches'] > 0 ? round(100 * $t['searches_cached'] / $t['searches'], 1) : null,
            'empty_rate' => $t['searches'] > 0 ? round(100 * $t['searches_empty'] / $t['searches'], 1) : null,
            'avg_ms' => $ns > 0 ? (int) round($t['s_ms_sum'] / $ns) : null,
            'p95_ms' => $ns > 0 ? self::percentile($t, 0.95) : null,
            'req_per_min' => round($t['requests'] / max(1, $seconds / 60), 1),
        ];
    }

    private static function totals(int $pid, int $from, int $to): array
    {
        $r = Db::one('SELECT ' . implode(', ', array_map(static fn ($c) => "COALESCE(SUM($c), 0) AS $c", self::COUNTERS))
            . ' FROM metrics_minute WHERE project_id = ? AND minute >= ? AND minute <= ?', [$pid, date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to)]) ?? [];
        $out = [];
        foreach (self::COUNTERS as $c) {
            $out[$c] = (int) ($r[$c] ?? 0);
        }
        return $out;
    }

    private static function histCount(array $b): int
    {
        return array_sum(array_map(static fn ($c) => (int) $b[$c], array_keys(self::BUCKETS)));
    }

    /** Percentile approché d'après la répartition (interpolation dans la tranche ; au-delà d'1 s : 1 000 ms et plus). */
    private static function percentile(array $b, float $p): int
    {
        $n = self::histCount($b);
        $target = $p * $n;
        $acc = 0;
        $low = 0;
        foreach (self::BUCKETS as $c => $high) {
            $cnt = (int) $b[$c];
            if ($cnt > 0 && $acc + $cnt >= $target) {
                if ($high === PHP_INT_MAX) {
                    return 1000;
                }
                return (int) round($low + ($high - $low) * (($target - $acc) / $cnt));
            }
            $acc += $cnt;
            $low = $high === PHP_INT_MAX ? $low : $high;
        }
        return $low;
    }

    /** Recherches par minute, moyenne des 5 dernières minutes (minute en cours comprise, au prorata). */
    private static function recentRate(int $pid, int $now, KvStore $store): float
    {
        $sum = (int) Db::value('SELECT COALESCE(SUM(searches), 0) FROM metrics_minute WHERE project_id = ? AND minute >= ?',
            [$pid, date('Y-m-d H:i:00', $now - 240)]);
        $sum += self::current($pid, $now, $store)['searches'];
        return round($sum / (4 + max(1, $now % 60) / 60), 1);
    }

    /** Recherches « finales » (journal des recherches) : une par recherche tapée, pas par frappe ; null si le journal est désactivé. */
    private static function finalSearches(int $pid, int $from, int $to): ?int
    {
        if (\DeepFind\Settings::int('log_searches') !== 1) {
            return null;
        }
        return (int) Db::value('SELECT COUNT(*) FROM search_logs WHERE project_id = ? AND created_at >= ? AND created_at <= ? AND is_final = 1',
            [$pid, date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to + 59)]);
    }

    /** Requêtes de recherche par jour et par heure, 5 derniers jours (aujourd'hui compris). @return list<array{day:string, hours:list<int>}> */
    private static function heatmap(int $pid, int $now): array
    {
        $days = [];
        for ($d = 4; $d >= 0; $d--) {
            $days[date('Y-m-d', $now - $d * 86400)] = array_fill(0, 24, 0);
        }
        foreach (Db::all('SELECT DATE(minute) AS d, HOUR(minute) AS h, SUM(searches) AS n FROM metrics_minute WHERE project_id = ? AND minute >= ? GROUP BY d, h',
            [$pid, array_key_first($days) . ' 00:00:00']) as $r) {
            if (isset($days[$r['d']])) {
                $days[$r['d']][(int) $r['h']] += (int) $r['n'];
            }
        }
        $today = date('Y-m-d', $now);
        $days[$today][(int) date('G', $now)] += self::current($pid, $now)['searches'];
        $out = [];
        foreach ($days as $d => $hours) {
            $out[] = ['day' => $d, 'hours' => $hours];
        }
        return $out;
    }
}
