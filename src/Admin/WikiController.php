<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Wiki\Markdown;
use DeepFind\Wiki\Wiki;

/** Aide › wiki (/admin/wiki, /admin/wiki/categorie/page ; /wiki y redirige) : réservé aux personnes connectées à l'extranet. */
final class WikiController
{
    public function show(string $path): void
    {
        if (str_starts_with($path, Wiki::BASE . '/images/')) {
            $this->image(substr($path, strlen(Wiki::BASE . '/images/')));
            return;
        }
        $node = Wiki::find($path);
        $top = null; // rubrique de premier niveau : toutes ses pages se suivent sur une seule page
        if ($node !== null && $node['type'] !== 'home') {
            $top = $node['trail'] === [] ? ($node['type'] === 'cat' ? $node : null) : Wiki::find($node['trail'][0]['path']);
        }
        if ($node === null) {
            http_response_code(404);
            $node = ['type' => 'missing', 'path' => $path, 'title' => 'Page introuvable', 'file' => null, 'children' => [], 'trail' => []];
            $sections = [['id' => 'p-missing', 'path' => $path, 'title' => $node['title'], 'eyebrow' => '', 'source' => null, 'updated' => null, 'toc' => [],
                'html' => '<h1>Page introuvable</h1><p>Cette page de l\'aide n\'existe pas (ou plus). Choisissez une rubrique à gauche, ou cherchez un mot.</p>']];
        } else {
            $md = new Markdown(url(Wiki::BASE), url(Wiki::BASE . '/images'));
            $list = $top !== null ? [$top, ...Wiki::flat($top['children'], [['title' => $top['title'], 'path' => $top['path']]])] : [$node];
            $sections = [];
            foreach ($list as $k => $n) {
                $md->at(url(rtrim($n['path'], '/') . ($n['type'] === 'page' ? '' : '/index')), $k === 0 ? 0 : 1);
                $before = count($md->toc);
                $html = $n['file'] !== null ? $md->render((string) file_get_contents($n['file']))
                    : '<h' . ($k === 0 ? 1 : 2) . '>' . htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8') . '</h' . ($k === 0 ? 1 : 2) . '>';
                $sections[] = ['id' => Wiki::anchor($n['path']), 'path' => $n['path'], 'title' => $n['title'], 'html' => $html,
                    'toc' => array_slice($md->toc, $before),
                    // sous-rubrique (ex. Carrousels) rappelée au-dessus du titre de ses pages
                    'eyebrow' => implode(' › ', array_column(array_slice($n['trail'] ?? [], 1), 'title')),
                    'source' => $n['file'] !== null ? substr($n['file'], strlen(APP_ROOT) + 1) : null,
                    'updated' => $n['file'] !== null ? (int) filemtime($n['file']) : null];
            }
        }
        // précédent / suivant : rubriques de premier niveau (accueil avant la première)
        $tree = Wiki::tree();
        $cats = array_values(array_filter($tree, static fn ($n) => $n['type'] === 'cat' || $n['file'] !== null));
        $here = $top['path'] ?? $node['path'];
        $prev = $next = null;
        foreach ($cats as $k => $n) {
            if ($n['path'] === $here) {
                $prev = $cats[$k - 1] ?? ['path' => Wiki::BASE, 'title' => 'Accueil de l\'aide'];
                $next = $cats[$k + 1] ?? null;
            }
        }
        if ($node['type'] === 'home') {
            $next = $cats[0] ?? null;
        }
        View::render('wiki', [
            'tree' => $tree, 'node' => $node, 'top' => $top, 'sections' => $sections, 'prev' => $prev, 'next' => $next,
            'search' => Wiki::searchIndex(),
        ], $node['title'], false);
    }

    /** Capture d'écran des pages d'aide : nom simple uniquement, cherché dans docs/wiki/_images puis docs/screenshots. */
    private function image(string $name): void
    {
        $file = null;
        if (preg_match(Markdown::IMAGE_NAME, $name)) {
            foreach ([Wiki::root() . '/_images', APP_ROOT . '/docs/screenshots'] as $dir) {
                if (is_file($dir . '/' . $name)) {
                    $file = $dir . '/' . $name;
                    break;
                }
            }
        }
        if ($file === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Image introuvable';
            return;
        }
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        header('Content-Type: ' . ($ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg')));
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
    }
}
