<?php

declare(strict_types=1);

namespace DeepFind\Import;

use DeepFind\Settings;
use DeepFind\Text;

/**
 * Construit le document indexé à partir d'un produit parent (ou simple) et de ses enfants.
 * Les champs calculés (prix « à partir de », stock agrégé, promo, score métier) sont regroupés
 * dans computeDerived() pour être recalculés lors des imports delta.
 */
final class ProductBuilder
{
    /** @var array<string, array{label:string, filterable:bool}> attributs rencontrés pendant l'import */
    public array $attributeMeta = [];

    /** @var array<string,float> */
    private array $w;

    /**
     * @param array<string,int>|null $boosts boosts saisis dans l'extranet (SKU majuscules => -10..10)
     * @param array<string,int>|null $clicks clics sur 30 jours (identifiant de document => nombre)
     */
    public function __construct(?array $weights = null, private readonly ?int $now = null, private readonly ?array $boosts = null, private readonly ?array $clicks = null)
    {
        $this->w = $weights ?? self::weightsFromSettings();
    }

    /** Builder pour une langue : charge les boosts de l'extranet et les clics des 30 derniers jours. */
    public static function forLang(string $lang): self
    {
        return new self(null, null, \DeepFind\Rules\Boosts::active($lang), \DeepFind\Rules\Boosts::clicks($lang));
    }

    /** Applique les signaux extranet (boost, clics) à un document existant ; sans effet si non chargés. */
    public function applySignals(array $doc): array
    {
        if ($this->boosts !== null) {
            $doc['admin_boost'] = $this->boosts[mb_strtoupper((string) $doc['sku'], 'UTF-8')] ?? 0;
        }
        if ($this->clicks !== null) {
            $doc['clicks_30d'] = $this->clicks[(string) $doc['id']] ?? 0;
        }
        return $doc;
    }

    public static function weightsFromSettings(): array
    {
        return [
            'stock' => Settings::float('w_stock'),
            'margin' => Settings::float('w_margin'),
            'popularity' => Settings::float('w_popularity'),
            'new' => Settings::float('w_new'),
            'boost' => Settings::float('w_manual_boost'),
            'clicks' => Settings::float('w_clicks'),
            'clicks_ref' => max(1.0, Settings::float('clicks_ref')),
            'popularity_ref' => max(1.0, Settings::float('popularity_ref')),
            'new_days' => max(0.0, Settings::float('new_days')),
        ];
    }

