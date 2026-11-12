<?php

declare(strict_types=1);

namespace DeepFind\Import;

use DeepFind\Text;
use SimpleXMLElement;

/**
 * Transforme un élément XML du flux en tableau de champs internes, selon le mapping JSON de la source.
 *
 * Syntaxe des chemins (relatifs à l'élément produit/article) :
 *   "name"                 élément enfant
 *   "prices/final"         sous-élément
 *   "@id"                  attribut de l'élément courant
 *   "category/@path"       attribut d'un enfant
 *   "."                    texte de l'élément courant
 *   tout autre chemin      expression XPath (ex. "g:price", "attribute[@code='color']")
 */
final class Mapper
{
    /** Champs internes produits => type */
    public const PRODUCT_FIELDS = [
        'id' => 'string', 'sku' => 'string', 'type' => 'string', 'parent_id' => 'string',
        'enabled' => 'bool', 'name' => 'text', 'brand' => 'text', 'url' => 'url', 'image' => 'url',
        'image_hover' => 'url', 'short_description' => 'html', 'description' => 'html', 'ean' => 'string',
        'price' => 'float', 'final_price' => 'float', 'in_stock' => 'bool', 'salable_qty' => 'int',
        'margin_rate' => 'float', 'sales_30d' => 'int', 'created_at' => 'date', 'rating' => 'float',
        'reviews_count' => 'int', 'search_keywords' => 'text', 'manual_boost' => 'int',
    ];

    public const VARIANT_FIELDS = [
        'id' => 'string', 'sku' => 'string', 'ean' => 'string', 'url' => 'url', 'image' => 'url',
        'price' => 'float', 'final_price' => 'float', 'in_stock' => 'bool', 'salable_qty' => 'int',
    ];

    public const POST_FIELDS = [
        'id' => 'string', 'enabled' => 'bool', 'title' => 'text', 'url' => 'url', 'image' => 'url',
        'excerpt' => 'html', 'content' => 'html', 'author' => 'text', 'published_at' => 'date',
        'updated_at' => 'date', 'manual_boost' => 'int', 'categories' => 'list', 'tags' => 'list',
        'related_skus' => 'list',
    ];

    private const SIMPLE_PATH = '/^(?:@[\w\-]+|[\w\-.]+(?:\/[\w\-.]+)*(?:\/@[\w\-]+)?)$/';

    /** @param array<string,mixed> $mapping */
    public function __construct(private readonly array $mapping, private readonly string $kind = 'products')
    {
    }

    public function mapping(): array
    {
        return $this->mapping;
    }

    public function itemElement(): string
    {
        return (string) ($this->mapping['item_element'] ?? ($this->kind === 'posts' ? 'post' : 'product'));
    }

    public function deleteElement(): string
    {
        return (string) ($this->mapping['delete_element'] ?? 'deleted');
    }

    public function variantMode(): string
    {
        return ($this->mapping['variant_mode'] ?? 'nested') === 'flat' ? 'flat' : 'nested';
    }

    public function parentMatch(): string
    {
        return ($this->mapping['parent_match'] ?? 'id') === 'sku' ? 'sku' : 'id';
    }

