<?php

declare(strict_types=1);

namespace DeepFind\Logging;

use DeepFind\Db;
use DeepFind\Settings;
use DeepFind\Store\KvStore;
use DeepFind\Text;

/**
 * Journal des recherches (table search_logs).
 * Pendant la frappe, « pal », « palm », « palme » arrivent successivement : grâce à l'identifiant de
 * session de page (sid, aléatoire, sans cookie), seule la dernière requête est marquée is_final = 1.
 */
final class SearchLogger
{
    public function __construct(private readonly KvStore $store)
    {
    }

    public function log(array $e): ?int
    {
        if (Settings::int('log_searches') !== 1 || ($e['query_norm'] ?? '') === '') {
            return null;
        }
        $id = Db::insert('search_logs', [
            'project_id' => \DeepFind\Project::id(),
            'created_at' => date('Y-m-d H:i:s'),
            'api_key_id' => $e['api_key_id'],
            'lang' => $e['lang'],
            'query' => Text::truncate($e['query'], 255),
            'query_norm' => Text::truncate($e['query_norm'], 255),
            'filters' => $e['filters'] ?: null,
            // Aucun produit ne contient tous les mots : 0 résultat (les produits approchants sont comptés à part)
            'results' => !empty($e['approx']) ? 0 : $e['results'],
            'approx' => !empty($e['approx']) ? 1 : 0,
            'approx_results' => !empty($e['approx']) ? $e['results'] : 0,
            'took_ms' => min(65535, $e['took_ms']),
            'ip_hash' => $e['ip_hash'],
            'ip_masked' => $e['ip_masked'],
            'user_agent' => Text::truncate($e['user_agent'], 255),
            'sid' => $e['sid'] !== '' ? $e['sid'] : null,
            'is_final' => 1,
            'custom_ids' => !empty($e['custom_ids']) ? ',' . implode(',', array_map('intval', $e['custom_ids'])) . ',' : null,
            'banner_id' => !empty($e['banner_id']) ? (int) $e['banner_id'] : null,
        ]);
        $sid = $e['sid'];
        if ($sid !== '') {
            $prev = $this->store->get('last:' . $sid);
            if (is_array($prev) && (time() - $prev['t']) <= 20) {
                $a = $prev['q'];
                $b = $e['query_norm'];
                if ($a !== $b && (str_starts_with($b, $a) || str_starts_with($a, $b)) && ($prev['f'] === ($e['filters'] ?: ''))) {
                    Db::exec('UPDATE search_logs SET is_final = 0 WHERE id = ?', [$prev['id']]);
                }
            }
            $this->store->set('last:' . $sid, ['id' => $id, 'q' => $e['query_norm'], 'f' => $e['filters'] ?: '', 't' => time()], 60);
        }
        return $id;
    }

    public function click(array $c): void
    {
        Db::insert('click_logs', [
            'project_id' => \DeepFind\Project::id(),
            'created_at' => date('Y-m-d H:i:s'),
            'lang' => $c['lang'],
            'query_norm' => Text::truncate($c['query_norm'], 255),
            'doc_type' => in_array($c['doc_type'], ['post', 'banner'], true) ? $c['doc_type'] : 'product',
            'doc_id' => Text::truncate($c['doc_id'], 190),
            'position' => max(0, min(1000, $c['position'])),
            'ip_hash' => $c['ip_hash'],
            'sid' => $c['sid'] !== '' ? $c['sid'] : null,
            'custom_result_id' => !empty($c['custom_result_id']) ? (int) $c['custom_result_id'] : null,
        ]);
    }

    /** Suppression des journaux au-delà de la durée de conservation. */
    public static function purge(): array
    {
        $days = max(1, Settings::int('log_retention_days'));
        $limit = date('Y-m-d H:i:s', time() - $days * 86400);
        $out = [];
        foreach (['search_logs', 'click_logs', 'bot_events'] as $t) {
            $n = 0;
            do {
                $deleted = Db::exec("DELETE FROM `$t` WHERE created_at < ? LIMIT 10000", [$limit]);
                $n += $deleted;
            } while ($deleted === 10000);
            $out[$t] = $n;
        }
        $out['ip_blocks_expired'] = Db::exec('DELETE FROM ip_blocks WHERE blocked_until IS NOT NULL AND blocked_until < NOW() - INTERVAL 7 DAY');
        return $out;
    }
}
