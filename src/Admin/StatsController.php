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
            'SELECT COUNT(*) AS searches, COUNT(DISTINCT query_norm) AS uniq, COALESCE(SUM((results = 0 OR approx = 1)), 0) AS zero,
                    COALESCE(AVG(took_ms), 0) AS took, COUNT(DISTINCT sid) AS sessions
             FROM search_logs WHERE lang = ? AND created_at >= ? AND is_final = 1',
            $p
        ) ?? [];
        $kpi['clicks'] = (int) Db::value('SELECT COUNT(*) FROM click_logs WHERE lang = ? AND created_at >= ?', $p);
        $top = Db::all(
            'SELECT l.query_norm, COUNT(*) AS n, ROUND(AVG(l.results)) AS avg_results, SUM(l.results = 0 OR l.approx = 1) AS zero,
                    (SELECT COUNT(*) FROM click_logs c WHERE c.lang = l.lang AND c.query_norm = l.query_norm AND c.created_at >= ?) AS clicks
             FROM search_logs l WHERE l.lang = ? AND l.created_at >= ? AND l.is_final = 1
             GROUP BY l.query_norm ORDER BY n DESC LIMIT 50',
            [$since, $lang, $since]
        );
        $zero = Db::all(
            'SELECT query_norm, COUNT(*) AS n, MAX(created_at) AS last_at, MAX(approx) AS approx, MAX(results) AS results FROM search_logs
             WHERE lang = ? AND created_at >= ? AND is_final = 1 AND (results = 0 OR approx = 1)
             GROUP BY query_norm ORDER BY n DESC LIMIT 50',
            $p
        );
        $daily = Db::all(
            'SELECT DATE(created_at) AS d, COUNT(*) AS n, SUM((results = 0 OR approx = 1)) AS zero FROM search_logs
             WHERE lang = ? AND created_at >= ? AND is_final = 1 GROUP BY DATE(created_at) ORDER BY d',
            $p
        );
        // Produits cliqués : clics, requêtes d'origine, position moyenne + nom/SKU/image lus dans l'index
        $clicked = Db::all(
            "SELECT doc_id, COUNT(*) AS n, COUNT(DISTINCT query_norm) AS queries, ROUND(AVG(position), 1) AS pos,
                    SUBSTRING_INDEX(GROUP_CONCAT(query_norm ORDER BY created_at DESC SEPARATOR '|'), '|', 3) AS last_queries
             FROM click_logs WHERE lang = ? AND created_at >= ? AND doc_type = 'product'
             GROUP BY doc_id ORDER BY n DESC LIMIT 30",
            $p
        );
        $info = self::productsInfo($lang, array_column($clicked, 'doc_id'));
        foreach ($clicked as &$c) {
            $c += $info[$c['doc_id']] ?? ['name' => null, 'sku' => null, 'image' => null, 'url' => null];
        }
        unset($c);
        $clickedPosts = Db::all(
            "SELECT doc_id, COUNT(*) AS n FROM click_logs WHERE lang = ? AND created_at >= ? AND doc_type = 'post'
             GROUP BY doc_id ORDER BY n DESC LIMIT 10",
            $p
        );
        // Requêtes fréquentes avec des résultats mais (presque) aucun clic : résultats probablement peu pertinents
        $noClick = Db::all(
            'SELECT l.query_norm, COUNT(*) AS n, ROUND(AVG(l.results)) AS avg_results,
                    (SELECT COUNT(*) FROM click_logs c WHERE c.lang = l.lang AND c.query_norm = l.query_norm AND c.created_at >= ?) AS clicks
             FROM search_logs l WHERE l.lang = ? AND l.created_at >= ? AND l.is_final = 1 AND l.results > 0 AND l.approx = 0
             GROUP BY l.query_norm HAVING n >= 3 AND clicks = 0 ORDER BY n DESC LIMIT 20',
            [$since, $lang, $since]
        );
        View::render('stats', compact('days', 'kpi', 'top', 'zero', 'daily', 'clicked', 'clickedPosts', 'noClick') + ['active' => 'stats', 'lang' => $lang], 'Statistiques');
    }

    /** @return array<string, array{name:?string, sku:?string, image:?string, url:?string}> */
    public static function productsInfo(string $lang, array $ids): array
    {
        $ids = array_values(array_filter(array_map('strval', $ids)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        try {
            $docs = \DeepFind\Meili\MeiliClient::admin()->fetchDocuments(\DeepFind\Config::productIndex($lang), \DeepFind\Search\Filter::in('id', $ids), count($ids));
            foreach ($docs as $d) {
                $out[$d['id']] = ['name' => $d['name'] ?? null, 'sku' => $d['sku'] ?? null, 'image' => $d['image'] ?? null, 'url' => $d['url'] ?? null];
            }
        } catch (\Throwable) {
            // moteur indisponible : on affiche seulement les identifiants
        }
        return $out;
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
            $where[] = '(results = 0 OR approx = 1)';
        }
        if (empty($_GET['all'])) {
            $where[] = 'is_final = 1';
        }
        return [implode(' AND ', $where), $params];
    }
}
