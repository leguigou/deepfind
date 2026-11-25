<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Rules\Campaigns;

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
        View::render('campaign_edit', [
            'active' => 'campaigns', 'c' => $c, 'items' => $items, 'stats' => $c['id'] ? Campaigns::stats($c, $lang) : null,
            'status' => Campaigns::status($c),
        ], $c['id'] ? 'Campagne « ' . $c['name'] . ' »' : 'Nouvelle campagne');
    }

    public function save(): void
    {
        $lang = Auth::lang();
        $in = $_POST;
        $in['items'] = json_decode((string) ($_POST['items'] ?? '[]'), true) ?: [];
        [$c, $errors] = Campaigns::sanitize($in);
        $id = (int) ($_POST['id'] ?? 0);
        if ($c === null) {
            Auth::flash('error', implode(' ', $errors));
            App::back('/admin/campaigns/edit', $id ? ['id' => $id] : []);
            return;
        }
        $id = Campaigns::save($lang, $c, $id > 0 ? $id : null);
        Auth::flash('ok', sprintf('Campagne « %s » enregistrée.', $c['name']));
        App::back('/admin/campaigns/edit', ['id' => $id]);
    }

    public function delete(): void
    {
        Campaigns::delete((int) ($_POST['id'] ?? 0), Auth::lang());
        Auth::flash('ok', 'Campagne supprimée (les statistiques des produits restent dans l\'audience).');
        App::back('/admin/campaigns');
    }
}
