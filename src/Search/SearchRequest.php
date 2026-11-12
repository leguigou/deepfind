<?php

declare(strict_types=1);

namespace DeepFind\Search;

use DeepFind\Import\Mapper;
use DeepFind\Settings;
use DeepFind\Text;

/** Paramètres d'une recherche, validés. */
final class SearchRequest
{
    public const SORTS = ['relevance', 'price_asc', 'price_desc', 'newest'];
    public const INTERNAL_SORTS = ['margin_desc', 'popularity_desc', 'score_desc'];

    public string $q = '';
    public string $qNorm = '';
    public string $lang = 'fr';
    public int $offset = 0;
    public int $limit = 20;
    /** @var list<string> */
    public array $brands = [];
    /** @var list<string> */
    public array $categories = [];
    /** @var array<string, list<string>> */
    public array $attrs = [];
    public ?int $inStock = null;
    public ?int $onSale = null;
    public ?float $priceMin = null;
    public ?float $priceMax = null;
    public ?float $marginMin = null;
    public string $sort = 'relevance';
    public bool $withPosts = true;

    public static function fromArray(array $p, bool $internal = false): self
    {
        $r = new self();
        $r->q = Text::truncate(trim((string) ($p['q'] ?? '')), 100);
        $r->qNorm = Text::normalize($r->q);
        $lang = (string) ($p['lang'] ?? 'fr');
        $r->lang = preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'fr';
        $r->offset = max(0, min(1000, (int) ($p['offset'] ?? 0)));
        $r->limit = max(0, min(max(1, Settings::int('max_limit')), (int) ($p['limit'] ?? 20)));
        $r->brands = self::list($p['brand'] ?? []);
        $r->categories = self::list($p['category'] ?? []);
        foreach ((array) ($p['attr'] ?? []) as $code => $values) {
            $code = Mapper::code((string) $code);
            $vals = self::list($values);
            if ($code !== '' && $vals !== []) {
                $r->attrs[$code] = $vals;
            }
        }
        $r->inStock = isset($p['in_stock']) && $p['in_stock'] !== '' ? ((int) $p['in_stock'] ? 1 : 0) : null;
        $r->onSale = isset($p['on_sale']) && $p['on_sale'] !== '' && (int) $p['on_sale'] ? 1 : null;
        $r->priceMin = isset($p['price_min']) && $p['price_min'] !== '' ? (float) $p['price_min'] : null;
        $r->priceMax = isset($p['price_max']) && $p['price_max'] !== '' ? (float) $p['price_max'] : null;
        $sorts = $internal ? [...self::SORTS, ...self::INTERNAL_SORTS] : self::SORTS;
        $r->sort = in_array($p['sort'] ?? '', $sorts, true) ? (string) $p['sort'] : 'relevance';
        if ($internal && isset($p['margin_min']) && $p['margin_min'] !== '') {
            $r->marginMin = (float) $p['margin_min'];
        }
        $r->withPosts = ($p['posts'] ?? '1') !== '0';
        return $r;
    }

    /** @return list<string> */
    private static function list(mixed $v): array
    {
        $vals = is_array($v) ? $v : (($v === '' || $v === null) ? [] : explode('||', (string) $v));
        $out = [];
        foreach ($vals as $x) {
            $x = Text::truncate(trim((string) $x), 120);
            if ($x !== '') {
                $out[] = $x;
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 30);
    }

    public function cacheKey(bool $internal): string
    {
        return md5(serialize([$this->qNorm, $this->q, $this->lang, $this->offset, $this->limit, $this->brands, $this->categories,
            $this->attrs, $this->inStock, $this->onSale, $this->priceMin, $this->priceMax, $this->marginMin, $this->sort, $this->withPosts, $internal]));
    }

    public function filtersSummary(): string
    {
        $f = array_filter([
            'brand' => $this->brands ?: null, 'category' => $this->categories ?: null, 'attr' => $this->attrs ?: null,
            'in_stock' => $this->inStock, 'on_sale' => $this->onSale, 'price_min' => $this->priceMin, 'price_max' => $this->priceMax,
            'sort' => $this->sort !== 'relevance' ? $this->sort : null,
        ], static fn ($v) => $v !== null);
        return $f === [] ? '' : Text::truncate(Text::json($f), 500);
    }
}
