<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Db;
use DeepFind\Text;

final class StatsController
{
    public function stats(): void
    {
        $days = (int) ($_GET['days'] ?? 7);
        $days = in_array($days, [1, 7, 30, 90], true) ? $days : 7;
        $lang = Auth::lang();
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $p = [$lang, $since];
        $kpi = Db::one(
            'SELECT COUNT(*) AS searches, COUNT(DISTINCT query_norm) AS uniq, COALESCE(SUM(results = 0), 0) AS zero,
                    COALESCE(AVG(took_ms), 0) AS took, COUNT(DISTINCT sid) AS sessions
             FROM search_logs WHERE lang = ? AND created_at >= ? AND is_final = 1',
            $p
        ) ?? [];
        $kpi['clicks'] = (int) Db::value('SELECT COUNT(*) FROM click_logs WHERE lang = ? AND created_at >= ?', $p);
        $top = Db::all(
            'SELECT l.query_norm, COUNT(*) AS n, ROUND(AVG(l.results)) AS avg_results, SUM(l.results = 0) AS zero,
                    (SELECT COUNT(*) FROM click_logs c WHERE c.lang = l.lang AND c.query_norm = l.query_norm AND c.created_at >= ?) AS clicks
             FROM search_logs l WHERE l.lang = ? AND l.created_at >= ? AND l.is_final = 1
             GROUP BY l.query_norm ORDER BY n DESC LIMIT 50',
            [$since, $lang, $since]
        );
        $zero = Db::all(
            'SELECT query_norm, COUNT(*) AS n, MAX(created_at) AS last_at FROM search_logs
             WHERE lang = ? AND created_at >= ? AND is_final = 1 AND results = 0
             GROUP BY query_norm ORDER BY n DESC LIMIT 50',
            $p
        );
        $daily = Db::all(
            'SELECT DATE(created_at) AS d, COUNT(*) AS n, SUM(results = 0) AS zero FROM search_logs
             WHERE lang = ? AND created_at >= ? AND is_final = 1 GROUP BY DATE(created_at) ORDER BY d',
            $p
        );
        $clicked = Db::all(
            'SELECT doc_type, doc_id, COUNT(*) AS n FROM click_logs WHERE lang = ? AND created_at >= ?
             GROUP BY doc_type, doc_id ORDER BY n DESC LIMIT 20',
            $p
        );
        View::render('stats', compact('days', 'kpi', 'top', 'zero', 'daily', 'clicked') + ['active' => 'stats', 'lang' => $lang], 'Statistiques');
    }

    public function logs(): void
    {
        [$where, $params] = $this->filters();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = 100;
        $total = (int) Db::value("SELECT COUNT(*) FROM search_logs WHERE $where", $params);
        $rows = Db::all("SELECT * FROM search_logs WHERE $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
        View::render('logs', [
            'active' => 'logs', 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per)),
            'f' => ['q' => $_GET['q'] ?? '', 'ip' => $_GET['ip'] ?? '', 'zero' => $_GET['zero'] ?? '', 'all' => $_GET['all'] ?? '', 'days' => $_GET['days'] ?? '1'],
        ], 'Journal des recherches');
    }

    public function export(): void
    {
        [$where, $params] = $this->filters();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="recherches-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['date', 'langue', 'requete', 'requete_normalisee', 'filtres', 'resultats', 'temps_ms', 'ip_masquee', 'empreinte_ip', 'user_agent', 'finale'], ';', '"', '');
        $st = Db::pdo()->prepare("SELECT * FROM search_logs WHERE $where ORDER BY id DESC LIMIT 100000");
        $st->execute($params);
        while ($r = $st->fetch()) {
            fputcsv($out, [$r['created_at'], $r['lang'], $r['query'], $r['query_norm'], $r['filters'], $r['results'], $r['took_ms'], $r['ip_masked'], $r['ip_hash'], $r['user_agent'], $r['is_final']], ';', '"', '');
        }
        fclose($out);
    }

    /** @return array{0:string,1:array} */
    private function filters(): array
    {
        $where = ['lang = ?'];
        $params = [Auth::lang()];
        $days = max(1, min(400, (int) ($_GET['days'] ?? 1)));
        $where[] = 'created_at >= ?';
        $params[] = date('Y-m-d H:i:s', time() - $days * 86400);
        if (($q = trim((string) ($_GET['q'] ?? ''))) !== '') {
            $where[] = 'query_norm LIKE ?';
            $params[] = '%' . addcslashes(Text::normalize($q), '%_\\') . '%';
        }
        if (($ip = trim((string) ($_GET['ip'] ?? ''))) !== '' && preg_match('/^[a-f0-9]{16}$/', $ip)) {
            $where[] = 'ip_hash = ?';
            $params[] = $ip;
        }
        if (!empty($_GET['zero'])) {
            $where[] = 'results = 0';
        }
        if (empty($_GET['all'])) {
            $where[] = 'is_final = 1';
        }
        return [implode(' AND ', $where), $params];
    }
}
