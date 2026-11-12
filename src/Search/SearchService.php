<?php

declare(strict_types=1);

namespace DeepFind\Search;

use DeepFind\Config;
use DeepFind\Meili\MeiliClient;
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
    private const INTERNAL_FIELDS = ['margin_rate', 'biz_score', 'salable_qty', 'sales_30d', 'manual_boost', 'type', 'category_names'];
    private const POST_FIELDS = ['id', 'title', 'url', 'image', 'excerpt', 'published_ts'];

    public function __construct(private readonly MeiliClient $meili)
    {
    }

    public function search(SearchRequest $r, bool $internal = false): array
    {
        $productIndex = Config::productIndex($r->lang);
        $postIndex = Config::postIndex($r->lang);
        $rules = RulesRepository::compiled($r->lang);
        $q = $r->qNorm;

        // Épingler / remonter : uniquement en tri « pertinence » (un tri par prix demandé par le visiteur reste respecté).
        $withRules = $q !== '' && $r->sort === 'relevance';
        $pins = $withRules ? ($rules['pins'][$q] ?? []) : [];
        $boosts = $withRules ? ($rules['boosts'][$q] ?? []) : [];
        $hides = array_values(array_unique(array_merge($q !== '' ? ($rules['hides'][$q] ?? []) : [], $rules['hide_all'])));
        $injected = array_values(array_unique(array_merge($boosts, array_column($pins, 'sku'))));
        $injected = array_values(array_diff($injected, $hides));

        $groups = $this->userFilterGroups($r, $internal);
        $base = array_values($groups);
        if ($hides !== []) {
            $base[] = Filter::notIn('all_skus', $hides);
        }
        $mainFilter = $base;
        if ($injected !== []) {
            $mainFilter[] = Filter::notIn('all_skus', $injected);
        }

        $nInj = count($injected);
        $mainOffset = $r->offset === 0 ? 0 : max(0, $r->offset - $nInj);
        $mainLimit = $r->offset === 0 ? max(0, $r->limit - $nInj) : $r->limit;

        $attrLabels = Settings::json('attr_labels_' . $r->lang);
        $facets = ['brand', 'category_names', 'in_stock', 'on_sale', 'final_price'];
        foreach (array_keys($attrLabels) as $code) {
            $facets[] = 'attr.' . $code;
        }
        $fields = $internal ? [...self::PUBLIC_FIELDS, ...self::INTERNAL_FIELDS] : self::PUBLIC_FIELDS;

        $queries = [];
        $names = [];
        if (!$this->indexExists($productIndex)) {
            return $this->empty($r);
        }
        $queries[] = [
            'indexUid' => $productIndex, 'q' => $r->q, 'offset' => $mainOffset, 'limit' => $mainLimit,
            'filter' => $mainFilter, 'facets' => $facets, 'sort' => $this->sort($r), 'attributesToRetrieve' => $fields,
        ];
        $names[] = 'main';

        if ($nInj > 0) {
            $queries[] = [
                'indexUid' => $productIndex, 'q' => '', 'limit' => $nInj,
                'filter' => [...$base, Filter::in('all_skus', $injected)], 'attributesToRetrieve' => [...$fields, 'all_skus'],
            ];
            $names[] = 'injected';
        }

        $skuLike = $r->offset === 0 && self::looksLikeSku($r->q);
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
                $queries[] = ['indexUid' => $productIndex, 'q' => $r->q, 'limit' => 0, 'filter' => $f, 'facets' => [$field]];
                $names[] = 'facet:' . $field;
            }
        }

        $withPosts = $r->withPosts && $r->offset === 0 && $r->q !== '' && Settings::int('posts_limit') > 0 && $this->indexExists($postIndex);
        if ($withPosts) {
            $queries[] = ['indexUid' => $postIndex, 'q' => $r->q, 'limit' => Settings::int('posts_limit'), 'attributesToRetrieve' => self::POST_FIELDS];
            $names[] = 'posts';
        }

        $results = [];
        foreach ($this->meili->multiSearch($queries) as $i => $res) {
            $results[$names[$i]] = $res;
        }
        return $this->format($r, $results, $pins, $boosts, $nInj, $mainOffset, $mainLimit, $attrLabels, $internal, $skuLike);
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

    private function format(SearchRequest $r, array $res, array $pins, array $boosts, int $nInj, int $mainOffset, int $mainLimit, array $attrLabels, bool $internal, bool $skuLike): array
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
                $used = [];
                $top = [];
                foreach ($boosts as $sku) {
                    if (isset($bySku[$sku]) && !isset($used[$bySku[$sku]['id']])) {
                        $used[$bySku[$sku]['id']] = true;
                        $top[] = $this->product($bySku[$sku], $internal) + ($internal ? ['rule' => 'boost'] : []);
                    }
                }
                $hits = array_merge($top, $hits);
                usort($pins, static fn ($a, $b) => $a['position'] <=> $b['position']);
                foreach ($pins as $p) {
                    $h = $bySku[$p['sku']] ?? null;
                    if ($h === null || isset($used[$h['id']])) {
                        continue;
                    }
                    $used[$h['id']] = true;
                    array_splice($hits, min(count($hits), $p['position'] - 1), 0, [$this->product($h, $internal) + ($internal ? ['rule' => 'pin #' . $p['position']] : [])]);
                }
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
        ];
    }

    private function product(array $h, bool $internal): array
    {
        $p = [];
        foreach ($internal ? [...self::PUBLIC_FIELDS, ...self::INTERNAL_FIELDS] : self::PUBLIC_FIELDS as $f) {
            if (array_key_exists($f, $h)) {
                $p[$f] = $h[$f];
            }
        }
        foreach (['in_stock', 'on_sale', 'has_price_range', 'is_new'] as $b) {
            if (isset($p[$b])) {
                $p[$b] = (bool) $p[$b];
            }
        }
        return $p;
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
            'next_offset' => $r->offset + $r->limit, 'has_more' => false, 'redirect' => null, 'products' => [], 'facets' => [],
            'toggles' => ['in_stock' => 0, 'on_sale' => 0], 'price_range' => null, 'posts' => [], 'posts_total' => 0, 'engine_ms' => 0];
    }

    private function indexExists(string $uid): bool
    {
        $store = Store::get();
        $k = 'idx:' . \DeepFind\CacheVersion::get() . ':' . $uid;
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
