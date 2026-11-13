<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Config;
use DeepFind\Db;
use DeepFind\Http\Response;
use DeepFind\Meili\MeiliClient;
use DeepFind\Rules\BoostRules;
use DeepFind\Rules\CustomResults;
use DeepFind\Rules\RulesRepository;
use DeepFind\Search\Filter;

/**
 * Résultats personnalisés : produits mis en avant ou seuls affichés, produits exclus, pour certains termes de recherche,
 * avec suivi impressions / clics / CTR. Remplace les « règles de résultats ».
 */
final class CustomResultsController
{
    private const DAYS = [7 => '7 jours', 30 => '30 jours', 90 => '90 jours'];

    public function index(): void
    {
        $lang = Auth::lang();
        $days = (int) ($_GET['days'] ?? 30);
        $days = isset(self::DAYS[$days]) ? $days : 30;
        $list = CustomResults::all($lang);
        View::render('custom_results', [
            'active' => 'custom_results', 'lang' => $lang, 'list' => $list, 'days' => $days, 'dayChoices' => self::DAYS,
            'stats' => CustomResults::stats($lang, array_column($list, 'id'), $days),
            'logging' => \DeepFind\Settings::int('log_searches') === 1,
        ], 'Résultats personnalisés');
    }

    public function edit(): void
    {
        $lang = Auth::lang();
        $id = (int) ($_GET['id'] ?? 0);
        $cr = $id > 0 ? CustomResults::find($id, $lang) : null;
        if ($id > 0 && $cr === null) {
            Auth::flash('error', 'Résultat personnalisé introuvable.');
            App::back('/admin/custom-results');
            return;
        }
        if ($cr === null) {
            $term = trim((string) ($_GET['term'] ?? ''));
            $cr = ['id' => 0, 'name' => $term !== '' ? CustomResults::pinName($term) : '', 'active' => true, 'starts_at' => null, 'ends_at' => null,
                'mode' => 'first', 'terms' => $term !== '' ? [['term' => mb_strtolower($term, 'UTF-8'), 'match' => 'exact']] : [],
                'include_items' => [], 'include_rules' => [], 'exclude_items' => [], 'exclude_rules' => []];
        }
        $fields = [];
        foreach (CustomResults::fields($lang) as $code => [$label, $type]) {
            $fields[] = ['code' => $code, 'label' => $label, 'type' => $type, 'static' => BoostRules::STATIC_VALUES[$code] ?? null];
        }
        View::render('custom_result_edit', [
            'active' => 'custom_results', 'cr' => $cr, 'fields' => $fields,
            'products' => $this->products($lang, [...$cr['include_items'], ...$cr['exclude_items']]),
            'stats' => $cr['id'] ? CustomResults::stats($lang, [$cr['id']], 30)[$cr['id']] : null,
        ], $cr['id'] ? 'Résultat personnalisé' : 'Nouveau résultat personnalisé');
    }

    public function save(): void
    {
        $lang = Auth::lang();
        $in = json_decode((string) ($_POST['payload'] ?? ''), true);
        [$cr, $errors] = is_array($in) ? CustomResults::sanitize($in, $lang) : [null, ['Formulaire illisible : rechargez la page.']];
        $id = is_array($in) ? (int) ($in['id'] ?? 0) : 0;
        if ($cr === null) {
            Auth::flash('error', implode(' ', $errors));
            App::back('/admin/custom-results/edit', $id ? ['id' => $id] : []);
            return;
        }
        $id = CustomResults::save($lang, $cr, $id > 0 ? $id : null);
        Auth::flash('ok', sprintf('Résultat personnalisé « %s » enregistré%s.', $cr['name'], $cr['active'] ? ' (effet immédiat)' : ', inactif'));
        App::back('/admin/custom-results/edit', ['id' => $id]);
    }

    public function toggle(): void
    {
        Db::exec('UPDATE custom_results SET active = 1 - active, updated_at = ? WHERE id = ? AND project_id = ? AND lang = ?',
            [date('Y-m-d H:i:s'), (int) ($_POST['id'] ?? 0), \DeepFind\Project::id(), Auth::lang()]);
        \DeepFind\CacheVersion::bump();
        App::backTo('/admin/custom-results');
    }