    /**
     * @param bool $partial  true = flux delta : seuls les champs présents dans le XML sont retournés
     * @return array{fields: array<string,mixed>, categories: list<array>, attributes: array<string,array>, variants: list<array>}
     */
    public function mapProduct(SimpleXMLElement $el, bool $partial = false): array
    {
        $out = [
            'fields' => $this->mapFields($el, $this->mapping['fields'] ?? [], self::PRODUCT_FIELDS, $partial),
            'categories' => $this->mapCategories($el),
            'attributes' => $this->mapAttributes($el, $this->mapping['attributes'] ?? null, $this->mapping['extra_attributes'] ?? []),
            'variants' => [],
        ];
        $v = $this->mapping['variants'] ?? null;
        if ($this->variantMode() === 'nested' && is_array($v) && !empty($v['path'])) {
            foreach (self::nodes($el, (string) $v['path']) as $vel) {
                $out['variants'][] = [
                    'fields' => $this->mapFields($vel, $v['fields'] ?? [], self::VARIANT_FIELDS, $partial),
                    'attributes' => $this->mapAttributes($vel, $v['attributes'] ?? null, $v['extra_attributes'] ?? []),
                ];
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function mapPost(SimpleXMLElement $el): array
    {
        return $this->mapFields($el, $this->mapping['fields'] ?? [], self::POST_FIELDS, false);
    }

    /** @return list<string> SKU listés dans un bloc <deleted> */
    public function deletedSkus(SimpleXMLElement $el): array
    {
        $path = (string) ($this->mapping['delete_sku_path'] ?? 'sku');
        return array_values(array_filter(array_map('trim', self::values($el, $path)), static fn ($s) => $s !== ''));
    }

    /**
     * @param array<string,mixed> $map
     * @param array<string,string> $types
     * @return array<string,mixed>
     */
    private function mapFields(SimpleXMLElement $el, array $map, array $types, bool $partial): array
    {
        $out = [];
        foreach ($types as $field => $type) {
            $conf = $map[$field] ?? null;
            if ($conf === null) {
                continue;
            }
            if (is_string($conf)) {
                $conf = ['path' => $conf];
            }
            if (isset($conf['value'])) {
                $raw = [(string) $conf['value']];
            } else {
                $raw = self::values($el, (string) ($conf['path'] ?? $field));
            }
            if ($raw === []) {
                // Élément absent du flux : champ non renseigné (valeur par défaut appliquée plus tard).
                continue;
            }
            $transforms = (array) ($conf['transform'] ?? []);
            if ($type === 'list' || !empty($conf['multiple'])) {
                $vals = [];
                foreach ($raw as $r) {
                    $t = self::transform($transforms, $r);
                    foreach ((array) $t as $x) {
                        $x = trim((string) $x);
                        if ($x !== '') {
                            $vals[] = $x;
                        }
                    }
                }
                $out[$field] = array_values(array_unique($vals));
                continue;
            }
            $value = self::transform($transforms, $raw[0] ?? null);
            if (is_array($value)) {
                $value = implode(' ', $value);
            }
            $out[$field] = self::cast($type, $value);
        }
        return $out;
    }

    /** @return list<array{name:string, path:list<string>, id:string}> */
    private function mapCategories(SimpleXMLElement $el): array
    {
        $c = $this->mapping['categories'] ?? null;
        if (!is_array($c) || empty($c['path'])) {
            return [];
        }
        $sep = (string) ($c['separator'] ?? '>');
        $out = [];
        foreach (self::nodes($el, (string) $c['path']) as $node) {
            $name = trim(implode(' ', self::values($node, (string) ($c['name_path'] ?? '.'))));
            $pathStr = !empty($c['path_path']) ? trim(implode(' ', self::values($node, (string) $c['path_path']))) : '';
            if ($pathStr === '' && $name !== '' && str_contains($name, $sep)) {
                $pathStr = $name;
            }
            $segments = $pathStr !== '' ? array_values(array_filter(array_map('trim', explode($sep, $pathStr)), 'strlen')) : [];
            if ($name === '' || str_contains($name, $sep)) {
                $name = $segments === [] ? $name : (string) end($segments);
            }
            if ($name === '') {
                continue;
            }
            $id = !empty($c['id_path']) ? trim(implode('', self::values($node, (string) $c['id_path']))) : '';
            $out[] = ['name' => $name, 'path' => $segments ?: [$name], 'id' => $id];
        }
        return $out;
    }

    /**
     * @return array<string, array{label:string, filterable:bool, values:list<string>}>
     */
    private function mapAttributes(SimpleXMLElement $el, ?array $conf, array $extra): array
    {
        $out = [];
        if (is_array($conf) && !empty($conf['path'])) {
            $only = array_map('strval', (array) ($conf['only_codes'] ?? []));
            $exclude = array_map('strval', (array) ($conf['exclude_codes'] ?? []));
            $forceFilter = array_map('strval', (array) ($conf['filterable_codes'] ?? []));
            foreach (self::nodes($el, (string) $conf['path']) as $node) {
                $code = self::code(implode('', self::values($node, (string) ($conf['code_path'] ?? '@code'))));
                if ($code === '' || ($only !== [] && !in_array($code, $only, true)) || in_array($code, $exclude, true)) {
                    continue;
                }
                $value = trim(implode(' ', self::values($node, (string) ($conf['value_path'] ?? '.'))));
                if ($value === '') {
                    continue;
                }
                $label = trim(implode(' ', self::values($node, (string) ($conf['label_path'] ?? '@label')))) ?: $code;
                $filt = implode('', self::values($node, (string) ($conf['filterable_path'] ?? '@filterable')));
                $filterable = in_array($code, $forceFilter, true) || in_array(strtolower($filt), ['1', 'true', 'yes', 'oui'], true);
                self::addAttr($out, $code, $label, $filterable, $value);
            }
        }
        foreach ($extra as $code => $ec) {
            $code = self::code((string) $code);
            if ($code === '' || !is_array($ec)) {
                continue;
            }
            foreach (self::values($el, (string) ($ec['path'] ?? $code)) as $raw) {
                foreach ((array) self::transform((array) ($ec['transform'] ?? []), $raw) as $value) {
                    $value = trim((string) $value);
                    if ($value !== '') {
                        self::addAttr($out, $code, (string) ($ec['label'] ?? $code), !empty($ec['filterable']), $value);
                    }
                }
            }
        }
        return $out;
    }

    private static function addAttr(array &$out, string $code, string $label, bool $filterable, string $value): void
    {
        if (!isset($out[$code])) {
            $out[$code] = ['label' => $label, 'filterable' => $filterable, 'values' => []];
        }
        $out[$code]['filterable'] = $out[$code]['filterable'] || $filterable;
        if (!in_array($value, $out[$code]['values'], true)) {
            $out[$code]['values'][] = $value;
        }
    }

    public static function code(string $s): string
    {
        $s = Text::normalize($s);
        return trim((string) preg_replace('/[^a-z0-9_]+/', '_', $s), '_');
    }

    /** @return list<SimpleXMLElement> */
    public static function nodes(SimpleXMLElement $el, string $path): array
    {
        if ($path === '.') {
            return [$el];
        }
        if (preg_match(self::SIMPLE_PATH, $path) && !str_contains($path, '@')) {
            $nodes = [$el];
            foreach (explode('/', $path) as $seg) {
                $next = [];
                foreach ($nodes as $n) {
                    foreach ($n->{$seg} as $child) {
                        $next[] = $child;
                    }
                }
                $nodes = $next;
                if ($nodes === []) {
                    break;
                }
            }
            return $nodes;
        }
        $res = @$el->xpath($path);
        return is_array($res) ? $res : [];
    }

    /** @return list<string> */
    public static function values(SimpleXMLElement $el, string $path): array
    {
        if ($path === '.') {
            return [trim((string) $el)];
        }
        if (preg_match(self::SIMPLE_PATH, $path)) {
            $attr = null;
            $at = strrpos($path, '@');
            if ($at !== false) {
                $attr = substr($path, $at + 1);
                $path = rtrim(substr($path, 0, $at), '/');
            }
            $nodes = $path === '' ? [$el] : self::nodes($el, $path);
            $out = [];
            foreach ($nodes as $n) {
                if ($attr !== null) {
                    if (isset($n[$attr])) {
                        $out[] = trim((string) $n[$attr]);
                    }
                } else {
                    $out[] = trim((string) $n);
                }
            }
            return $out;
        }
        $res = @$el->xpath($path);
        if (!is_array($res)) {
            return [];
        }
        return array_map(static fn ($n) => trim((string) $n), $res);
    }

    /**
     * Transformations disponibles : trim, lowercase, uppercase, ucfirst, strip_html, to_float, to_int, to_bool,
     * divide:N, multiply:N, default:X, split:SEP, prefix:X, suffix:X, replace:A|B, truncate:N, date_iso
     */
    public static function transform(array $transforms, ?string $value): mixed
    {
        $v = $value;
        foreach ($transforms as $t) {
            [$name, $arg] = array_pad(explode(':', (string) $t, 2), 2, null);
            if (is_array($v)) {
                $v = array_map(static fn ($x) => self::transform([$t], (string) $x), $v);
                continue;
            }
            $s = (string) $v;
            $v = match ($name) {
                'trim' => trim($s),
                'lowercase' => mb_strtolower($s, 'UTF-8'),
                'uppercase' => mb_strtoupper($s, 'UTF-8'),
                'ucfirst' => mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8'),
                'strip_html' => Text::stripHtml($s),
                'to_float' => self::toFloat($s),
                'to_int' => (int) round(self::toFloat($s)),
                'to_bool' => self::toBool($s),
                'divide' => self::toFloat($s) / ((float) $arg ?: 1.0),
                'multiply' => self::toFloat($s) * (float) $arg,
                'default' => trim($s) === '' ? (string) $arg : $s,
                'split' => array_values(array_filter(array_map('trim', explode((string) ($arg ?: ','), $s)), 'strlen')),
                'prefix' => trim($s) === '' ? $s : $arg . $s,
                'suffix' => trim($s) === '' ? $s : $s . $arg,
                'replace' => self::replace((string) $arg, $s),
                'truncate' => Text::truncate($s, max(1, (int) $arg)),
                'date_iso' => self::toTimestamp($s),
                default => throw new \InvalidArgumentException('Transformation inconnue : ' . $name),
            };
        }
        return $v;
    }

    private static function replace(string $arg, string $s): string
    {
        [$search, $replace] = array_pad(explode('|', $arg, 2), 2, '');
        return $search === '' ? $s : str_replace($search, $replace, $s);
    }

    public static function cast(string $type, mixed $v): mixed
    {
        return match ($type) {
            'float' => is_float($v) || is_int($v) ? (float) $v : self::toFloat((string) $v),
            'int' => is_int($v) ? $v : (int) round(is_float($v) ? $v : self::toFloat((string) $v)),
            'bool' => is_int($v) ? ($v ? 1 : 0) : self::toBool((string) $v),
            'date' => is_int($v) ? $v : self::toTimestamp((string) $v),
            'html' => Text::stripHtml((string) $v),
            'url' => self::url((string) $v),
            default => trim((string) $v),
        };
    }

    public static function toFloat(string $s): float
    {
        $s = trim(str_replace(["\u{00A0}", ' ', '€', 'EUR'], '', $s));
        if ($s === '') {
            return 0.0;
        }
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = strrpos($s, ',') > strrpos($s, '.') ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
        } else {
            $s = str_replace(',', '.', $s);
        }
        return (float) preg_replace('/[^0-9.\-]/', '', $s);
    }

    /** Valeurs reconnues comme « oui » / « en stock » (après minuscules, sans accents, _ et - remplacés par des espaces). */
    private const TRUE_VALUES = [
        '1', 'true', 'yes', 'oui', 'y', 'o', 'vrai', 'on', 'enabled', 'enable', 'active', 'actif', 'published', 'publie',
        // stock : Magento, Google Shopping (in_stock, preorder, backorder), schema.org (InStock, LimitedAvailability…), français
        'in stock', 'instock', 'en stock', 'available', 'available for order', 'disponible', 'dispo', 'en stock fournisseur',
        'limited availability', 'limitedavailability', 'online only', 'onlineonly', 'preorder', 'pre order', 'backorder', 'back order',
        'precommande', 'pre commande', 'sur commande', 'is in stock',
    ];

    public static function toBool(string $s): int
    {
        $s = trim($s);
        if (preg_match('#^https?://schema\.org/(\w+)$#i', $s, $m)) {
            $s = $m[1];
        }
        if (is_numeric($s)) {
            return (float) $s > 0 ? 1 : 0; // « 12 » (quantité) = en stock, « 0 » = rupture
        }
        $s = trim((string) preg_replace('/[\s_\-]+/', ' ', Text::normalize($s)));
        return in_array($s, self::TRUE_VALUES, true) ? 1 : 0;
    }

    public static function toTimestamp(string $s): int
    {
        $s = trim($s);
        if ($s === '') {
            return 0;
        }
        if (ctype_digit($s)) {
            return (int) $s;
        }
        $t = strtotime($s);
        return $t === false ? 0 : $t;
    }

    private static function url(string $s): string
    {
        $s = trim($s);
        return preg_match('#^https?://#i', $s) ? $s : '';
    }

    // ---------------------------------------------------------------- Mappings par défaut (format DeepFind)

    public static function defaultProducts(): array
    {
        return [
            'item_element' => 'product',
            'variant_mode' => 'nested',
            'parent_match' => 'id',
            'delete_element' => 'deleted',
            'delete_sku_path' => 'sku',
            'fields' => array_combine(array_keys(self::PRODUCT_FIELDS), array_map(static fn ($f) => ['path' => $f], array_keys(self::PRODUCT_FIELDS))),
            'categories' => ['path' => 'categories/category', 'name_path' => '.', 'path_path' => '@path', 'id_path' => '@id', 'separator' => '>'],
            'attributes' => ['path' => 'attributes/attribute', 'code_path' => '@code', 'label_path' => '@label', 'filterable_path' => '@filterable', 'value_path' => '.'],
            'extra_attributes' => new \stdClass(),
            'variants' => [
                'path' => 'variants/variant',
                'fields' => array_combine(array_keys(self::VARIANT_FIELDS), array_map(static fn ($f) => ['path' => $f], array_keys(self::VARIANT_FIELDS))),
                'attributes' => ['path' => 'attributes/attribute', 'code_path' => '@code', 'label_path' => '@label', 'filterable_path' => '@filterable', 'value_path' => '.'],
            ],
        ];
    }

    public static function defaultPosts(): array
    {
        $fields = [];
        foreach (array_keys(self::POST_FIELDS) as $f) {
            $fields[$f] = ['path' => $f];
        }
        $fields['categories'] = ['path' => 'categories/category'];
        $fields['tags'] = ['path' => 'tags/tag'];
        $fields['related_skus'] = ['path' => 'related_products/sku'];
        return ['item_element' => 'post', 'fields' => $fields];
    }
}
