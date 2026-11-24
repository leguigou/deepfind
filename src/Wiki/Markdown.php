<?php

declare(strict_types=1);

namespace DeepFind\Wiki;

/**
 * Markdown → HTML pour le wiki (docs/wiki), sans dépendance. Tout le texte est échappé : aucun HTML brut n'est accepté.
 * Pris en charge : titres # à ####, paragraphes, listes à puces / numérotées (imbriquées par indentation), blocs de code ```,
 * citations > (une citation commençant par ⚠️ / 💡 devient un encadré), tableaux | a | b |, ligne ---,
 * dans le texte : `code`, **gras**, *italique*, [lien](adresse) (http(s), /chemin, #ancre, ou chemin du wiki relatif),
 * ![image](nom.png) : capture de docs/wiki/_images ou docs/screenshots, servie par /admin/wiki/images/nom.png (png, jpg, webp).
 */
final class Markdown
{
    /** @var list<array{level:int, id:string, text:string}> titres de niveau 2 et 3 (sommaire) */
    public array $toc = [];
    private array $ids = [];

    /** Décalage des titres (1 : « # » devient h2), pour enchaîner plusieurs pages sous le titre d'une rubrique. */
    private int $shift = 0;

    /** @param string $base adresse de la page (liens relatifs du wiki) @param string $images adresse des images du wiki */
    public function __construct(private string $base = Wiki::BASE, private readonly string $images = Wiki::BASE . '/images')
    {
    }

    /** Page suivante d'une rubrique rendue d'un seul tenant : autre adresse de base, titres décalés (les identifiants restent uniques). */
    public function at(string $base, int $shift = 0): self
    {
        $this->base = $base;
        $this->shift = max(0, min(2, $shift));
        return $this;
    }

    /** Nom de fichier d'image accepté (seul le nom compte : aucun chemin n'est suivi). */
    public const IMAGE_NAME = '/^[a-z0-9][a-z0-9_-]{0,80}\.(png|jpe?g|webp)$/';

