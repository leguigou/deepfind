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
        $flat = array_values(array_filter(Wiki::flat(), static fn ($n) => $n['file'] !== null));
        if ($node === null || $node['file'] === null && $node['type'] !== 'cat') {
            http_response_code(404);
            $node = ['type' => 'missing', 'path' => $path, 'title' => 'Page introuvable', 'file' => null, 'children' => [], 'trail' => []];
            $html = '<p>Cette page du wiki n\'existe pas (ou plus). Choisissez une rubrique à gauche, ou cherchez un mot.</p>';
            $toc = [];
        } else {
            $md = new Markdown(url(rtrim($node['path'], '/') . ($node['type'] === 'page' ? '' : '/index')), url(Wiki::BASE . '/images'));
            $html = $node['file'] !== null ? $md->render((string) file_get_contents($node['file'])) : '<h1>' . htmlspecialchars($node['title']) . '</h1>';
            $toc = $md->toc;
        }
        // catégorie : liste de ses pages sous son introduction
        $prev = $next = null;
        foreach ($flat as $k => $n) {
            if ($n['path'] === $node['path']) {
                $prev = $flat[$k - 1] ?? null;
                $next = $flat[$k + 1] ?? null;
            }
        }
        if ($node['type'] === 'home') {
            $next = $flat[0] ?? null;
        }
        View::render('wiki', [
            'tree' => Wiki::tree(), 'node' => $node, 'html' => $html, 'toc' => $toc, 'prev' => $prev, 'next' => $next,
            'search' => Wiki::searchIndex(),
            'source' => $node['file'] !== null ? substr($node['file'], strlen(APP_ROOT) + 1) : null,
            'updated' => $node['file'] !== null ? (int) filemtime($node['file']) : null,
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
