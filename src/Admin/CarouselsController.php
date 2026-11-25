<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Config;
use DeepFind\Http\Response;
use DeepFind\Meili\MeiliClient;
use DeepFind\Rules\BoostRules;
use DeepFind\Rules\Carousels;
use DeepFind\Rules\CustomResults;
use DeepFind\Rules\PageRules;
use DeepFind\Search\Filter;
use DeepFind\Settings;

/** Recommandations › Carrousels : liste (ordre, statistiques), fiche d'édition, aperçu des produits, réglage du consentement. */
final class CarouselsController
{
    private const DAYS = [7 => '7 jours', 30 => '30 jours', 90 => '90 jours'];

    public function index(): void
    {
        $lang = Auth::lang();
        $days = (int) ($_GET['days'] ?? 30);
        $days = isset(self::DAYS[$days]) ? $days : 30;
        $list = Carousels::all($lang);
        View::render('carousels', [
            'active' => 'carousels', 'lang' => $lang, 'list' => $list, 'days' => $days, 'dayChoices' => self::DAYS,
            'stats' => Carousels::stats(array_column($list, 'id'), $days), 'personal' => Carousels::personalMode(),
        ], 'Carrousels');
    }

    public function edit(): void
    {
        $lang = Auth::lang();
        $id = (int) ($_GET['id'] ?? 0);
        $c = $id > 0 ? Carousels::find($id, $lang) : null;
        if ($id > 0 && $c === null) {
            Auth::flash('error', 'Carrousel introuvable.');
            App::back('/admin/carousels');
            return;
        }
        $c ??= Carousels::blank();
        $fields = [];
        foreach (CustomResults::fields($lang) as $code => [$label, $type]) {
            $fields[] = ['code' => $code, 'label' => $label, 'type' => $type, 'static' => BoostRules::STATIC_VALUES[$code] ?? null];
        }
        $top = [];
        if ($c['id']) {
            $top = Carousels::topProducts($c['id'], 30);
            $names = $this->names($lang, array_column($top, 'product_id'), 'id');
            foreach ($top as &$t) {
                $t['name'] = $names[$t['product_id']]['name'] ?? '';
                $t['sku'] = $names[$t['product_id']]['sku'] ?? '';
            }
            unset($t);
        }
        // produits épinglés : fiche courte (introuvable dans le catalogue : signalé)
        $known = $this->names($lang, $c['items'], 'all_skus');
        $items = array_map(static fn ($s) => $known[mb_strtoupper((string) $s, 'UTF-8')] ?? ['sku' => (string) $s, 'name' => '', 'image' => '', 'missing' => true], $c['items']);
        View::render('carousel_edit', [
            'active' => 'carousels', 'c' => $c, 'fields' => $fields, 'urlOps' => PageRules::URL_OPS, 'items' => $items,
            'stats' => $c['id'] ? Carousels::stats([$c['id']], 30)[$c['id']] : null, 'top' => $top, 'personal' => Carousels::personalMode(),
            'searchTpl' => Carousels::searchTemplates(),
            'searchLink' => (string) ((\DeepFind\Widget\WidgetConfig::activeLayer()['settings']['card_link'] ?? '') ?: '{{url}}'),
        ], $c['id'] ? 'Carrousel « ' . $c['name'] . ' »' : 'Nouveau carrousel');
    }

    public function save(): void
    {
        $lang = Auth::lang();
        $in = json_decode((string) ($_POST['payload'] ?? ''), true);
        [$c, $errors] = is_array($in) ? Carousels::sanitize($in, $lang) : [null, ['Formulaire illisible : rechargez la page.']];
        $id = is_array($in) ? (int) ($in['id'] ?? 0) : 0;
        if ($c === null) {
            Auth::flash('error', implode(' ', $errors));
            App::back('/admin/carousels/edit', $id ? ['id' => $id] : []);
            return;
        }
        $id = Carousels::save($lang, $c, $id > 0 ? $id : null);
        $link = trim((string) ($in['card_link'] ?? ''));
        if ($link !== '' && $link !== '{{url}}' && $c['card_link'] === '') {
            Auth::flash('error', 'Lien de la carte refusé (celui de la recherche s\'applique) : il doit commencer par {{url}}, {{url_path}}, https:// ou /, '
                . 'sans espace ni guillemet, avec les seules variables {{url}}, {{url_path}}, {{sku}}, {{id}}.');
        }
        Auth::flash('ok', sprintf('Carrousel « %s » enregistré%s.', $c['name'], $c['active'] ? ' (visible sur le site d\'ici quelques minutes)' : ', inactif'));
        App::back('/admin/carousels/edit', ['id' => $id]);
    }