    public function render(string $md): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $md) ?: [];
        return $this->blocks($lines);
    }

    /** @param list<string> $lines */
    private function blocks(array $lines): string
    {
        $out = '';
        $n = count($lines);
        $i = 0;
        while ($i < $n) {
            $line = $lines[$i];
            if (trim($line) === '') {
                $i++;
                continue;
            }
            // bloc de code
            if (preg_match('/^\s*```\s*([\w+-]*)\s*$/', $line, $m)) {
                $code = [];
                $i++;
                while ($i < $n && !preg_match('/^\s*```\s*$/', $lines[$i])) {
                    $code[] = $lines[$i];
                    $i++;
                }
                $i++;
                $lang = $m[1] !== '' ? ' data-lang="' . self::e($m[1]) . '"' : '';
                $out .= '<pre class="wk-code"' . $lang . '><code>' . self::e(implode("\n", $code)) . "</code></pre>\n";
                continue;
            }
            // titre
            if (preg_match('/^(#{1,4})\s+(.+?)\s*#*\s*$/', $line, $m)) {
                $level = strlen($m[1]);
                $text = $m[2];
                $id = $this->id($text);
                if ($level === 2 || $level === 3) {
                    $this->toc[] = ['level' => $level, 'id' => $id, 'text' => self::plain($text)];
                }
                $h = min(6, $level + $this->shift);
                $out .= '<h' . $h . ' id="' . self::e($id) . '">' . $this->inline($text)
                    . ($level >= 2 ? ' <a class="wk-anchor" href="#' . self::e($id) . '" aria-label="Lien vers cette section">#</a>' : '') . '</h' . $h . ">\n";
                $i++;
                continue;
            }
            // séparateur
            if (preg_match('/^\s*(-{3,}|\*{3,})\s*$/', $line)) {
                $out .= "<hr>\n";
                $i++;
                continue;
            }
            // citation / encadré
            if (preg_match('/^\s*>/', $line)) {
                $quote = [];
                while ($i < $n && preg_match('/^\s*>\s?(.*)$/', $lines[$i], $m)) {
                    $quote[] = $m[1];
                    $i++;
                }
                $first = ltrim($quote[0] ?? '');
                $cls = str_starts_with($first, '⚠') ? ' class="wk-callout wk-warn"' : (str_starts_with($first, '💡') ? ' class="wk-callout wk-tip"' : '');
                $out .= '<blockquote' . $cls . '>' . $this->blocks($quote) . "</blockquote>\n";
                continue;
            }
            // tableau
            if (str_starts_with(ltrim($line), '|') && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-{2,}/', $lines[$i + 1])) {
                $head = self::cells($line);
                $aligns = array_map(static fn ($c) => str_ends_with(trim($c), ':') ? (str_starts_with(trim($c), ':') ? 'center' : 'right') : '', self::cells($lines[$i + 1]));
                $i += 2;
                $html = '<div class="wk-table"><table><thead><tr>';
                foreach ($head as $k => $c) {
                    $html .= '<th' . (($aligns[$k] ?? '') !== '' ? ' style="text-align:' . $aligns[$k] . '"' : '') . '>' . $this->inline($c) . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                while ($i < $n && str_starts_with(ltrim($lines[$i]), '|')) {
                    $html .= '<tr>';
                    foreach (self::cells($lines[$i]) as $k => $c) {
                        $html .= '<td' . (($aligns[$k] ?? '') !== '' ? ' style="text-align:' . $aligns[$k] . '"' : '') . '>' . $this->inline($c) . '</td>';
                    }
                    $html .= '</tr>';
                    $i++;
                }
                $out .= $html . "</tbody></table></div>\n";
                continue;
            }
            // liste
            if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $line)) {
                [$html, $i] = $this->listAt($lines, $i);
                $out .= $html;
                continue;
            }
            // paragraphe
            $para = [];
            while ($i < $n && trim($lines[$i]) !== '' && !preg_match('/^\s*(```|#{1,4}\s|>|\||([-*+]|\d+[.)])\s+)/', $lines[$i])) {
                $para[] = trim($lines[$i]);
                $i++;
            }
            if ($para === []) {
                // ligne inclassable (ex. « | » isolé) : texte simple
                $para[] = trim($lines[$i]);
                $i++;
            }
            $out .= '<p>' . $this->inline(implode(' ', $para)) . "</p>\n";
        }
        return $out;
    }

    /**
     * Liste (et sous-listes plus indentées) à partir de la ligne $i.
     * @param list<string> $lines @return array{0:string, 1:int}
     */
    private function listAt(array $lines, int $i): array
    {
        $n = count($lines);
        preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $lines[$i], $m);
        $indent = strlen($m[1]);
        $ordered = ctype_digit($m[2][0]);
        $html = $ordered ? '<ol>' : '<ul>';
        $item = null;
        while ($i < $n) {
            $line = $lines[$i];
            if (trim($line) === '') {
                // une ligne vide ne termine la liste que si la suivante n'en fait plus partie
                if ($i + 1 < $n && preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $lines[$i + 1], $mm) && strlen($mm[1]) >= $indent) {
                    $i++;
                    continue;
                }
                break;
            }
            if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $line, $mm)) {
                $ind = strlen($mm[1]);
                if ($ind < $indent) {
                    break;
                }
                if ($ind > $indent) {
                    [$sub, $i] = $this->listAt($lines, $i);
                    $item .= $sub;
                    continue;
                }
                if ($item !== null) {
                    $html .= '<li>' . $item . '</li>';
                }
                $item = $this->inline($mm[3]);
                $i++;
                continue;
            }
            // suite du texte de l'élément (ligne indentée) ; sinon fin de la liste
            if (preg_match('/^\s+\S/', $line) && $item !== null && !preg_match('/^\s*```/', $line)) {
                $item .= ' ' . $this->inline(trim($line));
                $i++;
                continue;
            }
            break;
        }
        if ($item !== null) {
            $html .= '<li>' . $item . '</li>';
        }
        return [$html . ($ordered ? '</ol>' : '</ul>') . "\n", $i];
    }

    /** Cellules d'une ligne de tableau (les | entre accents graves ne séparent pas). @return list<string> */
    private static function cells(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\||\|$/', '', $line) ?? $line;
        $cells = [];
        $cur = '';
        $code = false;
        $len = strlen($line);
        for ($k = 0; $k < $len; $k++) {
            $ch = $line[$k];
            if ($ch === '`') {
                $code = !$code;
            }
            if ($ch === '|' && !$code && ($k === 0 || $line[$k - 1] !== '\\')) {
                $cells[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        $cells[] = trim($cur);
        return array_map(static fn ($c) => str_replace('\|', '|', $c), $cells);
    }

    /** Texte d'une ligne : échappé, puis code, liens, gras, italique. */
    public function inline(string $text): string
    {
        $codes = [];
        // code en ligne : mis de côté avant tout autre traitement
        $text = (string) preg_replace_callback('/`([^`]+)`/', static function ($m) use (&$codes) {
            $codes[] = '<code>' . self::e($m[1]) . '</code>';
            return "\x01" . (count($codes) - 1) . "\x02";
        }, $text);
        $text = self::e($text);
        // images ![texte](capture.png) : seul le nom du fichier est gardé
        $text = (string) preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) {
            $name = strtolower(basename(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8')));
            if (!preg_match(self::IMAGE_NAME, $name)) {
                return $m[1];
            }
            return '<img class="wk-img" src="' . self::e(rtrim($this->images, '/') . '/' . $name) . '" alt="' . $m[1] . '" loading="lazy">';
        }, $text);
        // liens [texte](adresse)
        $text = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
            $href = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            $external = (bool) preg_match('#^https?://#i', $href);
            if (!$external && preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
                return $m[1]; // autre protocole (javascript:, data:, mailto:…) : texte simple
            }
            if (!$external && !preg_match('#^(/(?!/)|\#)#', $href)) {
                // lien relatif du wiki : « ../catalogue/imports » ou « imports »
                $href = self::resolve($this->base, $href);
            }
            if (!preg_match('#^(https?://|/(?!/)|\#)#i', $href)) {
                return $m[1];
            }
            return '<a href="' . self::e($href) . '"' . ($external ? ' target="_blank" rel="noopener"' : '') . '>' . $m[1] . '</a>';
        }, $text);
        // adresse seule entre chevrons : <https://…>
        $text = (string) preg_replace('#&lt;(https?://(?:[^\s&<>"\']|&amp;)+)&gt;#i', '<a href="$1" target="_blank" rel="noopener">$1</a>', $text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/', '<em>$1</em>', $text);
        return (string) preg_replace_callback("/\x01(\d+)\x02/", static fn ($m) => $codes[(int) $m[1]], $text);
    }

    /** Chemin relatif du wiki résolu par rapport à la page (base = /wiki/categorie/page). */
    private static function resolve(string $base, string $rel): string
    {
        $parts = explode('/', trim($base, '/'));
        array_pop($parts); // page courante
        foreach (explode('/', preg_replace('/\.md$/', '', $rel) ?? $rel) as $seg) {
            if ($seg === '..') {
                if (count($parts) > 1) {
                    array_pop($parts);
                }
            } elseif ($seg !== '.' && $seg !== '') {
                $parts[] = $seg;
            }
        }
        return '/' . implode('/', $parts);
    }

    private function id(string $text): string
    {
        $id = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', \DeepFind\Text::fold(self::plain($text))), '-')) ?: 'section';
        $base = $id;
        $k = 2;
        while (isset($this->ids[$id])) {
            $id = $base . '-' . $k++;
        }
        $this->ids[$id] = true;
        return $id;
    }

    /** Texte sans balisage Markdown (titres, sommaire, recherche). */
    public static function plain(string $md): string
    {
        $t = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $md);
        $t = str_replace(['**', '`'], '', $t);
        return trim((string) preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/', '$1', $t));
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
