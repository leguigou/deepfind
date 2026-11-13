<?php

declare(strict_types=1);

namespace DeepFind\Search;

use DeepFind\Config;
use DeepFind\Meili\MeiliClient;
use DeepFind\Rules\BoostRules;
use DeepFind\Rules\CustomResults;
use DeepFind\Rules\RulesRepository;
use DeepFind\Settings;
use DeepFind\Store\Store;

/**
 * Recherche : une seule requête HTTP « multi-search » vers Meilisearch regroupe
 * produits + produits épinglés + correspondance exacte SKU/EAN + articles + compteurs de filtres.
 */
final class SearchService
{
    private const PUBLIC_FIELDS = ['id', 'sku', 'name', 'brand', 'url', 'image', 'image_hover', 'price', 'final_price',
        'has_price_range', 'on_sale', 'discount_percent', 'in_stock', 'rating', 'reviews_count', 'is_new'];
    private const INTERNAL_FIELDS = ['margin_rate', 'biz_score', 'salable_qty', 'sales_30d', 'manual_boost', 'boost_total', 'clicks_30d', 'type', 'category_names'];
    private const POST_FIELDS = ['id', 'title', 'url', 'image', 'excerpt', 'published_ts'];
    // Aperçu interne uniquement : fiche détaillée et champs où la recherche a été trouvée.
    private const DETAIL_FIELDS = ['short_description', 'description', 'ean', 'all_skus', 'all_eans', 'category_paths', 'category_terms',
        'search_keywords', 'attr', 'attr_values', 'variants', 'created_ts'];

    /** @var list<string> mots de la recherche en cours (aperçu interne) */
    private array $words = [];
    /** @var array<string, true> SKU désactivés partout (aperçu interne : produits signalés « désactivé ») */
    private array $hideAll = [];
    /** @var array<string,int> SKU mis en avant => résultat personnalisé (suivi des clics) */
    private array $owner = [];
    /** @var array<int,string> noms des résultats personnalisés appliqués (aperçu interne) */
    private array $crNames = [];
    /** @var array<string, string> libellés des attributs */
    private array $attrLabels = [];

    public function __construct(private readonly MeiliClient $meili)
    {
    }

