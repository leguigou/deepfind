<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Rules\BoostRules;
use DeepFind\Rules\Campaigns;
use DeepFind\Rules\CustomResults;

/** Recommandations › Campagnes : produits à pousser et suivi de leurs résultats. */
final class CampaignsController
{
    public function index(): void
    {
        $lang = Auth::lang();
        $list = [];
        foreach (Campaigns::all($lang) as $c) {
            $list[] = $c + ['status' => Campaigns::status($c), 'stats' => Campaigns::stats($c, $lang)];
        }
        View::render('campaigns', ['active' => 'campaigns', 'list' => $list], 'Campagnes');
    }

    public function edit(): void
    {
        $lang = Auth::lang();
        $id = (int) ($_GET['id'] ?? 0);
        $c = $id > 0 ? Campaigns::find($id, $lang) : null;
        if ($id > 0 && $c === null) {
            Auth::flash('error', 'Campagne introuvable.');
            App::back('/admin/campaigns');
            return;
        }
        $c ??= Campaigns::blank();
        // produits déjà choisis : nom et image pour la liste (le SKU seul s'ils ne sont plus au catalogue)
        $docs = Campaigns::products($lang, $c['items']);
        $items = array_map(static function ($sku) use ($docs) {
            $d = $docs[mb_strtoupper($sku, 'UTF-8')] ?? null;
            return ['sku' => $sku, 'name' => (string) ($d['name'] ?? ''), 'image' => (string) ($d['image'] ?? ''), 'brand' => (string) ($d['brand'] ?? ''),
                'price' => $d['final_price'] ?? null, 'in_stock' => !empty($d['in_stock']), 'missing' => $d === null];
        }, $c['items']);
        // champs des règles : les mêmes que pour les boosts et les carrousels
        $fields = [];
        foreach (CustomResults::fields($lang) as $code => [$label, $type]) {
            $fields[] = ['code' => $code, 'label' => $label, 'type' => $type, 'static' => BoostRules::STATIC_VALUES[$code] ?? null];
        }
        $rule = $c['conditions'] !== [] ? Campaigns::ruleProducts($lang, $c) : null;
        View::render('campaign_edit', ['active' => 'campaigns', 'c' => $c, 'items' => $items, 'fields' => $fields, 'rule' => $rule],
            $c['id'] ? 'Modifier la campagne « ' . $c['name'] . ' »' : 'Nouvelle campagne');
    }

    /** Résultats d'une campagne (tableau de bord seul ; « Modifier la campagne » ouvre le formulaire). */
    public function results(): void
    {
        $lang = Auth::lang();
        $c = Campaigns::find((int) ($_GET['id'] ?? 0), $lang);
        if ($c === null) {
            Auth::flash('error', 'Campagne introuvable.');
            App::back('/admin/campaigns');
            return;
        }
        View::render('campaign_results', ['active' => 'campaigns', 'c' => $c, 'stats' => Campaigns::stats($c, $lang), 'status' => Campaigns::status($c)],
            'Campagne « ' . $c['name'] . ' »');
    }

    public function save(): void
    {
        $lang = Auth::lang();
        $in = $_POST;
        $in['items'] = json_decode((string) ($_POST['items'] ?? '[]'), true) ?: [];
        $in['conditions'] = json_decode((string) ($_POST['conditions'] ?? '[]'), true) ?: [];
        [$c, $errors] = Campaigns::sanitize($in, $lang);
        $id = (int) ($_POST['id'] ?? 0);
        if ($c === null) {
            Auth::flash('error', implode(' ', $errors));
            App::back('/admin/campaigns/edit', $id ? ['id' => $id] : []);
            return;
        }
        $id = Campaigns::save($lang, $c, $id > 0 ? $id : null);
        $rule = Campaigns::ruleProducts($lang, $c);
        Auth::flash('ok', sprintf('Campagne « %s » enregistrée.', $c['name']) . ($c['conditions'] === [] ? '' : ($rule['error'] ?? sprintf(' Règles : %s produit(s) du catalogue correspondent aujourd\'hui%s.',
            number_format($rule['total'], 0, ',', ' '), $rule['total'] > Campaigns::RULE_LIMIT ? ' (suivi limité aux ' . Campaigns::RULE_LIMIT . ' premiers : précisez les règles)' : ''))));
        App::back('/admin/campaigns/results', ['id' => $id]);
    }

    public function delete(): void
    {
        Campaigns::delete((int) ($_POST['id'] ?? 0), Auth::lang());
        Auth::flash('ok', 'Campagne supprimée (les statistiques des produits restent dans l\'audience).');
        App::back('/admin/campaigns');
    }
}