    public static function docId(string $id, string $sku): string
    {
        $base = $id !== '' ? $id : $sku;
        $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $base) ?? '';
        if ($clean !== $base || strlen($clean) > 400) {
            $clean = substr($clean, 0, 60) . '_' . substr(md5($base), 0, 10);
        }
        return 'p' . $clean;
    }

    /**
     * @param array{fields:array, categories:list<array>, attributes:array} $parent
     * @param list<array{fields:array, attributes:array}> $variants
     * @return array<string,mixed>|string document ou motif de rejet
     */
    public function build(array $parent, array $variants): array|string
    {
        $f = $parent['fields'];
        if (isset($f['enabled']) && (int) $f['enabled'] === 0) {
            return 'désactivé';
        }
        $sku = trim((string) ($f['sku'] ?? ''));
        $name = trim((string) ($f['name'] ?? ''));
        if ($sku === '') {
            return 'SKU manquant';
        }
        if ($name === '') {
            return 'nom manquant';
        }
        if (($f['url'] ?? '') === '') {
            return 'URL manquante ou invalide';
        }

        $attr = [];
        $this->mergeAttributes($attr, $parent['attributes'] ?? []);

        $vars = [];
        foreach ($variants as $v) {
            $vf = $v['fields'];
            if (isset($vf['enabled']) && (int) $vf['enabled'] === 0) {
                continue;
            }
            $vsku = trim((string) ($vf['sku'] ?? ''));
            if ($vsku === '') {
                continue;
            }
            $vattr = [];
            $this->mergeAttributes($vattr, $v['attributes'] ?? []);
            $this->mergeAttributes($attr, $v['attributes'] ?? []);
            $vars[] = [
                'id' => (string) ($vf['id'] ?? ''),
                'sku' => $vsku,
                'ean' => (string) ($vf['ean'] ?? ''),
                'url' => (string) ($vf['url'] ?? ''),
                'image' => (string) ($vf['image'] ?? ''),
                'price' => round((float) ($vf['price'] ?? 0), 2),
                'final_price' => round((float) ($vf['final_price'] ?? 0), 2),
                'in_stock' => (int) ($vf['in_stock'] ?? 1),
                'salable_qty' => (int) ($vf['salable_qty'] ?? 0),
                'attrs' => $vattr,
            ];
        }

        $cats = $parent['categories'] ?? [];
        $catNames = $catPaths = $catTerms = $catIds = [];
        foreach ($cats as $c) {
            $catNames[] = $c['name'];
            $catPaths[] = implode(' > ', $c['path']);
            foreach ($c['path'] as $seg) {
                $catTerms[] = $seg;
            }
            if ($c['id'] !== '') {
                $catIds[] = $c['id'];
            }
        }

        $attrValues = [];
        foreach ($attr as $values) {
            foreach ($values as $val) {
                $attrValues[] = $val;
            }
        }

        $doc = [
            'id' => self::docId((string) ($f['id'] ?? ''), $sku),
            'sku' => $sku,
            'type' => (string) ($f['type'] ?? ($vars ? 'configurable' : 'simple')),
            'name' => $name,
            'brand' => trim((string) ($f['brand'] ?? '')),
            'url' => (string) $f['url'],
            'image' => (string) ($f['image'] ?? ''),
            'image_hover' => (string) ($f['image_hover'] ?? ''),
            'short_description' => Text::truncate((string) ($f['short_description'] ?? ''), 500),
            'description' => Text::truncate((string) ($f['description'] ?? ''), 3000),
            'ean' => (string) ($f['ean'] ?? ''),
            'category_names' => array_values(array_unique($catNames)),
            'category_paths' => array_values(array_unique($catPaths)),
            'category_terms' => array_values(array_unique($catTerms)),
            'category_ids' => array_values(array_unique($catIds)),
            'search_keywords' => (string) ($f['search_keywords'] ?? ''),
            'raw_price' => round((float) ($f['price'] ?? 0), 2),
            'raw_final_price' => round((float) ($f['final_price'] ?? 0), 2),
            'raw_in_stock' => (int) ($f['in_stock'] ?? 1),
            'raw_salable_qty' => (int) ($f['salable_qty'] ?? 0),
            'margin_rate' => max(-1.0, min(1.0, (float) ($f['margin_rate'] ?? 0))),
            'sales_30d' => max(0, (int) ($f['sales_30d'] ?? 0)),
            'created_ts' => (int) ($f['created_at'] ?? 0),
            'rating' => round((float) ($f['rating'] ?? 0), 1),
            'reviews_count' => (int) ($f['reviews_count'] ?? 0),
            'manual_boost' => max(-10, min(10, (int) ($f['manual_boost'] ?? 0))),
            'attr' => $attr === [] ? new \stdClass() : $attr,
            'attr_values' => array_values(array_unique($attrValues)),
            'variants' => $vars,
        ];

        $doc = $this->computeDerived($this->applySignals($doc));
        if ($doc['final_price'] <= 0) {
            return 'prix manquant ou nul';
        }
        return $doc;
    }

    /** @param array<string,mixed> $doc */
    public function computeDerived(array $doc): array
    {
        $vars = $doc['variants'] ?? [];
        $rawFinal = (float) ($doc['raw_final_price'] ?: $doc['raw_price']);
        $rawPrice = (float) ($doc['raw_price'] ?: $rawFinal);

        if ($vars !== []) {
            $inStock = 0;
            $qty = 0;
            $priced = [];
            foreach ($vars as $i => $v) {
                $final = (float) ($v['final_price'] ?: $v['price']);
                if ($final <= 0) {
                    $final = $rawFinal;
                }
                $vars[$i]['final_price'] = round($final, 2);
                if ((float) $v['price'] <= 0) {
                    $vars[$i]['price'] = round($final, 2);
                }
                $inStock = max($inStock, (int) $v['in_stock']);
                $qty += max(0, (int) $v['salable_qty']);
                if ($final > 0) {
                    $priced[] = $vars[$i];
                }
            }
            $pool = array_values(array_filter($priced, static fn ($v) => (int) $v['in_stock'] === 1)) ?: $priced;
            if ($pool !== []) {
                usort($pool, static fn ($a, $b) => $a['final_price'] <=> $b['final_price']);
                $final = (float) $pool[0]['final_price'];
                $price = max((float) $pool[0]['price'], $final);
                $max = max(array_column($pool, 'final_price'));
            } else {
                $final = $rawFinal;
                $price = $rawPrice;
                $max = $final;
            }
            $doc['variants'] = $vars;
            $doc['in_stock'] = $inStock;
            $doc['salable_qty'] = $qty;
        } else {
            $final = $rawFinal;
            $price = max($rawPrice, $final);
            $max = $final;
            $doc['in_stock'] = (int) $doc['raw_in_stock'];
            $doc['salable_qty'] = (int) $doc['raw_salable_qty'];
        }

        $doc['final_price'] = round($final, 2);
        $doc['price'] = round($price, 2);
        $doc['price_max'] = round((float) $max, 2);
        $doc['has_price_range'] = $doc['price_max'] - $doc['final_price'] > 0.005 ? 1 : 0;
        $doc['sort_price'] = $doc['final_price'];
        $doc['on_sale'] = $doc['price'] - $doc['final_price'] > 0.005 ? 1 : 0;
        $doc['discount_percent'] = $doc['on_sale'] && $doc['price'] > 0 ? (int) round((1 - $doc['final_price'] / $doc['price']) * 100) : 0;

        $skus = [mb_strtoupper($doc['sku'], 'UTF-8')];
        $eans = $doc['ean'] !== '' ? [$doc['ean']] : [];
        foreach ($doc['variants'] as $v) {
            $skus[] = mb_strtoupper($v['sku'], 'UTF-8');
            if ($v['ean'] !== '') {
                $eans[] = $v['ean'];
            }
        }
        $doc['all_skus'] = array_values(array_unique($skus));
        $doc['all_eans'] = array_values(array_unique($eans));

        $now = $this->now ?? time();
        $doc['is_new'] = $doc['created_ts'] > 0 && ($now - $doc['created_ts']) < $this->w['new_days'] * 86400 ? 1 : 0;
        $pop = $doc['sales_30d'] > 0 ? min(1.0, log(1 + $doc['sales_30d']) / log(1 + $this->w['popularity_ref'])) : 0.0;
        $clicks = (int) ($doc['clicks_30d'] ?? 0);
        $clickPop = $clicks > 0 ? min(1.0, log(1 + $clicks) / log(1 + ($this->w['clicks_ref'] ?? 50.0))) : 0.0;
        // Boost total = boost du flux + boost saisi dans l'extranet, borné à -10..+10
        $doc['boost_total'] = max(-10, min(10, (int) $doc['manual_boost'] + (int) ($doc['admin_boost'] ?? 0)));
        $doc['biz_score'] = round(
            $this->w['stock'] * $doc['in_stock']
            + $this->w['margin'] * max(0.0, (float) $doc['margin_rate'])
            + $this->w['popularity'] * $pop
            + ($this->w['clicks'] ?? 0.0) * $clickPop
            + $this->w['new'] * $doc['is_new']
            + $this->w['boost'] * $doc['boost_total'],
            3
        );
        $doc['updated_ts'] = $now;
        return $doc;
    }

    private function mergeAttributes(array &$target, array $attrs): void
    {
        foreach ($attrs as $code => $a) {
            $this->attributeMeta[$code] ??= ['label' => $a['label'], 'filterable' => false];
            $this->attributeMeta[$code]['filterable'] = $this->attributeMeta[$code]['filterable'] || $a['filterable'];
            foreach ($a['values'] as $val) {
                $target[$code] ??= [];
                if (!in_array($val, $target[$code], true)) {
                    $target[$code][] = $val;
                }
            }
        }
    }
}