    /**
     * @param bool $withDisabled aperçu interne : garder les produits désactivés dans la liste, à leur place, signalés « disabled »
     */
    public function search(SearchRequest $r, bool $internal = false, bool $withDisabled = false): array
    {
        $productIndex = Config::productIndex($r->lang);
        $postIndex = Config::postIndex($r->lang);
        $rules = RulesRepository::compiled($r->lang);
        $q = $r->qNorm;
        $this->words = $internal ? Relevance::words($r->q, $r->lang) : [];

        // Résultats personnalisés (termes de la recherche) : produits mis en avant ou seuls affichés, produits exclus.
        // L'ordre imposé ne vaut qu'en tri « pertinence » (un tri par prix demandé par le visiteur reste respecté).
        $relevance = $r->sort === 'relevance';
        $plan = CustomResults::plan($q !== '' ? CustomResults::forQuery($r->lang, $r->q) : []);
        $only = $plan['only'];
        $this->owner = $plan['item_owner'];
        $this->crNames = $plan['names'];
        $withDisabled = $withDisabled && $internal;
        $this->hideAll = $internal ? array_fill_keys(array_map(static fn ($s) => mb_strtoupper((string) $s, 'UTF-8'), $rules['hide_all']), true) : [];
        $hides = array_values(array_unique(array_merge($q !== '' ? ($rules['hides'][$q] ?? []) : [], $plan['exclude_items'], $withDisabled ? [] : $rules['hide_all'])));

        $groups = $this->userFilterGroups($r, $internal);
        $base = array_values($groups);
        if ($hides !== []) {
            $base[] = Filter::notIn('all_skus', $hides);
        }
        foreach ($plan['exclude_filters'] as $ex) {
            $base[] = $ex;
        }
        if ($only) {
            // Uniquement les produits sélectionnés (un par un ou par règle), quels que soient les mots
            $base[] = [...($plan['include_items'] ? [Filter::in('all_skus', $plan['include_items'])] : []), ...$plan['include_filters']];
        }
        $injected = $relevance ? array_values(array_diff($plan['include_items'], $hides)) : [];
        if ($relevance && !$only && $plan['include_filters'] !== [] && $r->offset >= 0 && $this->indexExists($productIndex)) {
            // Mode « en premier » : produits des règles d'inclusion placés après les produits choisis un par un
            $pre = $this->meili->multiSearch([['indexUid' => $productIndex, 'q' => '', 'limit' => CustomResults::RULE_ITEMS_MAX,
                'filter' => [...$base, $plan['include_filters']], 'sort' => ['biz_score:desc'], 'attributesToRetrieve' => ['sku']]]);
            foreach ($pre[0]['hits'] ?? [] as $h) {
                $sku = mb_strtoupper((string) $h['sku'], 'UTF-8');
                if (!in_array($sku, $injected, true)) {
                    $injected[] = $sku;
                    $this->owner[$sku] ??= (int) $plan['rule_owner'];
                }
            }
        }
        $mainQ = $only ? '' : $r->q;
        $mainFilter = $base;
        if ($injected !== []) {
            $mainFilter[] = Filter::notIn('all_skus', $injected);
        }

        $nInj = count($injected);
        $mainOffset = $r->offset === 0 ? 0 : max(0, $r->offset - $nInj);
        $mainLimit = $r->offset === 0 ? max(0, $r->limit - $nInj) : $r->limit;
        // Bonus « mot au début du nom » : DeepFind réordonne lui-même les N premiers résultats (tri pertinence uniquement).
        $window = Relevance::rerankWindow();
        // Règles de boost (page « Règles de boost ») : appliquées au même réordonnancement.
        $boostRules = $r->sort === 'relevance' ? BoostRules::forQuery($r->lang, $r->q) : [];
        $rerank = $r->sort === 'relevance' && $mainOffset < $window
            && (($r->q !== '' && Relevance::namePositionWeight() > 0) || $boostRules !== [])
            ? ['offset' => $mainOffset, 'limit' => $mainLimit] : null;

        $attrLabels = Settings::json('attr_labels_' . $r->lang);
        $facets = ['brand', 'category_names', 'in_stock', 'on_sale', 'final_price'];
        foreach (array_keys($attrLabels) as $code) {
            $facets[] = 'attr.' . $code;
        }
        $this->attrLabels = $attrLabels;
        $fields = $internal ? [...self::PUBLIC_FIELDS, ...self::INTERNAL_FIELDS, ...self::DETAIL_FIELDS] : self::PUBLIC_FIELDS;

        $queries = [];
        $names = [];
        if (!$this->indexExists($productIndex)) {
            return $this->empty($r);
        }
        $queries[] = [
            'indexUid' => $productIndex, 'q' => $mainQ,
            'offset' => $rerank ? 0 : $mainOffset, 'limit' => $rerank ? max($window, $mainOffset + $mainLimit) : $mainLimit,
            'filter' => $mainFilter, 'facets' => $facets, 'sort' => $this->sort($r),
            // Champs des conditions : lus pour le calcul uniquement, product() ne renvoie que la liste blanche.
            'attributesToRetrieve' => $boostRules !== [] ? array_values(array_unique([...$fields, ...BoostRules::DOC_FIELDS])) : $fields,
        ];
        if ($rerank) {
            $queries[0]['showRankingScore'] = true;
        }
        $names[] = 'main';

        if ($nInj > 0) {
            $queries[] = [
                'indexUid' => $productIndex, 'q' => '', 'limit' => $nInj,
                'filter' => [...$base, Filter::in('all_skus', $injected)], 'attributesToRetrieve' => [...$fields, 'all_skus'],
            ];
            $names[] = 'injected';
        }

        $skuLike = $r->offset === 0 && !$only && self::looksLikeSku($r->q);
        if ($skuLike) {
            $code = mb_strtoupper(str_replace(' ', '', $r->q), 'UTF-8');
            $queries[] = [
                'indexUid' => $productIndex, 'q' => '', 'limit' => 5,
                'filter' => [...$base, [Filter::eq('all_skus', $code), Filter::eq('all_eans', $code)]],
                'attributesToRetrieve' => [...$fields, 'variants'],
            ];
            $names[] = 'sku';
        }

        // Compteurs « disjonctifs » : sélectionner une marque n'efface pas les autres marques de la liste.
        foreach ($groups as $field => $clause) {
            if ($field === 'brand' || $field === 'category_names' || str_starts_with($field, 'attr.')) {
                $others = $groups;
                unset($others[$field]);
                $f = array_values($others);
                if ($hides !== []) {
                    $f[] = Filter::notIn('all_skus', $hides);
                }
                $queries[] = ['indexUid' => $productIndex, 'q' => $mainQ, 'limit' => 0, 'filter' => $f, 'facets' => [$field]];
                $names[] = 'facet:' . $field;
            }
        }

        // Aperçu interne : produits désactivés (masqués de toutes les recherches) qui correspondraient à cette recherche.
        if ($internal && $rules['hide_all'] !== []) {
            $queries[] = ['indexUid' => $productIndex, 'q' => $r->q, 'limit' => 50, 'attributesToRetrieve' => $fields,
                'filter' => [...array_values($groups), Filter::in('all_skus', $rules['hide_all'])]];
            $names[] = 'disabled';
        }

        $withPosts = $r->withPosts && $r->offset === 0 && $r->q !== '' && Settings::int('posts_limit') > 0 && $this->indexExists($postIndex);
        if ($withPosts) {
            $queries[] = ['indexUid' => $postIndex, 'q' => $r->q, 'limit' => Settings::int('posts_limit'), 'attributesToRetrieve' => self::POST_FIELDS];
            $names[] = 'posts';
        }

        // Correspondance des mots (page Pertinence) :
        //   exact_then_approx : tous les mots doivent être trouvés ; si aucun produit, résultats « approchants » signalés comme tels ;
        //   strict            : tous les mots, sinon aucun résultat ;
        //   loose             : le moteur retire des mots jusqu'à trouver quelque chose (ancien comportement).
        $mode = Relevance::matchingMode();
        $results = $this->runQueries($queries, $names, $mode === 'loose' ? 'last' : 'all', $internal);
        $approx = false;
        if ($mode === 'exact_then_approx' && $r->q !== '' && (int) ($results['main']['estimatedTotalHits'] ?? 0) === 0
            && empty($results['sku']['hits']) && empty($results['injected']['hits'])) {
            $results = $this->runQueries($queries, $names, 'last', $internal);
            $approx = (int) ($results['main']['estimatedTotalHits'] ?? 0) > 0;
        }
        if ($rerank && isset($results['main']['hits'])) {
            $results['main']['hits'] = array_slice($this->rerank($results['main']['hits'], $r, $boostRules), $rerank['offset'], $rerank['limit']);
        }
        $out = $this->format($r, $results, $injected, $nInj, $mainOffset, $mainLimit, $attrLabels, $internal, $skuLike, $approx);
        if ($only && $plan['ids'] !== []) {
            // Mode « uniquement » : tous les produits affichés viennent du résultat personnalisé (suivi des clics)
            $owner = $plan['rule_owner'] ?? $plan['ids'][0];
            foreach ($out['products'] as &$p) {
                $p['cr'] ??= $owner;
                if ($internal) {
                    $p['rule'] ??= 'résultat perso : ' . ($this->crNames[$p['cr']] ?? '');
                }
            }
            unset($p);
        }
        // Résultats personnalisés appliqués (journal des recherches → impressions) ; retiré de la réponse publique par l'API
        $out['_cr'] = $plan['ids'];
        return $out;
    }

