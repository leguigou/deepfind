<?php

declare(strict_types=1);

namespace DeepFind\Rules;

use DeepFind\Db;
use DeepFind\Search\Filter;
use DeepFind\Search\SearchService;
use DeepFind\Settings;
use DeepFind\Store\Store;
use DeepFind\Text;

/**
 * Carrousels de produits (Recommandations › Carrousels) : bandeau de produits que le widget insère dans une page du site.
 *   - pages : comme les popins (toutes, accueil, adresses ; adresses exclues), appareil, période ;
 *   - emplacement : sélecteur CSS (#id, .classe…) + position (dans le bloc au début / à la fin, avant, après, à la place) ;
 *   - produits : épinglés en tête (ordre imposé), puis selon la source :
 *       manual   : seulement les produits épinglés ;
 *       auto     : conditions (comme les règles selon la page) + tri ;
 *       similar  : même catégorie et/ou marque que le produit de la page (retrouvé par son adresse ou son SKU) ;
 *       personal : vus récemment / « vous aimerez aussi », d'après l'historique gardé dans le navigateur du visiteur
 *                  (envoyé sans identifiant, jamais enregistré ; seulement si le visiteur a donné son consentement) ;
 *     les conditions limitent aussi les sources similar / personal ; sans contexte (pas de produit dans la page, pas
 *     d'historique), la source « auto » prend le relais ;
 *   - apprentissage : produits reclassés selon leur taux de clic dans ce carrousel (CTR lissé, 30 jours).
 * Suivi : carousel_stats (vu à l'écran, clics) et carousel_product_stats (par produit), agrégés par jour.
 */
final class Carousels
{
    public const SOURCES = [
        'auto' => 'Automatique : produits choisis par conditions et triés',
        'manual' => 'Sélection manuelle : uniquement les produits choisis, dans l\'ordre',
        'similar' => 'Produits similaires au produit de la page (fiche produit)',
        'personal' => 'Personnalisé selon le visiteur (avec son consentement)',
    ];
    public const SORTS = [
        'biz' => 'Score métier (ventes, clics, stock, marge, nouveauté)',
        'sales' => 'Meilleures ventes (30 jours)',
        'clicks' => 'Les plus cliqués dans la recherche (30 jours)',
        'new' => 'Nouveautés',
        'discount' => 'Plus grosses remises',
        'rating' => 'Mieux notés',
        'price_asc' => 'Prix croissant',
        'price_desc' => 'Prix décroissant',
    ];
    private const SORT_RULES = [
        'biz' => ['biz_score:desc'], 'sales' => ['sales_30d:desc', 'biz_score:desc'], 'clicks' => ['clicks_30d:desc', 'biz_score:desc'],
        'new' => ['created_ts:desc', 'biz_score:desc'], 'discount' => ['discount_percent:desc', 'biz_score:desc'],
        'rating' => ['rating:desc', 'reviews_count:desc'], 'price_asc' => ['final_price:asc'], 'price_desc' => ['final_price:desc'],
    ];
    public const SIMILAR = ['category' => 'Même catégorie', 'brand' => 'Même marque', 'category_brand' => 'Même catégorie et même marque'];
    public const PERSONAL = [
        'recommended' => '« Vous aimerez aussi » : produits des catégories et marques que le visiteur a consultées',
        'recent' => '« Vus récemment » : les derniers produits consultés ou cliqués par le visiteur',
    ];
    public const INSERTS = [
        'append' => 'Dans le bloc, à la fin', 'prepend' => 'Dans le bloc, au début', 'before' => 'Juste avant le bloc',
        'after' => 'Juste après le bloc', 'replace' => 'À la place du contenu du bloc',
    ];
    public const PERSONAL_MODES = [
        'consent' => 'Seulement si le visiteur a donné son consentement (transmis par le bandeau cookies du site)',
        'always' => 'Toujours (seulement si votre analyse RGPD le permet)',
        'off' => 'Jamais : les carrousels personnalisés affichent leur sélection automatique',
    ];
    /** Carrousels par page au plus ; produits d'historique pris en compte au plus. */
    public const MAX_PER_PAGE = 10;
    public const MAX_HISTORY = 20;

    // ------------------------------------------------------------------ Lecture / écriture

    /** @return list<array> */
    public static function all(string $lang): array
    {
        return array_map([self::class, 'decode'], Db::all('SELECT * FROM carousels WHERE project_id = ? AND lang = ? ORDER BY position, id',
            [\DeepFind\Project::id(), $lang]));
    }

