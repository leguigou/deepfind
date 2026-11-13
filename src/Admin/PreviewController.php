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
                $result = (new SearchService(MeiliClient::search()))->search(SearchRequest::fromArray($params, true), true, !empty($_GET['show_disabled']));
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
            // Produits choisis un par un dans un résultat personnalisé de cette recherche (position = rang dans sa liste)
            $pins = \DeepFind\Rules\CustomResults::pinsFor(Auth::lang(), (string) ($_GET['q'] ?? ''));
        }
        // Clics réels des 30 derniers jours (le chiffre de l'index n'est mis à jour qu'au recalcul des scores)
        $liveClicks = [];
        if ($result && $result['products']) {
            $ids = array_values(array_unique(array_map('strval', array_column($result['products'], 'id'))));
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::all("SELECT doc_id, COUNT(*) AS n FROM click_logs WHERE project_id = ? AND lang = ? AND doc_type = 'product' AND created_at >= ? AND doc_id IN ($in) GROUP BY doc_id",
                [\DeepFind\Project::id(), Auth::lang(), date('Y-m-d H:i:s', time() - 30 * 86400), ...$ids]) as $c) {
                $liveClicks[(string) $c['doc_id']] = (int) $c['n'];
            }
        }
        $disabledRules = [];
        foreach (Db::all("SELECT id, sku FROM query_rules WHERE project_id = ? AND lang = ? AND query = '*' AND action = 'hide'", [\DeepFind\Project::id(), Auth::lang()]) as $d) {
            $disabledRules[mb_strtoupper($d['sku'], 'UTF-8')] = (int) $d['id'];
        }
        // Paramètres conservés après une action (boost, épinglage, désactivation) : recherche, tri, interrupteurs et filtres de gauche
        $keep = array_intersect_key($_GET, array_flip(['q', 'sort', 'margin_min', 'in_stock', 'on_sale', 'show_disabled', 'brand', 'category', 'attr']));
        $back = '/admin/preview?' . http_build_query($keep);
        View::render('preview', ['active' => 'preview', 'p' => $_GET, 'result' => $result, 'error' => $error,
            'adminBoosts' => $adminBoosts, 'pins' => $pins, 'back' => $back, 'keep' => $keep, 'liveClicks' => $liveClicks, 'disabledRules' => $disabledRules, 'attrLabels' => \DeepFind\Settings::json('attr_labels_' . Auth::lang()),
            'words' => \DeepFind\Search\Relevance::words((string) ($_GET['q'] ?? ''), Auth::lang())], 'Aperçu interne');
    }

    /** Tous les produits désactivés (masqués de toutes les recherches), avec filtre et réactivation. */
    public function disabledList(): void
    {
        $lang = Auth::lang();
        $rows = Db::all("SELECT sku, MIN(created_at) AS created_at FROM query_rules WHERE project_id = ? AND lang = ? AND query = '*' AND action = 'hide' GROUP BY sku ORDER BY created_at DESC",
            [\DeepFind\Project::id(), $lang]);
        $info = [];
        $error = null;
        try {
            $meili = MeiliClient::admin();
            $index = \DeepFind\Config::productIndex($lang);
            foreach (array_chunk(array_map(static fn ($r) => mb_strtoupper($r['sku'], 'UTF-8'), $rows), 100) as $chunk) {
                foreach ($meili->fetchDocuments($index, \DeepFind\Search\Filter::in('all_skus', $chunk), count($chunk) * 2) as $d) {
                    foreach ($d['all_skus'] ?? [$d['sku']] as $s) {
                        $info[mb_strtoupper((string) $s, 'UTF-8')] ??= $d;
                    }
                }
            }
        } catch (\Throwable $e) {
            $error = 'Moteur indisponible : noms et prix non affichés (' . $e->getMessage() . ').';
        }
        $filter = trim((string) ($_GET['f'] ?? ''));
        if ($filter !== '') {
            $needle = \DeepFind\Text::normalize($filter);
            $rows = array_values(array_filter($rows, static function ($r) use ($info, $needle) {
                $d = $info[mb_strtoupper($r['sku'], 'UTF-8')] ?? [];
                return str_contains(\DeepFind\Text::normalize($r['sku'] . ' ' . ($d['name'] ?? '') . ' ' . ($d['brand'] ?? ''), 500), $needle);
            }));
        }
        $back = '/admin/disabled' . ($filter !== '' ? '?' . http_build_query(['f' => $filter]) : '');
        View::render('disabled', ['active' => 'disabled', 'rows' => $rows, 'info' => $info, 'filter' => $filter, 'error' => $error, 'back' => $back], 'Produits désactivés');
    }

    /** Réactive tous les produits désactivés (projet et langue courants). */
    public function enableAll(): void
    {
        $n = Db::exec("DELETE FROM query_rules WHERE project_id = ? AND lang = ? AND query = '*' AND action = 'hide'", [\DeepFind\Project::id(), Auth::lang()]);
        RulesRepository::bumpVersion();
        Auth::flash('ok', sprintf('%d produit(s) réactivé(s).', $n));
        App::back('/admin/disabled');
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