    /**
     * Réordonne les premiers résultats : pertinence du moteur + bonus quand les mots recherchés sont au début du nom.
     * Les ruptures restent en fin de liste si l'option est active ; à égalité, l'ordre du moteur est conservé.
     */
    private function rerank(array $hits, SearchRequest $r, array $boostRules = []): array
    {
        $words = Relevance::words($r->q, $r->lang);
        if ($words === [] && $boostRules === []) {
            return $hits;
        }
        $weight = Relevance::namePositionWeight();
        $oosLast = Settings::int('out_of_stock_last') === 1;
        $keyed = [];
        foreach ($hits as $i => $h) {
            $bonus = $words === [] ? 0.0 : Relevance::namePositionBonus($words, (string) ($h['name'] ?? ''), $r->lang);
            [$factor, $applied] = $boostRules === [] ? [1.0, []] : BoostRules::factor($boostRules, $h);
            $h['_namePosition'] = round($bonus, 3);
            $h['_boostFactor'] = $factor;
            $h['_boostRules'] = $applied;
            // Pertinence × coefficient des règles de boost (1 = neutre, 2 = double, 0,5 = moitié, 0 = en fin de liste)
            $h['_finalScore'] = round(((float) ($h['_rankingScore'] ?? 1.0) + $weight * $bonus) * $factor, 4);
            $keyed[] = [$oosLast ? (int) ($h['in_stock'] ?? 1) : 0, $h['_finalScore'], -$i, $h];
        }
        usort($keyed, static fn ($a, $b) => [$b[0], $b[1], $b[2]] <=> [$a[0], $a[1], $a[2]]);
        return array_map(static fn ($k) => $k[3], $keyed);
    }