    public static function find(int $id, string $lang): ?array
    {
        $row = Db::one('SELECT * FROM carousels WHERE id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]);
        return $row ? self::decode($row) : null;
    }

    public static function blank(): array
    {
        return [
            'id' => 0, 'name' => '', 'active' => true, 'position' => 0, 'starts_at' => null, 'ends_at' => null,
            'pages' => 'all', 'home' => false, 'urls' => [], 'exclude_urls' => [], 'device' => 'all',
            'selector' => '', 'insert_mode' => 'append', 'title' => '', 'more_url' => '', 'more_label' => '',
            'source' => 'auto', 'items' => [], 'match_all' => true, 'conditions' => [], 'sort_by' => 'biz',
            'similar_by' => 'category', 'personal_mode' => 'recommended', 'learning' => false, 'in_stock_only' => true,
            'max_items' => 12, 'per_view_desktop' => 4, 'per_view_mobile' => 2, 'autoplay' => 0, 'custom_css' => '',
            'card_template' => '', 'card_template_mobile' => '',
        ];
    }

    private static function decode(array $r): array
    {
        $pick = static fn (array $choices, mixed $v, string $def) => isset($choices[(string) $v]) ? (string) $v : $def;
        $j = static fn ($v) => json_decode((string) $v, true) ?: [];
        return [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'active' => (int) $r['active'] === 1, 'position' => (int) $r['position'],
            'starts_at' => $r['starts_at'] ?: null, 'ends_at' => $r['ends_at'] ?: null,
            'pages' => $r['pages'] === 'custom' ? 'custom' : 'all', 'home' => (int) $r['home'] === 1,
            'urls' => $j($r['urls']), 'exclude_urls' => $j($r['exclude_urls']), 'device' => $pick(Popins::DEVICES, $r['device'], 'all'),
            'selector' => (string) $r['selector'], 'insert_mode' => $pick(self::INSERTS, $r['insert_mode'], 'append'),
            'title' => (string) $r['title'], 'more_url' => (string) $r['more_url'], 'more_label' => (string) $r['more_label'],
            'source' => $pick(self::SOURCES, $r['source'], 'auto'), 'items' => $j($r['items']),
            'match_all' => (int) $r['match_all'] === 1, 'conditions' => $j($r['conditions']), 'sort_by' => $pick(self::SORTS, $r['sort_by'], 'biz'),
            'similar_by' => $pick(self::SIMILAR, $r['similar_by'], 'category'), 'personal_mode' => $pick(self::PERSONAL, $r['personal_mode'], 'recommended'),
            'learning' => (int) $r['learning'] === 1, 'in_stock_only' => (int) $r['in_stock_only'] === 1,
            'max_items' => (int) $r['max_items'], 'per_view_desktop' => (int) $r['per_view_desktop'], 'per_view_mobile' => (int) $r['per_view_mobile'],
            'autoplay' => (int) $r['autoplay'], 'custom_css' => (string) $r['custom_css'],
            'card_template' => (string) ($r['card_template'] ?? ''), 'card_template_mobile' => (string) ($r['card_template_mobile'] ?? ''),
        ];
    }

    /**
     * Valide un carrousel envoyé par le formulaire.
     * @return array{0:?array, 1:list<string>}
     */
    public static function sanitize(array $in, string $lang): array
    {
        $errors = [];
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 190, 'UTF-8');
        if ($name === '') {
            $errors[] = 'Le nom est obligatoire.';
        }
        $start = $end = null;
        if (($in['duration'] ?? 'always') === 'period') {
            $start = self::date($in['starts_at'] ?? '');
            $end = self::date($in['ends_at'] ?? '');
            if ($start === null || $end === null) {
                $errors[] = 'Choisissez la date de début et la date de fin de la période.';
            } elseif ($end < $start) {
                $errors[] = 'La date de fin est antérieure à la date de début.';
            }
        }
        $pages = ($in['pages'] ?? 'all') === 'custom' ? 'custom' : 'all';
        $home = !empty($in['home']);
        $urls = PageRules::sanitizeUrls((array) ($in['urls'] ?? []));
        if ($pages === 'custom' && !$home && $urls === []) {
            $errors[] = 'Choisissez les pages : la page d\'accueil et/ou au moins une adresse (ex. contient « /nautisme/ »).';
        }
        $selector = self::selector((string) ($in['selector'] ?? ''));
        if ($selector === '') {
            $errors[] = 'Indiquez l\'emplacement : l\'identifiant (#…) ou la classe (.…) du bloc de la page où insérer le carrousel.';
        } elseif ($selector === null) {
            $errors[] = 'Emplacement : sélecteur CSS invalide (ex. #accueil-produits, .category-view, main .columns).';
        }
        $moreUrl = mb_substr(trim((string) ($in['more_url'] ?? '')), 0, 500, 'UTF-8');
        if ($moreUrl !== '' && !\DeepFind\Security\HtmlSanitizer::safeUrl($moreUrl)) {
            $errors[] = 'Lien « Voir tout » : adresse complète (https://…) ou chemin du site (/…) attendu.';
        }
        $source = isset(self::SOURCES[$in['source'] ?? '']) ? (string) $in['source'] : 'auto';
        $items = [];
        foreach (array_slice((array) ($in['items'] ?? []), 0, 48) as $sku) {
            $sku = mb_substr(trim((string) $sku), 0, 64, 'UTF-8');
            if ($sku !== '' && !in_array($sku, $items, true)) {
                $items[] = $sku;
            }
        }
        if ($source === 'manual' && $items === []) {
            $errors[] = 'Sélection manuelle : ajoutez au moins un produit.';
        }
        [$rules, $condErrors] = CustomResults::sanitizeRules([['match' => ($in['match'] ?? 'all') === 'any' ? 'any' : 'all',
            'conditions' => $source === 'manual' ? [] : (array) ($in['conditions'] ?? [])]], $lang, true);
        $errors = [...$errors, ...$condErrors];
        if ($errors !== []) {
            return [null, $errors];
        }
        $int = static fn (string $k, int $min, int $max, int $def) => isset($in[$k]) && is_numeric($in[$k]) ? max($min, min($max, (int) $in[$k])) : $def;
        $pick = static fn (array $choices, string $k, string $def) => isset($choices[(string) ($in[$k] ?? '')]) ? (string) $in[$k] : $def;
        return [[
            'name' => $name, 'active' => !empty($in['active']), 'starts_at' => $start, 'ends_at' => $end,
            'pages' => $pages, 'home' => $pages === 'custom' && $home, 'urls' => $pages === 'custom' ? $urls : [],
            'exclude_urls' => PageRules::sanitizeUrls((array) ($in['exclude_urls'] ?? [])), 'device' => $pick(Popins::DEVICES, 'device', 'all'),
            'selector' => $selector, 'insert_mode' => $pick(self::INSERTS, 'insert_mode', 'append'),
            'title' => mb_substr(trim((string) ($in['title'] ?? '')), 0, 190, 'UTF-8'), 'more_url' => $moreUrl,
            'more_label' => mb_substr(trim((string) ($in['more_label'] ?? '')), 0, 60, 'UTF-8'),
            'source' => $source, 'items' => $items, 'match_all' => $rules[0]['match_all'] ?? true, 'conditions' => $rules[0]['conditions'] ?? [],
            'sort_by' => $pick(self::SORTS, 'sort_by', 'biz'), 'similar_by' => $pick(self::SIMILAR, 'similar_by', 'category'),
            'personal_mode' => $pick(self::PERSONAL, 'personal_mode', 'recommended'), 'learning' => !empty($in['learning']) && $source !== 'manual',
            'in_stock_only' => !empty($in['in_stock_only']), 'max_items' => $int('max_items', 2, 48, 12),
            'per_view_desktop' => $int('per_view_desktop', 1, 8, 4), 'per_view_mobile' => $int('per_view_mobile', 1, 4, 2),
            'autoplay' => $int('autoplay', 0, 60, 0), 'custom_css' => self::css((string) ($in['custom_css'] ?? '')),
        ] + self::cardTemplates($in), []];
    }

