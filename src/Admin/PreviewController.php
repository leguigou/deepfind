<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Db;
use DeepFind\Meili\MeiliClient;
use DeepFind\Search\SearchRequest;
use DeepFind\Search\SearchService;

final class PreviewController
{
    /** Aperçu interne : affiche marge, score métier et règles appliquées (jamais exposés publiquement). */
    public function preview(): void
    {
        $params = $_GET + ['lang' => Auth::lang(), 'limit' => 48];
        $params['lang'] = Auth::lang();
        $result = null;
        $error = null;
        if (isset($_GET['q'])) {
            try {
                $result = (new SearchService(MeiliClient::search()))->search(SearchRequest::fromArray($params, true), true);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $adminBoosts = [];
        $pins = [];
        if ($result && $result['products']) {
            $skus = array_column($result['products'], 'sku');
            $in = implode(',', array_fill(0, count($skus), '?'));
            foreach (Db::all("SELECT sku, boost FROM product_boosts WHERE lang = ? AND sku IN ($in)", [Auth::lang(), ...$skus]) as $b) {
                $adminBoosts[mb_strtoupper($b['sku'], 'UTF-8')] = (int) $b['boost'];
            }
            foreach (Db::all("SELECT id, sku, position FROM query_rules WHERE lang = ? AND query = ? AND action = 'pin'", [Auth::lang(), \DeepFind\Text::normalize((string) ($_GET['q'] ?? ''))]) as $pin) {
                $pins[mb_strtoupper($pin['sku'], 'UTF-8')] = $pin;
            }
        }
        $back = '/admin/preview?' . http_build_query(array_intersect_key($_GET, array_flip(['q', 'sort', 'margin_min', 'in_stock', 'on_sale'])));
        View::render('preview', ['active' => 'preview', 'p' => $_GET, 'result' => $result, 'error' => $error,
            'adminBoosts' => $adminBoosts, 'pins' => $pins, 'back' => $back], 'Aperçu interne');
    }

    /** Page de démonstration du widget (simule l'en-tête du site). */
    public function demo(): void
    {
        $keys = Db::all('SELECT name, public_key, langs FROM api_keys WHERE active = 1 ORDER BY id');
        $key = (string) ($_GET['key'] ?? ($keys[0]['public_key'] ?? ''));
        View::render('demo', ['keys' => $keys, 'key' => $key, 'lang' => Auth::lang()], 'Démo du widget', false);
    }
}