    /** @return array<string, array> résultats par nom de requête */
    private function runQueries(array $queries, array $names, string $strategy, bool $internal): array
    {
        $threshold = Relevance::minScore();
        foreach ($queries as &$q) {
            if (($q['q'] ?? '') === '') {
                continue; // requêtes par filtre (SKU exact, produits épinglés) : pas concernées
            }
            $q['matchingStrategy'] = $strategy;
            if ($threshold > 0) {
                $q['rankingScoreThreshold'] = $threshold;
            }
            if ($internal) {
                $q['showRankingScore'] = true;
                $q['showMatchesPosition'] = true;
            }
        }
        unset($q);
        $results = [];
        foreach ($this->meili->multiSearch($queries) as $i => $res) {
            $results[$names[$i]] = $res;
        }
        return $results;
    }

    /** @return array<string, string|list<string>> clauses de filtre par groupe (combinées en ET) */
    private function userFilterGroups(SearchRequest $r, bool $internal): array
    {
        $g = [];
        if ($r->brands !== []) {
            $g['brand'] = array_map(static fn ($v) => Filter::eq('brand', $v), $r->brands);
        }
        if ($r->categories !== []) {
            $g['category_names'] = array_map(static fn ($v) => Filter::eq('category_names', $v), $r->categories);
        }
        $labels = Settings::json('attr_labels_' . $r->lang);
        foreach ($r->attrs as $code => $values) {
            if (isset($labels[$code])) {
                $g['attr.' . $code] = array_map(static fn ($v) => Filter::eq('attr.' . $code, $v), $values);
            }
        }
        if ($r->inStock !== null) {
            $g['in_stock'] = 'in_stock = ' . $r->inStock;
        }
        if ($r->onSale !== null) {
            $g['on_sale'] = 'on_sale = 1';
        }
        if ($r->priceMin !== null) {
            $g['price_min'] = 'final_price >= ' . self::num($r->priceMin);
        }
        if ($r->priceMax !== null) {
            $g['price_max'] = 'final_price <= ' . self::num($r->priceMax);
        }
        if ($internal && $r->marginMin !== null) {
            $g['margin_min'] = 'margin_rate >= ' . self::num($r->marginMin);
        }
        return $g;
    }

    /** @return list<string> */
    private function sort(SearchRequest $r): array
    {
        $s = Settings::int('out_of_stock_last') === 1 ? ['in_stock:desc'] : [];
        return match ($r->sort) {
            'price_asc' => [...$s, 'sort_price:asc'],
            'price_desc' => [...$s, 'sort_price:desc'],
            'newest' => [...$s, 'created_ts:desc'],
            'margin_desc' => ['margin_rate:desc'],
            'popularity_desc' => ['sales_30d:desc'],
            'score_desc' => ['biz_score:desc'],
            default => $s,
        };
    }

