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
        View::render('preview', ['active' => 'preview', 'p' => $_GET, 'result' => $result, 'error' => $error], 'Aperçu interne');
    }

    /** Page de démonstration du widget (simule l'en-tête du site). */
    public function demo(): void
    {
        $keys = Db::all('SELECT name, public_key, langs FROM api_keys WHERE active = 1 ORDER BY id');
        $key = (string) ($_GET['key'] ?? ($keys[0]['public_key'] ?? ''));
        View::render('demo', ['keys' => $keys, 'key' => $key, 'lang' => Auth::lang()], 'Démo du widget', false);
    }
}