    /**
     * Modèles de carte produit du carrousel (ordinateur, mobile), nettoyés comme celui de la recherche (WidgetConfig::cleanCardTemplate).
     * Identique au modèle de la recherche (layer actif, sinon modèle d'origine) : '' (le carrousel suit alors ce modèle).
     * Mobile identique à l'ordinateur : ''.
     * @return array{card_template:string, card_template_mobile:string}
     */
    public static function cardTemplates(array $in): array
    {
        [$baseDesk, $baseMob] = self::searchTemplates();
        $desk = \DeepFind\Widget\WidgetConfig::cleanCardTemplate((string) ($in['card_template'] ?? ''));
        $mob = \DeepFind\Widget\WidgetConfig::cleanCardTemplate((string) ($in['card_template_mobile'] ?? ''));
        $same = [\DeepFind\Widget\WidgetConfig::class, 'sameTemplate'];
        $desk = $desk === '' || $same($desk, $baseDesk) ? '' : $desk;
        $mobRef = $desk !== '' ? $desk : $baseMob;
        $mob = $mob === '' || $same($mob, $mobRef) ? '' : $mob;
        return ['card_template' => $desk, 'card_template_mobile' => $mob];
    }

    /** Modèles de carte de la fenêtre de recherche (layer actif), modèle d'origine à défaut. @return array{0:string, 1:string} */
    public static function searchTemplates(): array
    {
        $s = \DeepFind\Widget\WidgetConfig::activeLayer()['settings'] ?? [];
        $desk = (string) ($s['card_template'] ?? '') !== '' ? (string) $s['card_template'] : \DeepFind\Widget\WidgetConfig::DEFAULT_CARD_TEMPLATE;
        $mob = (string) ($s['card_template_mobile'] ?? '') !== '' ? (string) $s['card_template_mobile'] : $desk;
        return [$desk, $mob];
    }