    private function format(SearchRequest $r, array $res, array $injected, int $nInj, int $mainOffset, int $mainLimit, array $attrLabels, bool $internal, bool $skuLike, bool $approx = false): array
    {
        $main = $res['main'] ?? [];
        $hits = array_map(fn ($h) => $this->product($h, $internal), $main['hits'] ?? []);
        $foundInj = 0;

        if ($nInj > 0) {
            $bySku = [];
            foreach ($res['injected']['hits'] ?? [] as $h) {
                foreach ($h['all_skus'] ?? [] as $s) {
                    $bySku[$s] = $h;
                }
            }
            $foundInj = count($res['injected']['hits'] ?? []);
            if ($r->offset === 0) {
                // Produits mis en avant, dans l'ordre choisi (un par un, puis par règle)
                $used = [];
                $top = [];
                foreach ($injected as $i => $sku) {
                    $h = $bySku[$sku] ?? null;
                    if ($h === null || isset($used[$h['id']])) {
                        continue;
                    }
                    $used[$h['id']] = true;
                    $cr = $this->owner[$sku] ?? null;
                    $top[] = $this->product($h, $internal) + ($cr !== null ? ['cr' => $cr] : [])
                        + ($internal ? ['rule' => 'résultat perso : ' . ($this->crNames[$cr] ?? '') . ' #' . (count($top) + 1)] : []);
                }
                $hits = array_merge($top, $hits);
            }
        }

        if ($skuLike && !empty($res['sku']['hits'])) {
            $code = mb_strtoupper(str_replace(' ', '', $r->q), 'UTF-8');
            $exact = [];
            foreach ($res['sku']['hits'] as $h) {
                $p = $this->product($h, $internal);
                foreach ($h['variants'] ?? [] as $v) {
                    if (mb_strtoupper($v['sku'], 'UTF-8') === $code || ($v['ean'] ?? '') === $code) {
                        $p['matched_variant'] = [
                            'sku' => $v['sku'], 'url' => $v['url'] ?: $p['url'], 'final_price' => $v['final_price'],
                            'price' => $v['price'], 'in_stock' => $v['in_stock'],
                        ];
                    }
                }
                $p['exact_match'] = true;
                $exact[] = $p;
            }
            $ids = array_column($exact, 'id');
            $hits = array_merge($exact, array_values(array_filter($hits, static fn ($h) => !in_array($h['id'], $ids, true))));
        }

        $mainTotal = (int) ($main['estimatedTotalHits'] ?? $main['totalHits'] ?? 0);
        $dist = $main['facetDistribution'] ?? [];
        foreach ($res as $name => $fr) {
            if (str_starts_with($name, 'facet:')) {
                $field = substr($name, 6);
                $dist[$field] = $fr['facetDistribution'][$field] ?? [];
            }
        }

        $facets = [];
        $facets[] = $this->facet('brand', self::label('brand', $r->lang), $dist['brand'] ?? [], $r->brands, true);
        $facets[] = $this->facet('category', self::label('category', $r->lang), $dist['category_names'] ?? [], $r->categories, false);
        foreach ($attrLabels as $code => $label) {
            $facets[] = $this->facet('attr.' . $code, (string) $label, $dist['attr.' . $code] ?? [], $r->attrs[$code] ?? [], false);
        }
        $facets = array_values(array_filter($facets, static fn ($f) => $f['values'] !== []));

        $posts = [];
        foreach ($res['posts']['hits'] ?? [] as $p) {
            $posts[] = [
                'id' => $p['id'], 'title' => $p['title'], 'url' => $p['url'], 'image' => $p['image'] ?? '',
                'excerpt' => $p['excerpt'] ?? '', 'date' => !empty($p['published_ts']) ? date('Y-m-d', (int) $p['published_ts']) : null,
            ];
        }

        $stats = $main['facetStats']['final_price'] ?? null;
        $total = $mainTotal + $foundInj;
        return [
            'query' => $r->q,
            'lang' => $r->lang,
            'total' => $total,
            'offset' => $r->offset,
            'limit' => $r->limit,
            'next_offset' => $r->offset + $r->limit,
            'has_more' => ($mainOffset + $mainLimit) < $mainTotal,
            // true : aucun produit ne contient tous les mots ; produits « approchants » (comptés « sans résultat exact »)
            'approximate' => $approx,
            'redirect' => RulesRepository::compiled($r->lang)['redirects'][$r->qNorm] ?? null,
            'products' => $hits,
            'facets' => $facets,
            'toggles' => [
                'in_stock' => (int) ($dist['in_stock']['1'] ?? 0),
                'on_sale' => (int) ($dist['on_sale']['1'] ?? 0),
            ],
            'price_range' => $stats ? ['min' => (float) $stats['min'], 'max' => (float) $stats['max']] : null,
            'posts' => $posts,
            'posts_total' => (int) ($res['posts']['estimatedTotalHits'] ?? 0),
            'engine_ms' => (int) ($main['processingTimeMs'] ?? 0),
        ] + ($internal ? ['disabled' => array_map(fn ($h) => $this->product($h, true), $res['disabled']['hits'] ?? [])] : []);
    }

