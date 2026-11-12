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

    /**
     * @return array{item:string, candidates:array<string,int>, scanned:int, paths:array, codes:array}
     */
    public static function inspect(string $file, ?string $itemElement = null, int $max = 300): array
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
                'coverage' => (int) round(100 * $info['n'] / $scanned),
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
            if ($o['coverage'] < 0) {
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