    public function delete(): void
    {
        CustomResults::delete((int) ($_POST['id'] ?? 0), Auth::lang());
        Auth::flash('ok', 'Résultat personnalisé supprimé.');
        App::back('/admin/custom-results');
    }

    /** Import CSV (requete;sku;action;position_ou_poids;debut;fin) : les lignes deviennent des résultats personnalisés. */
    public function import(): void
    {
        $f = $_FILES['csv'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 5 * 1024 * 1024) {
            Auth::flash('error', 'Fichier CSV manquant, invalide ou trop gros (5 Mo maximum).');
        } else {
            [$n, $errors] = RulesRepository::importRules($f['tmp_name'], Auth::lang(), isset($_POST['replace']));
            RulesRepository::bumpVersion();
            Auth::flash($errors ? 'warn' : 'ok', sprintf('%d ligne(s) importée(s) et converties en résultats personnalisés.', $n)
                . ($errors ? ' ' . implode(' ; ', array_slice($errors, 0, 5)) : ''));
        }
        App::back('/admin/custom-results');
    }

    /** Épingler depuis l'aperçu interne : position du produit pour cette recherche exacte. */
    public function pin(): void
    {
        $q = trim((string) ($_POST['query'] ?? ''));
        $sku = trim((string) ($_POST['sku'] ?? ''));
        if ($q === '' || $sku === '') {
            Auth::flash('error', 'Recherche et SKU obligatoires.');
        } else {
            $pos = max(1, (int) ($_POST['position'] ?? 1));
            CustomResults::pin(Auth::lang(), $q, $sku, $pos);
            Auth::flash('ok', sprintf('%s épinglé en position %d pour « %s » (résultat personnalisé « %s »).', $sku, $pos, $q, CustomResults::pinName($q)));
        }
        App::backTo('/admin/custom-results');
    }

    public function unpin(): void
    {
        CustomResults::unpin(Auth::lang(), (int) ($_POST['id'] ?? 0), (string) ($_POST['sku'] ?? ''));
        Auth::flash('ok', 'Produit retiré du résultat personnalisé.');
        App::backTo('/admin/custom-results');
    }

    /** Recherche de produits pour « Ajouter des éléments individuels » (JSON). */
    public function search(): void
    {
        $lang = Auth::lang();
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100, 'UTF-8');
        $out = [];
        try {
            $res = MeiliClient::search()->multiSearch([['indexUid' => Config::productIndex($lang), 'q' => $q, 'limit' => 40,
                'attributesToRetrieve' => ['sku', 'name', 'brand', 'image', 'final_price', 'in_stock']]]);
            foreach ($res[0]['hits'] ?? [] as $h) {
                $out[] = ['sku' => (string) $h['sku'], 'name' => (string) ($h['name'] ?? ''), 'brand' => (string) ($h['brand'] ?? ''),
                    'image' => (string) ($h['image'] ?? ''), 'price' => (float) ($h['final_price'] ?? 0), 'in_stock' => !empty($h['in_stock'])];
            }
        } catch (\Throwable $e) {
            Response::json(['items' => [], 'error' => 'Moteur indisponible.']);
            return;
        }
        Response::json(['items' => $out]);
    }

    /** @return array<string, array> SKU (majuscules) => fiche courte, pour afficher les produits choisis */
    private function products(string $lang, array $skus): array
    {
        $out = [];
        $skus = array_values(array_unique(array_map(static fn ($s) => mb_strtoupper((string) $s, 'UTF-8'), $skus)));
        if ($skus === []) {
            return $out;
        }
        try {
            foreach (array_chunk($skus, 100) as $chunk) {
                foreach (MeiliClient::admin()->fetchDocuments(Config::productIndex($lang), Filter::in('all_skus', $chunk), count($chunk) * 2) as $d) {
                    foreach ($d['all_skus'] ?? [$d['sku']] as $s) {
                        $out[mb_strtoupper((string) $s, 'UTF-8')] ??= ['sku' => (string) $d['sku'], 'name' => (string) ($d['name'] ?? ''),
                            'brand' => (string) ($d['brand'] ?? ''), 'image' => (string) ($d['image'] ?? ''), 'price' => (float) ($d['final_price'] ?? 0)];
                    }
                }
            }
        } catch (\Throwable) {
            // moteur indisponible : les SKU s'affichent sans nom
        }
        return $out;
    }
}