    private function product(array $h, bool $internal): array
    {
        $p = [];
        foreach ($internal ? [...self::PUBLIC_FIELDS, ...self::INTERNAL_FIELDS] : self::PUBLIC_FIELDS as $f) {
            if (array_key_exists($f, $h)) {
                $p[$f] = $h[$f];
            }
        }
        if ($internal && isset($h['_rankingScore'])) {
            $p['relevance'] = round((float) $h['_rankingScore'], 3);
        }
        if ($internal && isset($h['_namePosition'])) {
            $p['name_position'] = $h['_namePosition'];
            $p['final_score'] = $h['_finalScore'];
        }
        if ($internal && !empty($h['_boostRules'])) {
            $p['boost_factor'] = round((float) $h['_boostFactor'], 3);
            $p['boost_rules'] = $h['_boostRules'];
        }
        foreach (['in_stock', 'on_sale', 'has_price_range', 'is_new'] as $b) {
            if (isset($p[$b])) {
                $p[$b] = (bool) $p[$b];
            }
        }
        if ($internal) {
            $p['disabled'] = false;
            foreach ($h['all_skus'] ?? [$h['sku'] ?? ''] as $s) {
                if (isset($this->hideAll[mb_strtoupper((string) $s, 'UTF-8')])) {
                    $p['disabled'] = true;
                    break;
                }
            }
            $p['details'] = array_intersect_key($h, array_flip(self::DETAIL_FIELDS));
            $p['matched'] = $this->matched($h);
        }
        return $p;
    }

