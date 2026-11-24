<?php

declare(strict_types=1);

namespace DeepFind\Wiki;

/**
 * Wiki de DeepFind (menu Aide) : pages Markdown de docs/wiki, mises à jour avec le code.
 *   docs/wiki/index.md                      page d'accueil (/admin/wiki ; /wiki y redirige)
 *   docs/wiki/01-demarrage/_index.md        catégorie « Démarrage » (titre = premier titre # du fichier, texte d'introduction)
 *   docs/wiki/01-demarrage/02-installation.md   page /admin/wiki/demarrage/installation
 *   docs/wiki/04-x/03-carrousels/…          sous-catégorie (même principe, sur plusieurs niveaux)
 * Le préfixe « 01- » fixe l'ordre et n'apparaît pas dans l'adresse. Une adresse n'est servie que si elle correspond à un fichier
 * trouvé par le parcours (aucun chemin de fichier n'est construit à partir de l'adresse).
 */
final class Wiki
{
    /** Adresse de l'aide : dans l'extranet, pour profiter de sa connexion (cookie de session limité à /admin). */
    public const BASE = '/admin/wiki';

    public static function root(): string
    {
        return APP_ROOT . '/docs/wiki';
    }

    /**
     * Arborescence : liste de nœuds {type: cat|page, slug, path (adresse), title, file, children}.
     * @return list<array>
     */
    public static function tree(?string $dir = null, string $prefix = self::BASE): array
    {
        $dir ??= self::root();
        if (!is_dir($dir)) {
            return [];
        }
        $names = array_values(array_filter(scandir($dir) ?: [], static fn ($f) => $f[0] !== '.' && $f[0] !== '_' && $f !== 'index.md'));
        sort($names, SORT_NATURAL);
        $out = [];
        foreach ($names as $name) {
            $full = $dir . '/' . $name;
            $slug = self::slug($name);
            if ($slug === '') {
                continue;
            }
            if (is_dir($full)) {
                $index = $full . '/_index.md';
                $out[] = ['type' => 'cat', 'slug' => $slug, 'path' => $prefix . '/' . $slug, 'title' => self::title(is_file($index) ? $index : null, $name),
                    'file' => is_file($index) ? $index : null, 'children' => self::tree($full, $prefix . '/' . $slug)];
            } elseif (str_ends_with($name, '.md')) {
                $out[] = ['type' => 'page', 'slug' => $slug, 'path' => $prefix . '/' . $slug, 'title' => self::title($full, $name), 'file' => $full, 'children' => []];
            }
        }
        return $out;
    }

    /** « 02-installation.md » → « installation » */
    public static function slug(string $name): string
    {
        $s = (string) preg_replace(['/\.md$/', '/^\d+[-_]/'], '', $name);
        return strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $s), '-'));
    }

    /** Premier titre « # » du fichier, sinon nom lisible. */
    private static function title(?string $file, string $name): string
    {
        if ($file !== null) {
            $fh = @fopen($file, 'rb');
            if ($fh) {
                for ($k = 0; $k < 20 && ($line = fgets($fh)) !== false; $k++) {
                    if (preg_match('/^#\s+(.+?)\s*$/', $line, $m)) {
                        fclose($fh);
                        return Markdown::plain($m[1]);
                    }
                }
                fclose($fh);
            }
        }
        return ucfirst(str_replace('-', ' ', self::slug($name)));
    }

    /** Pages et catégories dans l'ordre de lecture. @return list<array> */
    public static function flat(?array $tree = null, array $trail = []): array
    {
        $out = [];
        foreach ($tree ?? self::tree() as $node) {
            $out[] = $node + ['trail' => $trail];
            if ($node['children']) {
                $out = [...$out, ...self::flat($node['children'], [...$trail, ['title' => $node['title'], 'path' => $node['path']]])];
            }
        }
        return $out;
    }

    /** Nœud de l'adresse (/admin/wiki/...), ou null ; « /admin/wiki » = accueil. */
    public static function find(string $path): ?array
    {
        $path = '/' . trim($path, '/');
        if ($path === self::BASE) {
            $home = self::root() . '/index.md';
            return is_file($home) ? ['type' => 'home', 'path' => self::BASE, 'title' => self::title($home, 'Accueil'), 'file' => $home, 'children' => self::tree(), 'trail' => []] : null;
        }
        foreach (self::flat() as $node) {
            if ($node['path'] === $path) {
                return $node;
            }
        }
        return null;
    }

    /** Texte des pages pour la recherche (titre, adresse, extrait, contenu sans balisage). @return list<array{title:string, path:string, trail:string, text:string}> */
    public static function searchIndex(): array
    {
        $out = [];
        foreach (self::flat() as $n) {
            if ($n['file'] === null) {
                continue;
            }
            $md = (string) file_get_contents($n['file']);
            $text = Markdown::plain((string) preg_replace(['/```.*?```/s', '/^#+\s*/m', '/[|>*_-]{2,}/', '/\s+/'], [' ', '', ' ', ' '], $md));
            $out[] = ['title' => $n['title'], 'path' => $n['path'], 'trail' => implode(' › ', array_column($n['trail'], 'title')),
                'text' => mb_substr($text, 0, 6000, 'UTF-8')];
        }
        return $out;
    }
}