    /** Sélecteur CSS sûr (pas d'accolade, de « < », de point-virgule ni de guillemet non fermé ; « > » autorisé) ; '' si vide, null si refusé. */
    public static function selector(string $s): ?string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        if ($s === '') {
            return '';
        }
        if (mb_strlen($s, 'UTF-8') > 300 || preg_match('/[{}<;\\\\]|\/\*/', $s) || substr_count($s, '"') % 2 || substr_count($s, "'") % 2) {
            return null;
        }
        return $s;
    }

    /** CSS personnalisé : texte brut, sans balise ni import / url(javascript…) / expression ; limité à 20 000 caractères. */
    public static function css(string $css): string
    {
        $css = str_replace(["\r\n", "\r"], "\n", $css);
        $css = (string) preg_replace('~</?\s*style[^>]*>~i', '', $css);
        $css = (string) preg_replace('~@import[^;]*;?|expression\s*\(|javascript:|behavior\s*:|-moz-binding~i', '', $css);
        $css = str_replace(['<', '>'], '', $css);
        return mb_substr(trim($css), 0, 20000, 'UTF-8');
    }

    private static function date(mixed $v): ?string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
    }

    /** @return int identifiant */
    public static function save(string $lang, array $c, ?int $id): int
    {
        $row = [
            'name' => $c['name'], 'active' => $c['active'] ? 1 : 0, 'starts_at' => $c['starts_at'], 'ends_at' => $c['ends_at'],
            'pages' => $c['pages'], 'home' => $c['home'] ? 1 : 0, 'urls' => Text::json($c['urls']), 'exclude_urls' => Text::json($c['exclude_urls']),
            'device' => $c['device'], 'selector' => $c['selector'], 'insert_mode' => $c['insert_mode'], 'title' => $c['title'],
            'more_url' => $c['more_url'], 'more_label' => $c['more_label'], 'source' => $c['source'], 'items' => Text::json($c['items']),
            'match_all' => $c['match_all'] ? 1 : 0, 'conditions' => Text::json($c['conditions']), 'sort_by' => $c['sort_by'],
            'similar_by' => $c['similar_by'], 'personal_mode' => $c['personal_mode'], 'learning' => $c['learning'] ? 1 : 0,
            'in_stock_only' => $c['in_stock_only'] ? 1 : 0, 'max_items' => $c['max_items'], 'per_view_desktop' => $c['per_view_desktop'],
            'per_view_mobile' => $c['per_view_mobile'], 'autoplay' => $c['autoplay'], 'custom_css' => $c['custom_css'],
            'card_template' => $c['card_template'] !== '' ? $c['card_template'] : null, 'card_template_mobile' => $c['card_template_mobile'] !== '' ? $c['card_template_mobile'] : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $pid = \DeepFind\Project::id();
        if ($id !== null && self::find($id, $lang) !== null) {
            Db::update('carousels', $row, 'id = ? AND project_id = ? AND lang = ?', [$id, $pid, $lang]);
        } else {
            $pos = (int) Db::value('SELECT COALESCE(MAX(position), 0) + 1 FROM carousels WHERE project_id = ? AND lang = ?', [$pid, $lang]);
            $id = Db::insert('carousels', $row + ['project_id' => $pid, 'lang' => $lang, 'position' => $pos, 'created_at' => $row['updated_at']]);
        }
        \DeepFind\CacheVersion::bump();
        return $id;
    }

    public static function setActive(int $id, string $lang, bool $on): void
    {
        Db::exec('UPDATE carousels SET active = ?, updated_at = ? WHERE id = ? AND project_id = ? AND lang = ?',
            [$on ? 1 : 0, date('Y-m-d H:i:s'), $id, \DeepFind\Project::id(), $lang]);
        \DeepFind\CacheVersion::bump();
    }

    /** Ordre d'insertion sur la page (deux carrousels au même emplacement : dans l'ordre de la liste). */
    public static function reorder(string $lang, array $ids): void
    {
        $known = array_column(self::all($lang), 'id');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => in_array($i, $known, true))));
        foreach ([...$ids, ...array_values(array_diff($known, $ids))] as $pos => $id) {
            Db::exec('UPDATE carousels SET position = ? WHERE id = ? AND project_id = ?', [$pos + 1, $id, \DeepFind\Project::id()]);
        }
        \DeepFind\CacheVersion::bump();
    }

    public static function delete(int $id, string $lang): void
    {
        $pid = \DeepFind\Project::id();
        if (Db::exec('DELETE FROM carousels WHERE id = ? AND project_id = ? AND lang = ?', [$id, $pid, $lang]) > 0) {
            Db::exec('DELETE FROM carousel_stats WHERE carousel_id = ? AND project_id = ?', [$id, $pid]);
            Db::exec('DELETE FROM carousel_product_stats WHERE carousel_id = ? AND project_id = ?', [$id, $pid]);
        }
        \DeepFind\CacheVersion::bump();
    }

    // ------------------------------------------------------------------ Pages

    /** Carrousels actifs aujourd'hui (cache invalidé par CacheVersion, date du jour dans la clé). @return list<array> */
    public static function active(string $lang): array
    {
        $today = date('Y-m-d');
        $store = Store::get();
        $key = 'carousels:' . \DeepFind\Project::id() . ':' . $lang . ':' . $today . ':' . \DeepFind\CacheVersion::get();
        $list = $store->get($key);
        if (!is_array($list)) {
            $list = array_values(array_filter(self::all($lang), static fn ($c) => $c['active'] && CustomResults::period($c, $today) === 'current'));
            $store->set($key, $list, 300);
        }
        return $list;
    }

    /** Accueil : « / » ou la racine d'une langue / d'une boutique Magento (« /fr », « /fr_fr ») — comme le widget. */
    public static function isHome(string $path): bool
    {
        $path = rtrim((string) preg_replace('~/index\.php$~i', '', $path), '/');
        return $path === '' || (bool) preg_match('~^/[a-z]{2}([_-][a-z]{2})?$~', $path);
    }

    public static function pageMatches(array $c, string $path): bool
    {
        $path = PageRules::normalizePath($path);
        foreach ($c['exclude_urls'] as $u) {
            if (PageRules::urlMatches($u, $path)) {
                return false;
            }
        }
        if ($c['pages'] !== 'custom') {
            return true;
        }
        if ($c['home'] && self::isHome($path)) {
            return true;
        }
        foreach ($c['urls'] as $u) {
            if (PageRules::urlMatches($u, $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Carrousels de la page, avec leurs produits, prêts pour le widget.
     * $ctx : page (chemin), sku (produit de la page indiqué par le site, facultatif), history (identifiants de produits, du plus récent
     * au plus ancien, ou null sans consentement).
     * @return array{carousels: list<array>, page_product: ?string}
     */
    public static function forPage(string $lang, array $ctx): array
    {
        $page = PageRules::normalizePath((string) ($ctx['page'] ?? '/'));
        $matched = array_slice(array_values(array_filter(self::active($lang), static fn ($c) => self::pageMatches($c, $page))), 0, self::MAX_PER_PAGE);
        $history = self::personalAllowed() && is_array($ctx['history'] ?? null) ? self::cleanIds($ctx['history']) : null;
        // produit de la page : pour les « similaires », et pour l'historique du visiteur (s'il a consenti)
        $needProduct = $history !== null || array_filter($matched, static fn ($c) => $c['source'] === 'similar') !== [];
        $pageProduct = $needProduct ? self::pageProduct($lang, $page, (string) ($ctx['sku'] ?? '')) : null;
        $out = [];
        foreach ($matched as $c) {
            $products = self::products($lang, $c, $pageProduct, $history);
            if ($products === []) {
                continue;
            }
            $out[] = self::payload($c) + ['products' => $products];
        }
        return ['carousels' => $out, 'page_product' => $pageProduct['id'] ?? null];
    }

    public static function payload(array $c): array
    {
        return [
            'id' => $c['id'], 'title' => $c['title'], 'more_url' => $c['more_url'], 'more_label' => $c['more_label'],
            'selector' => $c['selector'], 'insert' => $c['insert_mode'], 'device' => $c['device'],
            'per_view_desktop' => $c['per_view_desktop'], 'per_view_mobile' => $c['per_view_mobile'], 'autoplay' => $c['autoplay'], 'css' => $c['custom_css'],
            // modèles de carte propres au carrousel ('' : ceux de la fenêtre de recherche)
            'card_template' => $c['card_template'] ?? '', 'card_template_mobile' => $c['card_template_mobile'] ?? '',
        ];
    }

    public static function personalMode(): string
    {
        $m = Settings::get('carousel_personal');
        return isset(self::PERSONAL_MODES[$m]) ? $m : 'consent';
    }

    public static function personalAllowed(): bool
    {
        return self::personalMode() !== 'off';
    }

    /** @return list<string> identifiants de produits plausibles, sans doublon, $max au plus (historique : 20) */
    public static function cleanIds(array $ids, int $max = self::MAX_HISTORY): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if (preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $id) && !in_array($id, $out, true)) {
                $out[] = $id;
            }
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ Produits

    private const DOC_FIELDS = ['id', 'sku', 'all_skus', 'brand', 'category_names', 'category_paths'];

    /** Produit de la page : par le SKU indiqué par le site, sinon par l'adresse de la page (champ url_path). */
    public static function pageProduct(string $lang, string $path, string $sku = ''): ?array
    {
        $sku = mb_strtoupper(mb_substr(trim($sku), 0, 64, 'UTF-8'), 'UTF-8');
        $path = PageRules::normalizePath($path);
        if ($sku === '' && ($path === '/' || self::isHome($path))) {
            return null;
        }
        $store = Store::get();
        $key = 'pageprod:' . \DeepFind\Project::id() . ':' . $lang . ':' . \DeepFind\CacheVersion::get() . ':' . md5($sku . '|' . $path);
        $hit = $store->get($key);
        if (is_array($hit)) {
            return $hit['p'];
        }
        $filters = $sku !== '' ? [Filter::eq('all_skus', $sku)] : [[Filter::eq('url_path', $path), Filter::eq('url_path', rtrim($path, '/'))]];
        $res = self::query($lang, ['filter' => $filters, 'limit' => 1, 'attributesToRetrieve' => self::DOC_FIELDS]);
        $p = $res[0] ?? null;
        $store->set($key, ['p' => $p], 3600);
        return $p;
    }

    /**
     * Produits d'un carrousel (liste publique, dans l'ordre d'affichage).
     * @param ?array $pageProduct produit de la page (champs DOC_FIELDS) ; @param ?list<string> $history historique du visiteur
     * @return list<array>
     */
    public static function products(string $lang, array $c, ?array $pageProduct = null, ?array $history = null, bool $useCache = true): array
    {
        if (!$useCache) {
            return self::compute($lang, $c, $pageProduct, $history); // aperçu de l'extranet (formulaire non enregistré)
        }
        $source = $c['source'];
        $ctxKey = '';
        if ($source === 'similar') {
            $ctxKey = (string) ($pageProduct['id'] ?? '');
        } elseif ($source === 'personal') {
            $ctxKey = $history ? md5(implode(',', $history) . '|' . ($pageProduct['id'] ?? '')) : '';
        }
        $store = Store::get();
        $key = 'carprod:' . \DeepFind\Project::id() . ':' . $lang . ':' . $c['id'] . ':' . \DeepFind\CacheVersion::get() . ':' . $ctxKey;
        $cached = $store->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $list = self::compute($lang, $c, $pageProduct, $history);
        $store->set($key, $list, $source === 'personal' ? 60 : 300);
        return $list;
    }

    /** @return list<array> */
    private static function compute(string $lang, array $c, ?array $pageProduct, ?array $history): array
    {
        $max = max(1, $c['max_items']);
        $base = self::baseFilters($lang, $c);
        $exclude = $pageProduct ? [(string) $pageProduct['id']] : [];
        // 1. produits épinglés, dans l'ordre choisi
        $pinned = [];
        if ($c['items'] !== []) {
            $skus = array_map(static fn ($s) => mb_strtoupper((string) $s, 'UTF-8'), $c['items']);
            $hits = self::query($lang, ['filter' => [...$base, Filter::in('all_skus', $skus)], 'limit' => count($skus),
                'attributesToRetrieve' => [...SearchService::PUBLIC_FIELDS, 'all_skus']]);
            $rank = array_flip($skus);
            usort($hits, static function ($a, $b) use ($rank) {
                $ra = min(array_map(static fn ($s) => $rank[mb_strtoupper((string) $s, 'UTF-8')] ?? 999, $a['all_skus'] ?? [$a['sku'] ?? '']) ?: [999]);
                $rb = min(array_map(static fn ($s) => $rank[mb_strtoupper((string) $s, 'UTF-8')] ?? 999, $b['all_skus'] ?? [$b['sku'] ?? '']) ?: [999]);
                return $ra <=> $rb;
            });
            foreach ($hits as $h) {
                if (!in_array((string) $h['id'], $exclude, true)) {
                    $pinned[] = $h;
                }
            }
        }
        $pinned = array_slice($pinned, 0, $max);
        if ($c['source'] === 'manual' || count($pinned) >= $max) {
            return array_map([SearchService::class, 'publicProduct'], $pinned);
        }
        $exclude = [...$exclude, ...array_map(static fn ($h) => (string) $h['id'], $pinned)];
        $cond = PageRules::filter(['match_all' => $c['match_all'], 'conditions' => $c['conditions']], $lang);
        if ($cond !== null) {
            $base[] = $cond;
        }
        $want = $max - count($pinned);
        // apprentissage : un choix plus large, reclassé ensuite selon les clics dans ce carrousel
        $pool = $c['learning'] ? min(100, max($want * 3, $want + 12)) : $want;
        $rest = self::fromSource($lang, $c, $base, $exclude, $pageProduct, $history, $pool);
        if ($rest === null) {
            // pas de contexte (aucun produit dans la page, pas d'historique) : sélection automatique
            $rest = self::query($lang, ['filter' => [...$base, ...self::notIds($exclude)], 'sort' => self::SORT_RULES[$c['sort_by']] ?? ['biz_score:desc'],
                'limit' => $pool, 'attributesToRetrieve' => SearchService::PUBLIC_FIELDS]);
        }
        if ($c['learning'] && count($rest) > 1) {
            $rest = self::learn($c['id'], $rest);
        }
        return array_map([SearchService::class, 'publicProduct'], [...$pinned, ...array_slice($rest, 0, $want)]);
    }

    /** Produits selon la source ; null = pas de contexte (repli sur la sélection automatique). @return ?list<array> */
    private static function fromSource(string $lang, array $c, array $base, array $exclude, ?array $pageProduct, ?array $history, int $limit): ?array
    {
        $sort = self::SORT_RULES[$c['sort_by']] ?? ['biz_score:desc'];
        $fields = SearchService::PUBLIC_FIELDS;
        if ($c['source'] === 'auto') {
            return self::query($lang, ['filter' => [...$base, ...self::notIds($exclude)], 'sort' => $sort, 'limit' => $limit, 'attributesToRetrieve' => $fields]);
        }
        if ($c['source'] === 'similar') {
            if ($pageProduct === null) {
                return null;
            }
            $f = self::similarFilters([$pageProduct], $c['similar_by']);
            return $f === [] ? null
                : self::query($lang, ['filter' => [...$base, ...self::notIds($exclude), ...$f], 'sort' => $sort, 'limit' => $limit, 'attributesToRetrieve' => $fields]);
        }
        // personnalisé
        if (!$history) {
            return null;
        }
        $history = array_values(array_diff($history, $exclude));
        if ($history === []) {
            return null;
        }
        if ($c['personal_mode'] === 'recent') {
            $hits = self::query($lang, ['filter' => [...$base, Filter::in('id', $history)], 'limit' => count($history), 'attributesToRetrieve' => $fields]);
            $rank = array_flip($history);
            usort($hits, static fn ($a, $b) => ($rank[(string) $a['id']] ?? 99) <=> ($rank[(string) $b['id']] ?? 99));
            return $hits === [] ? null : $hits;
        }
        // « vous aimerez aussi » : catégories (la plus précise de chaque produit) et marques de l'historique, hors produits déjà vus
        $docs = self::query($lang, ['filter' => [Filter::in('id', array_slice($history, 0, 10))], 'limit' => 10, 'attributesToRetrieve' => self::DOC_FIELDS]);
        $f = self::similarFilters($docs, 'category', true);
        return $f === [] ? null : self::query($lang, ['filter' => [...$base, ...self::notIds([...$exclude, ...$history]), ...$f], 'sort' => $sort,
            'limit' => $limit, 'attributesToRetrieve' => $fields]);
    }

    /**
     * Filtres « même catégorie / même marque » à partir de produits de référence.
     * Catégorie = la plus précise du produit (chemin le plus long), sinon toutes ses catégories.
     * $orBrand : catégorie OU marque (recommandations), sinon selon $by.
     * @return list<string|list<string>>
     */
    private static function similarFilters(array $docs, string $by, bool $orBrand = false): array
    {
        $cats = $brands = [];
        foreach ($docs as $d) {
            $paths = (array) ($d['category_paths'] ?? []);
            usort($paths, static fn ($a, $b) => substr_count((string) $b, '>') <=> substr_count((string) $a, '>'));
            $leaf = $paths ? trim((string) preg_replace('~^.*>~s', '', (string) $paths[0])) : '';
            $names = array_map('strval', (array) ($d['category_names'] ?? []));
            if ($leaf !== '' && in_array($leaf, $names, true)) {
                $cats[] = $leaf;
            } else {
                $cats = [...$cats, ...$names];
            }
            if (trim((string) ($d['brand'] ?? '')) !== '') {
                $brands[] = trim((string) $d['brand']);
            }
        }
        $cats = array_values(array_unique(array_filter($cats, 'strlen')));
        $brands = array_values(array_unique($brands));
        if ($orBrand) {
            $or = [...($cats ? [Filter::in('category_names', $cats)] : []), ...($brands ? [Filter::in('brand', $brands)] : [])];
            return $or === [] ? [] : [$or];
        }
        $out = [];
        if ($by !== 'brand') {
            if ($cats === []) {
                return [];
            }
            $out[] = Filter::in('category_names', $cats);
        }
        if ($by !== 'category') {
            if ($brands === []) {
                return [];
            }
            $out[] = Filter::in('brand', $brands);
        }
        return $out;
    }

    /** Filtres communs : produits disponibles, non désactivés, en stock si demandé. @return list<string> */
    private static function baseFilters(string $lang, array $c): array
    {
        $base = [];
        if (Settings::get('available_filter_' . $lang) === '1') {
            $base[] = 'available != 0';
        }
        $hideAll = RulesRepository::compiled($lang)['hide_all'] ?? [];
        if ($hideAll !== []) {
            $base[] = Filter::notIn('all_skus', array_values($hideAll));
        }
        if ($c['in_stock_only']) {
            $base[] = 'in_stock = 1';
        }
        return $base;
    }

    /** @return list<string> */
    private static function notIds(array $ids): array
    {
        return $ids === [] ? [] : [Filter::notIn('id', array_values(array_unique(array_map('strval', $ids))))];
    }

    /** @return list<array> documents trouvés */
    private static function query(string $lang, array $q): array
    {
        $uid = \DeepFind\Config::productIndex($lang);
        try {
            $res = \DeepFind\Meili\MeiliClient::search()->multiSearch([['indexUid' => $uid, 'q' => ''] + $q]);
        } catch (\Throwable $e) {
            error_log('[deepfind] carrousel : ' . $e->getMessage());
            return [];
        }
        return $res[0]['hits'] ?? [];
    }

    /**
     * Apprentissage : reclasse par taux de clic lissé dans ce carrousel sur 30 jours,
     * ctr = (clics + M × ctr moyen) / (affichages + M). Un produit encore peu montré garde le taux moyen (il n'est pas écarté),
     * un produit souvent montré et peu cliqué descend. À égalité, l'ordre de la source est gardé.
     * @param list<array> $hits @return list<array>
     */
    public static function learn(int $carouselId, array $hits): array
    {
        $ids = array_map(static fn ($h) => (string) $h['id'], $hits);
        $stats = self::productStats($carouselId, $ids, 30);
        $views = array_sum(array_column($stats, 'views'));
        $clicks = array_sum(array_column($stats, 'clicks'));
        $avg = $views > 0 ? $clicks / $views : 0.0;
        $m = 20;
        $scored = [];
        foreach ($hits as $i => $h) {
            $s = $stats[(string) $h['id']] ?? ['views' => 0, 'clicks' => 0];
            $scored[] = [($s['clicks'] + $m * $avg) / ($s['views'] + $m), $i, $h];
        }
        usort($scored, static fn ($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        return array_column($scored, 2);
    }

    // ------------------------------------------------------------------ Statistiques

    /**
     * Événement envoyé par le widget : v = carrousel vu à l'écran (avec les produits visibles), p = autres produits vus en faisant
     * défiler (produits seulement), c = clic sur un produit. Seuls les carrousels actifs comptent ; 48 produits au plus par événement.
     */
    public static function record(string $lang, int $id, string $event, array $productIds): bool
    {
        if (!in_array($event, ['v', 'p', 'c'], true) || !in_array($id, array_column(self::active($lang), 'id'), true)) {
            return false;
        }
        $pids = self::cleanIds($productIds, $event === 'c' ? 1 : 48);
        if ($event !== 'v' && $pids === []) {
            return false;
        }
        $pid = \DeepFind\Project::id();
        $day = date('Y-m-d');
        $col = $event === 'c' ? 'clicks' : 'views';
        if ($event !== 'p') {
            Db::exec("INSERT INTO carousel_stats (project_id, carousel_id, day, `$col`) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE `$col` = `$col` + 1", [$pid, $id, $day]);
        }
        if ($pids !== []) {
            $rows = implode(', ', array_fill(0, count($pids), '(?, ?, ?, ?, 1)'));
            $params = [];
            foreach ($pids as $p) {
                array_push($params, $pid, $id, $p, $day);
            }
            Db::exec("INSERT INTO carousel_product_stats (project_id, carousel_id, product_id, day, `$col`) VALUES $rows ON DUPLICATE KEY UPDATE `$col` = `$col` + 1", $params);
        }
        return true;
    }

    /** @return array<int, array{views:int, clicks:int, ctr:?float}> */
    public static function stats(array $ids, int $days): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[(int) $id] = ['views' => 0, 'clicks' => 0, 'ctr' => null];
        }
        if ($out === []) {
            return $out;
        }
        $in = implode(',', array_fill(0, count($out), '?'));
        $since = date('Y-m-d', time() - (max(1, $days) - 1) * 86400);
        foreach (Db::all("SELECT carousel_id, SUM(views) AS v, SUM(clicks) AS c FROM carousel_stats WHERE project_id = ? AND day >= ? AND carousel_id IN ($in) GROUP BY carousel_id",
            [\DeepFind\Project::id(), $since, ...array_keys($out)]) as $r) {
            $v = (int) $r['v'];
            $c = (int) $r['c'];
            $out[(int) $r['carousel_id']] = ['views' => $v, 'clicks' => $c, 'ctr' => $v > 0 ? round(100 * $c / $v, 1) : null];
        }
        return $out;
    }

    /** @return array<string, array{views:int, clicks:int}> par produit */
    public static function productStats(int $carouselId, array $productIds, int $days): array
    {
        if ($productIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($productIds), '?'));
        $since = date('Y-m-d', time() - (max(1, $days) - 1) * 86400);
        $out = [];
        foreach (Db::all("SELECT product_id, SUM(views) AS v, SUM(clicks) AS c FROM carousel_product_stats
                WHERE project_id = ? AND carousel_id = ? AND day >= ? AND product_id IN ($in) GROUP BY product_id",
            [\DeepFind\Project::id(), $carouselId, $since, ...array_map('strval', $productIds)]) as $r) {
            $out[(string) $r['product_id']] = ['views' => (int) $r['v'], 'clicks' => (int) $r['c']];
        }
        return $out;
    }

    /** Produits les plus vus du carrousel (fiche : tableau des performances). @return list<array{product_id:string, views:int, clicks:int, ctr:?float}> */
    public static function topProducts(int $carouselId, int $days, int $limit = 20): array
    {
        $since = date('Y-m-d', time() - (max(1, $days) - 1) * 86400);
        $rows = Db::all('SELECT product_id, SUM(views) AS v, SUM(clicks) AS c FROM carousel_product_stats WHERE project_id = ? AND carousel_id = ? AND day >= ?
            GROUP BY product_id ORDER BY v DESC, c DESC LIMIT ' . max(1, min(100, $limit)), [\DeepFind\Project::id(), $carouselId, $since]);
        return array_map(static fn ($r) => ['product_id' => (string) $r['product_id'], 'views' => (int) $r['v'], 'clicks' => (int) $r['c'],
            'ctr' => (int) $r['v'] > 0 ? round(100 * (int) $r['c'] / (int) $r['v'], 1) : null], $rows);
    }

    /** Statistiques par produit : suppression au-delà de la conservation des journaux (aucune donnée personnelle, mais volumineuses). */
    public static function purge(): int
    {
        $days = max(30, Settings::int('log_retention_days'));
        return Db::exec('DELETE FROM carousel_product_stats WHERE day < ? LIMIT 50000', [date('Y-m-d', time() - $days * 86400)]);
    }
}