    public function toggle(): void
    {
        $lang = Auth::lang();
        $c = Carousels::find((int) ($_POST['id'] ?? 0), $lang);
        if ($c !== null) {
            Carousels::setActive($c['id'], $lang, !$c['active']);
        }
        App::backTo('/admin/carousels');
    }

    public function reorder(): void
    {
        Carousels::reorder(Auth::lang(), array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))));
        Auth::flash('ok', 'Ordre enregistré : deux carrousels au même emplacement s\'affichent dans l\'ordre de la liste.');
        App::back('/admin/carousels');
    }

    public function delete(): void
    {
        Carousels::delete((int) ($_POST['id'] ?? 0), Auth::lang());
        Auth::flash('ok', 'Carrousel supprimé.');
        App::back('/admin/carousels');
    }

    /** Réglage du projet : carrousels personnalisés (jamais / après consentement / toujours). */
    public function settings(): void
    {
        $mode = (string) ($_POST['carousel_personal'] ?? 'consent');
        Settings::set('carousel_personal', isset(Carousels::PERSONAL_MODES[$mode]) ? $mode : 'consent');
        Auth::flash('ok', 'Réglage des carrousels personnalisés enregistré.');
        App::back('/admin/carousels');
    }

    /**
     * Aperçu des produits du formulaire en cours (sans l'enregistrer), pour une page du site et, au besoin, un historique de test.
     * JSON : { items: [...], note }
     */
    public function preview(): void
    {
        $lang = Auth::lang();
        $in = json_decode((string) ($_POST['payload'] ?? ''), true);
        if (!is_array($in)) {
            Response::json(['items' => [], 'note' => 'Formulaire illisible.'], 422);
            return;
        }
        $in['name'] = ($in['name'] ?? '') !== '' ? $in['name'] : 'Aperçu';
        $in['selector'] = ($in['selector'] ?? '') !== '' ? $in['selector'] : '#apercu';
        [$c, $errors] = Carousels::sanitize($in, $lang);
        if ($c === null) {
            Response::json(['items' => [], 'note' => implode(' ', $errors)], 422);
            return;
        }
        $c['id'] = (int) ($in['id'] ?? 0);
        $page = (string) ($_POST['page'] ?? '/');
        $history = Carousels::cleanIds($this->ids($lang, array_filter(array_map('trim', explode(',', (string) ($_POST['history'] ?? ''))), 'strlen')));
        $pageProduct = Carousels::pageProduct($lang, $page, (string) ($_POST['sku'] ?? ''));
        $notes = [];
        if ($c['source'] === 'similar') {
            $notes[] = $pageProduct ? 'Produit de la page : ' . ($pageProduct['sku'] ?? $pageProduct['id']) . '.' : 'Aucun produit trouvé pour cette page : sélection automatique affichée.';
        }
        if ($c['source'] === 'personal' && $history === []) {
            $notes[] = 'Sans historique de test : sélection automatique affichée (comme pour un visiteur sans historique ou sans consentement).';
        }
        if (!Carousels::pageMatches($c, $page)) {
            $notes[] = 'Attention : ce carrousel ne s\'affiche pas sur cette page (pages concernées).';
        }
        $c = Carousels::forPath($c, $page);
        // panier de test : « achetés ensemble », « aussi regardé », page panier (livraison offerte)
        $cart = trim((string) ($_POST['cart'] ?? '')) !== '' || trim((string) ($_POST['cart_total'] ?? '')) !== ''
            ? Carousels::cartContext($lang, ['ci' => str_replace(' ', '', (string) ($_POST['cart'] ?? '')), 'ct' => (string) ($_POST['cart_total'] ?? '')]) : null;
        if (in_array($c['source'], Carousels::CART_SOURCES, true) && $cart === null && $c['source'] === 'cart') {
            $notes[] = 'Page panier : indiquez un panier de test (SKU) pour voir les compléments ; sans panier, sélection automatique.';
        }
        if ($c['source'] === 'trending' && \DeepFind\Rules\Recos::trending(1) === []) {
            $notes[] = 'Pas encore assez d\'activité mesurée pour les tendances : sélection automatique affichée.';
        }
        if ($c['page_rule'] !== null) {
            $pf = $c['page_filters'][(int) $c['page_key']]['url'];
            $notes[] = 'Adaptation selon la page appliquée (adresse ' . (\DeepFind\Rules\PageRules::URL_OPS[$pf['match']] ?? $pf['match']) . ' « ' . $pf['text'] . ' »).';
        }
        $products = Carousels::products($lang, $c, $pageProduct, $history === [] ? null : $history, false, $cart);
        $items = array_map(static fn ($p) => ['sku' => $p['sku'] ?? '', 'name' => $p['name'] ?? '', 'image' => $p['image'] ?? '', 'brand' => $p['brand'] ?? '',
            'price' => $p['final_price'] ?? null, 'in_stock' => !empty($p['in_stock'])], $products);
        // carrousel : prêt pour le widget (aperçu direct ordinateur / mobile)
        $c['id'] = $c['id'] ?: 1;
        $extra = [];
        if ($c['source'] === 'cart' && $cart !== null && $cart['threshold'] > 0 && $cart['total'] !== null) {
            $extra['free_shipping'] = ['threshold' => $cart['threshold'], 'total' => $cart['total'], 'left' => Carousels::freeShippingLeft($cart)];
            $notes[] = $extra['free_shipping']['left'] > 0 ? 'Livraison offerte : il manque ' . number_format($extra['free_shipping']['left'] / 100, 2, ',', ' ') . ' € ; les produits qui suffisent à l\'atteindre passent en tête.'
                : 'Livraison offerte atteinte.';
        } elseif ($c['source'] === 'cart' && ($cart['threshold'] ?? 0) === 0) {
            $notes[] = 'Aucun seuil de livraison offerte (Paramètres › Widget sur le site, ou donné par le site).';
        }
        Response::json(['items' => $items, 'note' => implode(' ', $notes), 'carousel' => Carousels::payload($c) + $extra + ['products' => $products]]);
    }

    /** SKU saisis pour l'historique de test → identifiants des produits. @return list<string> */
    private function ids(string $lang, array $skus): array
    {
        return array_values(array_map(static fn ($p) => (string) $p['id'], $this->names($lang, array_slice($skus, 0, 20), 'all_skus')));
    }

    /**
     * Fiches courtes (id, sku, nom, image) des produits, indexées par la valeur cherchée (SKU en majuscules, ou identifiant).
     * @return array<string, array>
     */
    private function names(string $lang, array $values, string $field): array
    {
        $values = array_values(array_unique(array_map(static fn ($v) => $field === 'all_skus' ? mb_strtoupper((string) $v, 'UTF-8') : (string) $v, $values)));
        if ($values === []) {
            return [];
        }
        $out = [];
        try {
            foreach (MeiliClient::admin()->fetchDocuments(Config::productIndex($lang), Filter::in($field, $values), count($values) * 2, 0,
                ['id', 'sku', 'all_skus', 'name', 'image', 'brand', 'final_price', 'in_stock']) as $d) {
                $card = ['id' => (string) $d['id'], 'sku' => (string) ($d['sku'] ?? ''), 'name' => (string) ($d['name'] ?? ''), 'image' => (string) ($d['image'] ?? ''),
                    'brand' => (string) ($d['brand'] ?? ''), 'price' => $d['final_price'] ?? null, 'in_stock' => !empty($d['in_stock'])];
                foreach ($field === 'id' ? [(string) $d['id']] : ($d['all_skus'] ?? [$d['sku'] ?? '']) as $k) {
                    $k = $field === 'id' ? $k : mb_strtoupper((string) $k, 'UTF-8');
                    if (in_array($k, $values, true)) {
                        $out[$k] = $card;
                    }
                }
            }
        } catch (\Throwable) {
            // moteur indisponible : fiches sans nom
        }
        return $out;
    }
}