    /**
     * Champs où la recherche a été trouvée (aperçu interne) : positions renvoyées par le moteur (showMatchesPosition),
     * sinon repli sur une comparaison mot à mot (fautes de frappe et synonymes non détectés dans ce cas).
     * @return list<array{field:string, label:string, values:list<string>}>
     */
    private function matched(array $h): array
    {
        if ($this->words === []) {
            return [];
        }
        $positions = $h['_matchesPosition'] ?? null;
        $keys = is_array($positions) ? array_keys($positions) : Relevance::searchableAttributes();
        $out = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            $field = (string) preg_replace('/[.\[].*$/', '', $key);
            $raw = $h[$field] ?? null;
            if ($raw === null || $raw === '' || $raw === []) {
                continue;
            }
            $list = is_array($raw) ? array_values(array_filter($raw, 'is_scalar')) : [$raw];
            $indices = [];
            foreach (is_array($positions) ? ($positions[$key] ?? []) : [] as $pos) {
                foreach ((array) ($pos['indices'] ?? []) as $i) {
                    $indices[(int) $i] = true;
                }
            }
            $values = [];
            foreach ($list as $i => $v) {
                $v = (string) $v;
                if ($indices !== [] ? isset($indices[$i]) : $this->hasWord($v)) {
                    $values[] = $v;
                }
            }
            if ($values === [] && is_array($positions)) {
                $values = array_map('strval', array_slice($list, 0, 3)); // trouvé par le moteur (faute de frappe, synonyme…)
            }
            if ($values === []) {
                continue;
            }
            $values = array_values(array_unique(array_map(fn ($v) => $this->excerpt($v), array_slice($values, 0, 8))));
            if ($field === 'attr_values' && is_array($h['attr'] ?? null)) {
                $values = array_map(fn ($v) => $this->attrLabel($h['attr'], $v), $values);
            }
            $out[] = ['field' => $field, 'label' => Relevance::FIELDS[$field][0] ?? $field, 'values' => $values];
        }
        return $out;
    }

    private function hasWord(string $text): bool
    {
        foreach (preg_split('/[^\p{L}\p{N}]+/u', \DeepFind\Text::normalize($text, 5000)) ?: [] as $w) {
            if ($w !== '' && Relevance::matchesAny($this->words, $w)) {
                return true;
            }
        }
        return false;
    }

    /** Extrait d'environ 160 caractères autour du premier mot trouvé (descriptions longues). */
    private function excerpt(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text, 'UTF-8') <= 160) {
            return $text;
        }
        $start = 0;
        foreach ($this->words as $w) {
            $p = mb_stripos(\DeepFind\Text::normalize($text, 5000), $w, 0, 'UTF-8');
            if ($p !== false) {
                $start = max(0, $p - 50);
                break;
            }
        }
        return ($start > 0 ? '… ' : '') . mb_substr($text, $start, 160, 'UTF-8') . ' …';
    }

    /** « Jaune » → « Couleur : Jaune » quand la valeur appartient à un attribut connu. */
    private function attrLabel(array $attr, string $value): string
    {
        foreach ($attr as $code => $vals) {
            if (in_array($value, (array) $vals, true)) {
                return ($this->attrLabels[$code] ?? $code) . ' : ' . $value;
            }
        }
        return $value;
    }

    private function facet(string $code, string $label, array $dist, array $selected, bool $alpha): array
    {
        $values = [];
        foreach ($dist as $value => $count) {
            $values[] = ['value' => (string) $value, 'count' => (int) $count, 'selected' => in_array((string) $value, $selected, true)];
        }
        foreach ($selected as $s) {
            if (!array_key_exists($s, $dist)) {
                $values[] = ['value' => $s, 'count' => 0, 'selected' => true];
            }
        }
        if ($alpha) {
            usort($values, static fn ($a, $b) => strnatcasecmp($a['value'], $b['value']));
        } else {
            usort($values, static fn ($a, $b) => $b['count'] <=> $a['count'] ?: strnatcasecmp($a['value'], $b['value']));
        }
        return ['code' => $code, 'label' => $label, 'values' => array_slice($values, 0, max(5, Settings::int('facet_values_limit')))];
    }

    private function empty(SearchRequest $r): array
    {
        return ['query' => $r->q, 'lang' => $r->lang, 'total' => 0, 'offset' => $r->offset, 'limit' => $r->limit,
            'next_offset' => $r->offset + $r->limit, 'has_more' => false, 'approximate' => false, 'redirect' => null, 'products' => [], 'facets' => [],
            'toggles' => ['in_stock' => 0, 'on_sale' => 0], 'price_range' => null, 'posts' => [], 'posts_total' => 0, 'engine_ms' => 0];
    }

    private function indexExists(string $uid): bool
    {
        $store = Store::get();
        $k = 'idx:' . \DeepFind\Project::id() . ':' . \DeepFind\CacheVersion::get() . ':' . $uid;
        $v = $store->get($k);
        if ($v === null) {
            $v = $this->meili->getIndex($uid) !== null ? 1 : 0;
            $store->set($k, $v, $v ? 300 : 30);
        }
        return (bool) $v;
    }

    public static function looksLikeSku(string $q): bool
    {
        $q = trim($q);
        return strlen($q) >= 3 && strlen($q) <= 64 && !str_contains($q, ' ')
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9\-_.\/]+$/', $q) === 1 && preg_match('/\d/', $q) === 1;
    }

    private static function num(float $f): string
    {
        return rtrim(rtrim(number_format($f, 4, '.', ''), '0'), '.');
    }

    public static function label(string $code, string $lang): string
    {
        $l = [
            'fr' => ['brand' => 'Marques', 'category' => 'Catégories'],
            'en' => ['brand' => 'Brands', 'category' => 'Categories'],
        ];
        return $l[$lang][$code] ?? $l['fr'][$code] ?? $code;
    }
}
