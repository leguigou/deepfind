<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Db;
use DeepFind\Meili\MeiliClient;
use DeepFind\Rules\RulesRepository;
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
            $quick = \DeepFind\Rules\BoostRules::productBoosts(Auth::lang());
            foreach ($skus as $sku) {
                $k = mb_strtoupper((string) $sku, 'UTF-8');
                if (isset($quick[$k])) {
                    $adminBoosts[$k] = $quick[$k];
                }
            }
            foreach (Db::all("SELECT id, sku, position FROM query_rules WHERE project_id = ? AND lang = ? AND query = ? AND action = 'pin'", [\DeepFind\Project::id(), Auth::lang(), \DeepFind\Text::normalize((string) ($_GET['q'] ?? ''))]) as $pin) {
                $pins[mb_strtoupper($pin['sku'], 'UTF-8')] = $pin;
            }
        }
        $disabledRules = [];
        foreach (Db::all("SELECT id, sku FROM query_rules WHERE project_id = ? AND lang = ? AND query = '*' AND action = 'hide'", [\DeepFind\Project::id(), Auth::lang()]) as $d) {
            $disabledRules[mb_strtoupper($d['sku'], 'UTF-8')] = (int) $d['id'];
        }
        $back = '/admin/preview?' . http_build_query(array_intersect_key($_GET, array_flip(['q', 'sort', 'margin_min', 'in_stock', 'on_sale'])));
        View::render('preview', ['active' => 'preview', 'p' => $_GET, 'result' => $result, 'error' => $error,
            'adminBoosts' => $adminBoosts, 'pins' => $pins, 'back' => $back, 'disabledRules' => $disabledRules, 'attrLabels' => \DeepFind\Settings::json('attr_labels_' . Auth::lang()),
            'words' => \DeepFind\Search\Relevance::words((string) ($_GET['q'] ?? ''), Auth::lang())], 'Aperçu interne');
    }

    /**
     * Désactive ou réactive un produit dans toutes les recherches (règle « masquer » sur la requête « * »).
     * Le produit reste dans l'index : il n'est simplement plus jamais renvoyé au site.
     */
    public function toggle(): void
    {
        $sku = trim((string) ($_POST['sku'] ?? ''));
        $lang = Auth::lang();
        if ($sku === '') {
            Auth::flash('error', 'SKU manquant.');
        } elseif (($_POST['state'] ?? '') === 'off') {
            $exists = Db::one("SELECT id FROM query_rules WHERE project_id = ? AND lang = ? AND query = '*' AND action = 'hide' AND sku = ?",
                [\DeepFind\Project::id(), $lang, $sku]);
            if ($exists === null) {
                RulesRepository::addRule($lang, '*', $sku, 'hide', null, '', '');
            }
            RulesRepository::bumpVersion();
            Auth::flash('ok', sprintf('Produit %s désactivé : il n\'apparaît plus dans aucune recherche du site.', $sku));
        } else {
            Db::exec("DELETE FROM query_rules WHERE project_id = ? AND lang = ? AND query = '*' AND action = 'hide' AND sku = ?",
                [\DeepFind\Project::id(), $lang, $sku]);
            RulesRepository::bumpVersion();
            Auth::flash('ok', sprintf('Produit %s réactivé.', $sku));
        }
        App::backTo('/admin/preview');
    }

    /** Page de démonstration du widget (simule l'en-tête du site). */
    public function demo(): void
    {
        $keys = Db::all('SELECT name, public_key, langs FROM api_keys WHERE active = 1 AND project_id = ? ORDER BY id', [\DeepFind\Project::id()]);
        $key = (string) ($_GET['key'] ?? ($keys[0]['public_key'] ?? ''));
        View::render('demo', ['keys' => $keys, 'key' => $key, 'lang' => Auth::lang()], 'Démo du widget', false);
    }
}
