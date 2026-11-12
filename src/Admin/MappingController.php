<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Db;
use DeepFind\Import\FeedFetcher;
use DeepFind\Import\FeedInspector;
use DeepFind\Import\FieldCatalog;
use DeepFind\Import\Mapper;
use DeepFind\Text;

/**
 * Mapping visuel : le flux est analysé, chaque champ DeepFind (à gauche) est associé à un champ détecté
 * dans le flux (liste déroulante à droite). Le résultat est enregistré dans le mapping JSON de la source.
 */
final class MappingController
{
    private const TRANSFORMS = ['trim', 'lowercase', 'uppercase', 'ucfirst', 'strip_html', 'to_float', 'to_int', 'to_bool',
        'divide', 'multiply', 'default', 'split', 'prefix', 'suffix', 'replace', 'truncate', 'date_iso'];

    public function edit(): void
    {
        $source = Db::one('SELECT * FROM sources WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
        if ($source === null) {
            App::back('/admin/sources');
            return;
        }
        $kind = $source['kind'];
        $mapping = json_decode((string) $source['mapping'], true);
        if (!is_array($mapping)) {
            $mapping = $kind === 'posts' ? Mapper::defaultPosts() : Mapper::defaultProducts();
        }
        $vars = ['active' => 'sources', 'source' => $source, 'kind' => $kind, 'error' => null, 'insp' => null];
        try {
            $file = FeedFetcher::cachedOrFetch($source, 'full', isset($_GET['refresh']));
            $vars['feedFile'] = basename($file) . ' (' . round(filesize($file) / 1048576, 2) . ' Mo)';
            $wantedItem = (string) ($_GET['item'] ?? ($mapping['item_element'] ?? ''));
            $insp = FeedInspector::inspect($file, $wantedItem, 300);
        } catch (\Throwable $e) {
            $vars['error'] = 'Analyse du flux impossible : ' . $e->getMessage();
            View::render('source_mapping', $vars, 'Mapping visuel');
            return;
        }
        if ($insp['scanned'] === 0) {
            $vars['error'] = 'Aucun élément <' . $insp['item'] . '> trouvé : choisissez l\'élément qui représente un produit.';
        }

        $mode = in_array($_GET['mode'] ?? '', ['nested', 'flat'], true) ? $_GET['mode'] : (($mapping['variant_mode'] ?? 'nested') === 'flat' ? 'flat' : 'nested');
        $root = FeedInspector::options($insp, '');
        $groups = FeedInspector::repeatedGroups($insp);
        // Mode non encore choisi : « à plat » si le flux a un champ parent et aucun groupe répété.
        if ($kind === 'products' && !isset($_GET['mode']) && !isset($mapping['variant_mode']) && $groups === []
            && FeedInspector::suggest(['parent_id', 'parent', 'parent_sku', 'item_group_id', 'configurable_id'], $root) !== '') {
            $mode = 'flat';
        }

        // Variantes (mode imbriqué)
        $variantsPath = '';
        if ($kind === 'products' && $mode === 'nested') {
            $variantsPath = (string) ($_GET['variants'] ?? ($mapping['variants']['path'] ?? ''));
            if ($variantsPath !== '' && $variantsPath !== '-' && !in_array($variantsPath, $groups, true)) {
                $variantsPath = '';
            }
            if ($variantsPath === '' && !isset($_GET['variants'])) {
                foreach ($groups as $g) {
                    if (preg_match('/(variant|child|enfant|declinaison|simple)/i', $g)) {
                        $variantsPath = $g;
                        break;
                    }
                }
            }
            if ($variantsPath === '-') {
                $variantsPath = '';
            }
        }
        $varOpts = $variantsPath !== '' ? FeedInspector::options($insp, $variantsPath) : [];
        // Les champs internes aux variantes ne sont pas proposés pour le produit parent.
        if ($variantsPath !== '') {
            $root = array_values(array_filter($root, static fn ($o) => !str_starts_with($o['value'], $variantsPath . '/')));
        }

        $rows = static function (array $catalog, array $current, array $options): array {
            $out = [];
            foreach ($catalog as $field => $info) {
                $conf = $current[$field] ?? null;
                $path = is_string($conf) ? $conf : (string) ($conf['path'] ?? '');
                $transform = is_array($conf) ? implode(', ', (array) ($conf['transform'] ?? [])) : '';
                $auto = false;
                if ($path === '' && !isset($conf['value'])) {
                    $path = FeedInspector::suggest($info['synonyms'], $options);
                    $auto = $path !== '';
                }
                $out[$field] = $info + ['path' => $path, 'transform' => $transform, 'auto' => $auto, 'fixed' => is_array($conf) && isset($conf['value']) ? (string) $conf['value'] : null];
            }
            return $out;
        };
        $groupsFields = FieldCatalog::groups($kind, $mode);
        $current = $mapping['fields'] ?? [];
        // Un mapping par défaut dont les chemins n'existent pas dans ce flux : on repart des suggestions.
        $knownPaths = array_column($root, 'value');
        foreach ($current as $f => $c) {
            $p = is_string($c) ? $c : ($c['path'] ?? null);
            if ($p !== null && !in_array($p, $knownPaths, true) && !isset($c['value']) && self::isDefaultPath($kind, $f, $p)) {
                unset($current[$f]);
            }
        }
        $vars['required'] = $rows($groupsFields['required'], $current, $root);
        $vars['secondary'] = $rows($groupsFields['secondary'], $current, $root);

        $vcurrent = $mapping['variants']['fields'] ?? [];
        $vknown = array_column($varOpts, 'value');
        foreach ($vcurrent as $f => $c) {
            $p = is_string($c) ? $c : ($c['path'] ?? null);
            if ($p !== null && !in_array($p, $vknown, true) && $p === $f) {
                unset($vcurrent[$f]);
            }
        }
        $vars['variantRows'] = $variantsPath !== '' ? $rows(FieldCatalog::variants(), $vcurrent, $varOpts) : [];

        // Catégories : éléments répétés ou dont le nom évoque une catégorie
        $catCandidates = [];
        foreach ($insp['paths'] as $p => $info) {
            if ($info['leaf'] && !str_contains($p, '@') && ($info['max'] > 1 || preg_match('/categ|rayon|famille/i', $p))
                && ($variantsPath === '' || !str_starts_with($p, $variantsPath . '/'))) {
                $catCandidates[] = $p;
            }
        }
        $cat = $mapping['categories'] ?? [];
        $catPath = (string) ($cat['path'] ?? '');
        if (!in_array($catPath, $catCandidates, true)) {
            $catPath = '';
            foreach ($catCandidates as $c) {
                if (preg_match('/categ/i', $c)) {
                    $catPath = $c;
                    break;
                }
            }
        }
        $vars['catCandidates'] = $catCandidates;
        $vars['cat'] = [
            'path' => $catPath,
            'path_path' => (string) ($cat['path_path'] ?? ''),
            'id_path' => (string) ($cat['id_path'] ?? ''),
            'separator' => (string) ($cat['separator'] ?? '>'),
            'attrs' => self::attrsOf($insp, $catCandidates),
        ];

        // Attributs par code (bloc générique) pour le produit et pour les variantes
        $codeGroups = [];
        $vcodeGroups = [];
        foreach ($insp['codes'] as $p => $byAttr) {
            $codeAttr = (string) array_key_first($byAttr);
            $entry = ['path' => $p, 'code_attr' => $codeAttr, 'count' => count($byAttr[$codeAttr]), 'codes' => array_map('strval', array_keys($byAttr[$codeAttr])), 'attrs' => self::attrsOf($insp, [$p])[$p] ?? []];
            if ($variantsPath !== '' && str_starts_with($p, $variantsPath . '/')) {
                $entry['path'] = substr($p, strlen($variantsPath) + 1);
                $vcodeGroups[] = $entry;
            } else {
                $codeGroups[] = $entry;
            }
        }
        $vars['codeGroups'] = $codeGroups;
        $vars['vcodeGroups'] = $vcodeGroups;
        $vars['attrBlock'] = self::blockState($mapping['attributes'] ?? null, $codeGroups);
        $vars['vattrBlock'] = self::blockState($mapping['variants']['attributes'] ?? null, $vcodeGroups);

        $extraCurrent = (array) ($mapping['extra_attributes'] ?? []);
        if ($extraCurrent === [] && $codeGroups === []) {
            // Suggestion : champs simples ressemblant à des attributs filtrables (couleur, taille…)
            $used = array_merge(array_column($vars['required'], 'path'), array_column($vars['secondary'], 'path'));
            foreach ($root as $o) {
                $last = strtolower((string) preg_replace('/^.*[\/:@]/', '', $o['value']));
                if (!in_array($o['value'], $used, true) && $o['coverage'] >= 0
                    && preg_match('/^(color|colour|couleur|size|taille|pointure|gender|genre|sexe|material|matiere|usage|activite|sport)$/', $last)) {
                    $extraCurrent[$last] = ['path' => $o['value'], 'label' => self::attrLabel($last), 'filterable' => true];
                }
            }
        }
        $vars['extra'] = self::extraRows($extraCurrent);
        $vars['vextra'] = self::extraRows($mapping['variants']['extra_attributes'] ?? []);

        $vars = [
            'insp' => $insp, 'root' => $root, 'varOpts' => $varOpts, 'groups' => $groups, 'mode' => $mode,
            'variantsPath' => $variantsPath, 'parentMatch' => ($mapping['parent_match'] ?? 'id') === 'sku' ? 'sku' : 'id',
            'deleteElement' => (string) ($mapping['delete_element'] ?? 'deleted'),
        ] + $vars;
        View::render('source_mapping', $vars, 'Mapping visuel · ' . $source['code']);
    }

    public function save(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $source = Db::one('SELECT * FROM sources WHERE id = ?', [$id]);
        if ($source === null) {
            App::back('/admin/sources');
            return;
        }
        $kind = $source['kind'];
        $mapping = json_decode((string) $source['mapping'], true);
        if (!is_array($mapping)) {
            $mapping = $kind === 'posts' ? Mapper::defaultPosts() : Mapper::defaultProducts();
        }
        $mapping['item_element'] = self::clean((string) ($_POST['item_element'] ?? ''), 'product');
        $mode = ($_POST['variant_mode'] ?? 'nested') === 'flat' ? 'flat' : 'nested';

        $groups = FieldCatalog::groups($kind, $mode);
        $mapping['fields'] = self::fields($_POST['f'] ?? [], array_keys($groups['required'] + $groups['secondary']), $mapping['fields'] ?? [], $kind);

        if ($kind === 'products') {
            $mapping['variant_mode'] = $mode;
            $mapping['parent_match'] = ($_POST['parent_match'] ?? 'id') === 'sku' ? 'sku' : 'id';
            $vpath = trim((string) ($_POST['variants_path'] ?? ''));
            if ($mode === 'nested' && $vpath !== '') {
                $mapping['variants'] = [
                    'path' => $vpath,
                    'fields' => self::fields($_POST['v'] ?? [], array_keys(FieldCatalog::variants()), $mapping['variants']['fields'] ?? [], 'variants'),
                ];
                $vb = self::block($_POST['vattr'] ?? []);
                if ($vb !== null) {
                    $mapping['variants']['attributes'] = $vb;
                }
                $vx = self::extra($_POST['vextra'] ?? []);
                if ($vx !== []) {
                    $mapping['variants']['extra_attributes'] = $vx;
                }
            } else {
                unset($mapping['variants']);
            }
            $cp = trim((string) ($_POST['cat']['path'] ?? ''));
            if ($cp !== '') {
                $mapping['categories'] = array_filter([
                    'path' => $cp,
                    'name_path' => '.',
                    'path_path' => trim((string) ($_POST['cat']['path_path'] ?? '')),
                    'id_path' => trim((string) ($_POST['cat']['id_path'] ?? '')),
                    'separator' => trim((string) ($_POST['cat']['separator'] ?? '>')) ?: '>',
                ], static fn ($v) => $v !== '');
            } else {
                unset($mapping['categories']);
            }
            $b = self::block($_POST['attr'] ?? []);
            if ($b !== null) {
                $mapping['attributes'] = $b;
            } else {
                unset($mapping['attributes']);
            }
            $x = self::extra($_POST['extra'] ?? []);
            $mapping['extra_attributes'] = $x === [] ? new \stdClass() : $x;
        }

        Db::update('sources', ['mapping' => Text::json($mapping), 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);

        $missing = [];
        $f = $mapping['fields'];
        $req = $kind === 'posts' ? ['id', 'title', 'url', 'published_at'] : ['sku', 'name', 'url'];
        if ($kind === 'products' && $mode === 'flat') {
            $req[] = 'parent_id';
        }
        foreach ($req as $r) {
            if (!isset($f[$r])) {
                $missing[] = $r;
            }
        }
        if ($kind === 'products' && !isset($f['price']) && !isset($f['final_price'])) {
            $missing[] = 'price (ou final_price)';
        }
        if ($missing !== []) {
            Auth::flash('warn', 'Mapping enregistré mais incomplet : ' . implode(', ', $missing) . '. Les produits sans ces champs seront rejetés à l\'import.');
        } else {
            Auth::flash('ok', 'Mapping enregistré. Vérifiez le résultat avec « Tester », puis lancez un import complet.');
        }
        App::back('/admin/sources/mapping', ['id' => $id]);
    }

    // ------------------------------------------------------------------

    private static function fields(array $post, array $allowed, array $existing, string $kind): array
    {
        $out = [];
        foreach ($allowed as $field) {
            $row = $post[$field] ?? [];
            $path = trim((string) ($row['path'] ?? ''));
            if ($path === '') {
                // une valeur fixe définie en JSON (mode avancé) est conservée
                if (is_array($existing[$field] ?? null) && isset($existing[$field]['value'])) {
                    $out[$field] = $existing[$field];
                }
                continue;
            }
            $conf = ['path' => $path];
            $tr = self::transforms((string) ($row['transform'] ?? ''));
            if ($tr !== []) {
                $conf['transform'] = $tr;
            }
            if (FieldCatalog::isList($kind, $field)) {
                $conf['multiple'] = true;
            }
            $out[$field] = $conf;
        }
        return $out;
    }

    /** « to_float, divide:100 » => ['to_float', 'divide:100'] (une virgule dans un argument, ex. split:, est conservée). */
    public static function transforms(string $s): array
    {
        $parts = [];
        foreach (explode(',', $s) as $chunk) {
            $name = strtolower(trim(explode(':', ltrim($chunk), 2)[0]));
            if ($parts !== [] && !in_array($name, self::TRANSFORMS, true)) {
                $parts[count($parts) - 1] .= ',' . $chunk;
                continue;
            }
            $parts[] = $chunk;
        }
        $out = [];
        foreach ($parts as $p) {
            $p = ltrim($p);
            $name = strtolower(explode(':', $p, 2)[0]);
            if (in_array($name, self::TRANSFORMS, true)) {
                $out[] = str_contains($p, ':') ? $name . ':' . explode(':', $p, 2)[1] : $name;
            }
        }
        return $out;
    }

    private static function block(array $p): ?array
    {
        $path = trim((string) ($p['path'] ?? ''));
        if ($path === '') {
            return null;
        }
        return array_filter([
            'path' => $path,
            'code_path' => trim((string) ($p['code_path'] ?? '@code')) ?: '@code',
            'label_path' => trim((string) ($p['label_path'] ?? '')),
            'filterable_path' => trim((string) ($p['filterable_path'] ?? '')),
            'value_path' => '.',
            'filterable_codes' => array_values(array_filter(array_map([Mapper::class, 'code'], (array) ($p['filterable_codes'] ?? [])))),
        ], static fn ($v) => $v !== '' && $v !== []);
    }

    private static function extra(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $path = trim((string) ($r['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $label = trim((string) ($r['label'] ?? ''));
            $code = Mapper::code($label !== '' ? $label : (string) preg_replace('/^.*[\/:@]/', '', $path));
            if ($code === '') {
                continue;
            }
            $out[$code] = ['path' => $path, 'label' => $label !== '' ? $label : $code, 'filterable' => !empty($r['filterable'])];
        }
        return $out;
    }

    private static function extraRows(mixed $current): array
    {
        $rows = [];
        foreach ((array) $current as $code => $c) {
            if (is_array($c)) {
                $rows[] = ['path' => (string) ($c['path'] ?? $code), 'label' => (string) ($c['label'] ?? $code), 'filterable' => !empty($c['filterable'])];
            }
        }
        for ($i = 0; $i < 3; $i++) {
            $rows[] = ['path' => '', 'label' => '', 'filterable' => true];
        }
        return $rows;
    }

    private static function blockState(?array $current, array $groups): array
    {
        $paths = array_column($groups, 'path');
        $path = (string) ($current['path'] ?? '');
        if (!in_array($path, $paths, true)) {
            $path = $groups !== [] ? $groups[0]['path'] : '';
        }
        $g = $groups[array_search($path, $paths, true)] ?? null;
        $attrs = $g['attrs'] ?? [];
        $pick = static fn (array $names, string $cur) => $cur !== '' && in_array($cur, $attrs, true) ? $cur : (array_values(array_intersect($names, $attrs))[0] ?? '');
        return [
            'path' => $path,
            'code_path' => $g ? '@' . $g['code_attr'] : '@code',
            'label_path' => $pick(['@label', '@libelle', '@title', '@name'], (string) ($current['label_path'] ?? '')),
            'filterable_path' => $pick(['@filterable', '@filtrable', '@is_filterable', '@facet'], (string) ($current['filterable_path'] ?? '')),
            'filterable_codes' => (array) ($current['filterable_codes'] ?? []),
            'codes' => $g['codes'] ?? [],
        ];
    }

    /** @return array<string, list<string>> attributs XML (@x) présents sur chaque chemin */
    private static function attrsOf(array $insp, array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            $out[$p] = [];
            foreach (array_keys($insp['paths']) as $k) {
                if (str_starts_with($k, $p . '/@')) {
                    $out[$p][] = substr($k, strlen($p) + 1);
                }
            }
        }
        return $out;
    }

    private static function isDefaultPath(string $kind, string $field, string $path): bool
    {
        $def = $kind === 'posts' ? Mapper::defaultPosts() : Mapper::defaultProducts();
        $c = $def['fields'][$field] ?? null;
        return is_array($c) && ($c['path'] ?? null) === $path;
    }

    private static function attrLabel(string $code): string
    {
        return [
            'color' => 'Couleur', 'colour' => 'Couleur', 'couleur' => 'Couleur', 'size' => 'Taille', 'taille' => 'Taille',
            'pointure' => 'Pointure', 'gender' => 'Genre', 'genre' => 'Genre', 'sexe' => 'Genre', 'material' => 'Matière',
            'matiere' => 'Matière', 'usage' => 'Usage', 'activite' => 'Activité', 'sport' => 'Sport',
        ][$code] ?? ucfirst($code);
    }

    private static function clean(string $s, string $default): string
    {
        $s = trim($s);
        return preg_match('/^[\w\-.:]+$/u', $s) ? $s : $default;
    }
}
