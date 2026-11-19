<?php

declare(strict_types=1);

namespace DeepFind\Import;

use DeepFind\Text;
use XMLReader;

/**
 * Analyse un flux XML pour le mapping visuel : élément « produit » probable, chemins disponibles
 * (éléments, attributs XML), groupes répétés (variantes, catégories…) et attributs identifiés par un code
 * (ex. <attribute code="couleur">Noir</attribute> => attributes/attribute[@code='couleur']).
 */
final class FeedInspector
{
    private const CODE_ATTRS = ['code', 'attribute_code', 'name', 'key', 'id'];

    /** @var array<string, array{n:int, max:int, leaf:bool, samples:list<string>}> */
    private array $paths = [];
    /** @var array<string, array<string, array<string, string>>> */
    private array $codes = [];

    /** Parcours complet du fichier : au plus N secondes (au-delà, résultat partiel signalé). */
    public const FULL_SCAN_SECONDS = 60;

    /**
     * Analyse détaillée (exemples, groupes répétés, attributs par code) des $max premiers éléments, puis
     * parcours rapide de TOUT le fichier : chaque balise est listée même si elle n'apparaît (ou n'est remplie)
     * que plus loin, ou si elle est vide ; taux de présence calculé sur tout le flux. Résultat mis en cache
     * tant que le fichier ne change pas.
     * @return array{item:string, candidates:array<string,int>, scanned:int, total:int, partial:bool, paths:array, codes:array}
     */
    public static function inspect(string $file, ?string $itemElement = null, int $max = 300, bool $full = true): array
    {
        $cache = null;
        if ($full && is_file($file)) {
            $cache = \DeepFind\Config::varDir('cache') . '/inspect_' . md5((string) realpath($file) . '|' . filesize($file) . '|' . filemtime($file) . '|' . $itemElement . '|' . $max) . '.json';
            $hit = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
            if (is_array($hit) && isset($hit['paths'], $hit['total'])) {
                return $hit;
            }
        }
        $r = self::inspectSample($file, $itemElement, $max);
        $r['total'] = $r['scanned'];
        $r['partial'] = false;
        if ($full && $r['scanned'] > 0) {
            $r = self::mergeFull($r, self::scanAll($file, $r['item']));
        }
        if ($cache !== null) {
            foreach (glob(dirname($cache) . '/inspect_*.json') ?: [] as $old) {
                if (filemtime($old) < time() - 86400) {
                    @unlink($old); // analyses de fichiers remplacés depuis
                }
            }
            @file_put_contents($cache, json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        }
        return $r;
    }

    /**
     * Parcours de tout le fichier sans construire d'arbre : chemins des balises et attributs XML de chaque élément produit,
     * nombre d'éléments où ils apparaissent, s'ils ont des sous-éléments, et un exemple de valeur non vide.
     * @return array{total:int, partial:bool, paths:array<string, array{n:int, max:int, leaf:bool, sample:string}>}
     */
    public static function scanAll(string $file, string $item, int $seconds = self::FULL_SCAN_SECONDS): array
    {
        $uri = XmlItemReader::isGzip($file) ? 'compress.zlib://' . $file : $file;
        $prev = libxml_use_internal_errors(true);
        $r = new XMLReader();
        $paths = [];
        $total = 0;
        $partial = false;
        if (!$r->open($uri, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            libxml_use_internal_errors($prev);
            return ['total' => 0, 'partial' => true, 'paths' => []];
        }
        $deadline = microtime(true) + $seconds;
        $base = -1;          // profondeur de l'élément produit en cours (-1 : hors produit)
        $stack = [];         // chemins des éléments ouverts dans le produit, par profondeur relative
        $local = [];         // occurrences dans le produit en cours
        $finish = static function () use (&$local, &$paths, &$total): void {
            foreach ($local as $p => $c) {
                $paths[$p]['n'] = ($paths[$p]['n'] ?? 0) + 1;
                $paths[$p]['max'] = max($paths[$p]['max'] ?? 0, $c);
            }
            $local = [];
            $total++;
        };
        $note = static function (string $p) use (&$paths, &$local): void {
            $paths[$p] ??= ['n' => 0, 'max' => 0, 'leaf' => true, 'sample' => ''];
            $local[$p] = ($local[$p] ?? 0) + 1;
        };
        while (@$r->read()) {
            if (($total & 255) === 0 && microtime(true) > $deadline) {
                $partial = true;
                break;
            }
            $type = $r->nodeType;
            if ($base < 0) {
                if ($type === XMLReader::ELEMENT && $r->localName === $item) {
                    $base = $r->depth;
                    $stack = [];
                    if ($r->hasAttributes) {
                        while ($r->moveToNextAttribute()) {
                            if (str_starts_with($r->name, 'xmlns')) {
                                continue; // déclaration d'espace de noms, pas un champ
                            }
                            $note('@' . $r->name);
                            self::keepSample($paths['@' . $r->name], $r->value);
                        }
                        $r->moveToElement();
                    }
                    if ($r->isEmptyElement) {
                        $finish();
                        $base = -1;
                    }
                }
                continue;
            }
            $rel = $r->depth - $base;
            if ($type === XMLReader::END_ELEMENT && $rel === 0) {
                $finish();
                $base = -1;
                continue;
            }
            if ($type === XMLReader::ELEMENT) {
                if ($rel > 5) {
                    continue; // même profondeur que l'analyse détaillée
                }
                $parent = $stack[$rel - 1] ?? '';
                if ($parent !== '') {
                    $paths[$parent]['leaf'] = false; // le parent a des sous-éléments : ce n'est pas un champ
                }
                $p = ($parent === '' ? '' : $parent . '/') . $r->name;
                $stack[$rel] = $p;
                foreach (array_keys($stack) as $k) {
                    if ($k > $rel) {
                        unset($stack[$k]); // niveaux plus profonds de l'élément précédent
                    }
                }
                $note($p);
                if ($r->hasAttributes) {
                    while ($r->moveToNextAttribute()) {
                        if (str_starts_with($r->name, 'xmlns')) {
                            continue;
                        }
                        $ap = $p . '/@' . $r->name;
                        $note($ap);
                        self::keepSample($paths[$ap], $r->value);
                    }
                    $r->moveToElement();
                }
            } elseif (($type === XMLReader::TEXT || $type === XMLReader::CDATA) && isset($stack[$rel - 1])) {
                self::keepSample($paths[$stack[$rel - 1]], $r->value);
            }
        }
        $r->close();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return ['total' => $total, 'partial' => $partial, 'paths' => $paths];
    }

    private static function keepSample(array &$info, string $value): void
    {
        if ($info['sample'] === '') {
            $info['sample'] = Text::truncate(trim((string) preg_replace('/\s+/u', ' ', $value)), 80);
        }
    }

    /** Ajoute à l'analyse détaillée les balises trouvées dans tout le fichier ; taux de présence recalculé sur tout le flux. */
    private static function mergeFull(array $r, array $full): array
    {
        if ($full['total'] === 0) {
            return $r;
        }
        foreach ($full['paths'] as $p => $f) {
            if (isset($r['paths'][$p])) {
                $r['paths'][$p]['max'] = max($r['paths'][$p]['max'], $f['max']);
                $r['paths'][$p]['leaf'] = $r['paths'][$p]['leaf'] && $f['leaf'];
                if ($r['paths'][$p]['samples'] === [] && $f['sample'] !== '') {
                    $r['paths'][$p]['samples'][] = $f['sample'];
                }
            } else {
                $r['paths'][$p] = ['n' => 0, 'max' => $f['max'], 'leaf' => $f['leaf'], 'samples' => $f['sample'] !== '' ? [$f['sample']] : []];
            }
            $r['paths'][$p]['full_n'] = $f['n'];
        }
        ksort($r['paths'], SORT_NATURAL);
        $r['total'] = $full['total'];
        $r['partial'] = $full['partial'];
        return $r;
    }

    /** Analyse détaillée des $max premiers éléments. */
    private static function inspectSample(string $file, ?string $itemElement, int $max): array
    {
        $candidates = self::candidates($file);
        $item = $itemElement !== null && $itemElement !== '' && isset($candidates[$itemElement])
            ? $itemElement
            : (string) (array_key_first($candidates) ?? ($itemElement ?? 'product'));
        $self = new self();
        $scanned = 0;
        foreach (XmlItemReader::items($file, [$item], $max) as [, $el]) {
            $dom = dom_import_simplexml($el);
            $local = [];
            $self->walk($dom, '', 0, $local);
            foreach ($local as $p => $count) {
                $self->paths[$p]['n'] = ($self->paths[$p]['n'] ?? 0) + 1;
                $self->paths[$p]['max'] = max($self->paths[$p]['max'] ?? 0, $count);
            }
            $scanned++;
        }
        ksort($self->paths, SORT_NATURAL);
        return ['item' => $item, 'candidates' => $candidates, 'scanned' => $scanned, 'paths' => $self->paths, 'codes' => $self->codes];
    }

    /**
     * Éléments les plus répétés aux niveaux 1 et 2 du document (le « produit » est en général le plus fréquent).
     * @return array<string,int>
     */
    public static function candidates(string $file, int $maxNodes = 50000): array
    {
        $uri = XmlItemReader::isGzip($file) ? 'compress.zlib://' . $file : $file;
        $prev = libxml_use_internal_errors(true);
        $r = new XMLReader();
        $counts = [];
        if ($r->open($uri, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            $seen = 0;
            while (@$r->read() && $seen < $maxNodes) {
                if ($r->nodeType === XMLReader::ELEMENT && ($r->depth === 1 || $r->depth === 2)) {
                    $counts[$r->name] = ($counts[$r->name] ?? 0) + 1;
                    $seen++;
                }
            }
            $r->close();
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        arsort($counts);
        return array_filter($counts, static fn ($n) => $n >= 1);
    }

    private function walk(\DOMElement $el, string $prefix, int $depth, array &$local): void
    {
        foreach ($el->attributes ?? [] as $a) {
            $p = $prefix . '@' . $a->nodeName;
            $local[$p] = ($local[$p] ?? 0) + 1;
            $this->sample($p, $a->value, true);
        }
        foreach ($el->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $p = $prefix . $child->nodeName;
            $local[$p] = ($local[$p] ?? 0) + 1;
            $hasElements = false;
            foreach ($child->childNodes as $c) {
                if ($c instanceof \DOMElement) {
                    $hasElements = true;
                    break;
                }
            }
            if (!$hasElements) {
                $this->sample($p, $child->textContent, true);
                $this->detectCode($p, $child);
                foreach ($child->attributes ?? [] as $a) {
                    $ap = $p . '/@' . $a->nodeName;
                    $local[$ap] = ($local[$ap] ?? 0) + 1;
                    $this->sample($ap, $a->value, true);
                }
            } else {
                $this->sample($p, '', false);
                if ($depth < 4) {
                    $this->walk($child, $p . '/', $depth + 1, $local);
                }
            }
        }
    }

    private function sample(string $path, string $value, bool $leaf): void
    {
        $this->paths[$path] ??= ['n' => 0, 'max' => 0, 'leaf' => $leaf, 'samples' => []];
        $this->paths[$path]['leaf'] = $this->paths[$path]['leaf'] && $leaf;
        $v = Text::truncate(trim((string) preg_replace('/\s+/u', ' ', $value)), 80);
        if ($v !== '' && count($this->paths[$path]['samples']) < 3 && !in_array($v, $this->paths[$path]['samples'], true)) {
            $this->paths[$path]['samples'][] = $v;
        }
    }

    private function detectCode(string $path, \DOMElement $el): void
    {
        foreach (self::CODE_ATTRS as $attr) {
            if (!$el->hasAttribute($attr)) {
                continue;
            }
            $code = trim($el->getAttribute($attr));
            if ($code === '' || strlen($code) > 60 || str_contains($code, "'")) {
                return;
            }
            if (!isset($this->codes[$path][$attr][$code]) && count($this->codes[$path][$attr] ?? []) < 200) {
                $this->codes[$path][$attr][$code] = Text::truncate(trim($el->textContent), 60);
            }
            return;
        }
    }

    // ------------------------------------------------------------------ Options pour les listes déroulantes

    /**
     * Chemins proposés pour un contexte : racine du produit ($base = '') ou intérieur d'un groupe (ex. variants/variant).
     * @return list<array{value:string, group:string, sample:string, coverage:int}>
     */
    public static function options(array $inspection, string $base = ''): array
    {
        $paths = $inspection['paths'];
        $scanned = max(1, $inspection['scanned']);
        $prefix = $base === '' ? '' : $base . '/';
        $repeatedGroups = self::repeatedGroups($inspection);
        $out = [];
        foreach ($paths as $p => $info) {
            if ($prefix !== '' && !str_starts_with($p, $prefix)) {
                continue;
            }
            $rel = substr($p, strlen($prefix));
            if ($rel === '' || !$info['leaf']) {
                continue;
            }
            $inGroup = false;
            foreach ($repeatedGroups as $g) {
                if ($g !== $base && str_starts_with($p, $g . '/') && ($base === '' || !str_starts_with($base, $g))) {
                    $inGroup = true;
                    break;
                }
            }
            $out[] = [
                'value' => $rel,
                'group' => str_contains($rel, '@') ? 'Attributs XML' : ($inGroup ? 'Dans un groupe répété' : 'Éléments'),
                'sample' => implode(' | ', $info['samples']),
                // présence sur tout le flux si parcouru en entier, sinon sur l'échantillon
                'coverage' => isset($info['full_n']) && ($inspection['total'] ?? 0) > 0
                    ? (int) min(100, max($info['full_n'] > 0 ? 1 : 0, round(100 * $info['full_n'] / $inspection['total'])))
                    : (int) round(100 * $info['n'] / $scanned),
                'empty' => $info['samples'] === [],
            ];
        }
        foreach ($inspection['codes'] as $p => $byAttr) {
            if ($prefix !== '' && !str_starts_with($p, $prefix)) {
                continue;
            }
            $rel = substr($p, strlen($prefix));
            foreach ($byAttr as $attr => $codes) {
                foreach ($codes as $code => $sample) {
                    $out[] = ['value' => $rel . "[@$attr='$code']", 'group' => 'Attributs par code (' . $rel . ')', 'sample' => $sample, 'coverage' => -1];
                }
            }
        }
        return $out;
    }

    /** @return list<string> éléments non terminaux répétés (candidats « variantes ») */
    public static function repeatedGroups(array $inspection): array
    {
        $g = [];
        foreach ($inspection['paths'] as $p => $info) {
            if (!$info['leaf'] && $info['max'] > 1) {
                $g[] = $p;
            }
        }
        return $g;
    }

    /** @return list<string> éléments terminaux répétés (candidats « catégories », « attributs ») */
    public static function repeatedLeaves(array $inspection): array
    {
        $g = [];
        foreach ($inspection['paths'] as $p => $info) {
            if ($info['leaf'] && $info['max'] > 1 && !str_contains($p, '@')) {
                $g[] = $p;
            }
        }
        return $g;
    }

    /** Pré-sélection : premier chemin dont le dernier segment correspond à un nom usuel du champ. */
    public static function suggest(array $synonyms, array $options): string
    {
        $index = [];
        foreach ($options as $o) {
            // attributs par code, et balises toujours vides dans le flux : jamais pré-sélectionnés
            if ($o['coverage'] < 0 || !empty($o['empty'])) {
                continue;
            }
            $last = strtolower((string) preg_replace('/^.*[\/:@]/', '', $o['value']));
            $score = substr_count($o['value'], '/') + ($o['group'] === 'Dans un groupe répété' ? 10 : 0) + (str_contains($o['value'], '@') ? 1 : 0);
            if (!isset($index[$last]) || $score < $index[$last][1]) {
                $index[$last] = [$o['value'], $score];
            }
        }
        foreach ($synonyms as $s) {
            if (isset($index[$s])) {
                return $index[$s][0];
            }
        }
        return '';
    }
}
