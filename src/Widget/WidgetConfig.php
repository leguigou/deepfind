<?php

declare(strict_types=1);

namespace DeepFind\Widget;

use DeepFind\Db;
use DeepFind\Project;
use DeepFind\Settings;
use DeepFind\Text;

/**
 * Affichage du widget sur le site (page « Intégration ») :
 * - layers : façon dont la fenêtre de recherche s'affiche (un seul actif par projet) ;
 * - filtres : ordre, nom affiché et visibilité des filtres (par langue) ;
 * - options de tri : liste proposée aux visiteurs et noms affichés (par langue).
 * Le tout est renvoyé au widget par /api/v1/config (aucune donnée interne).
 */
final class WidgetConfig
{
    /** Types de layer : [libellé, description]. */
    public const TYPES = [
        'below' => ['Sous la barre', 'S\'ouvre juste sous la barre de recherche du site, le reste de la page est assombri.'],
        'fullscreen' => ['Plein écran', 'Occupe toute la page quand les acheteurs ouvrent la recherche.'],
        'floating' => ['Flottant', 'Flotte au-dessus de la page dans un panneau que les acheteurs peuvent fermer.'],
        'embedded' => ['Intégré', 'S\'intègre comme une partie d\'une section existante de votre boutique.'],
    ];

    /** Options de tri possibles (hors pertinence, toujours proposée en premier) : [libellé par défaut fr, en]. */
    public const SORTS = [
        'price_asc' => ['Prix croissant', 'Price: low to high'],
        'price_desc' => ['Prix décroissant', 'Price: high to low'],
        'newest' => ['Nouveautés', 'Newest'],
        'name_asc' => ['Nom de A à Z', 'Name: A to Z'],
        'name_desc' => ['Nom de Z à A', 'Name: Z to A'],
    ];

    /** Tris proposés tant que rien n'est enregistré (comportement historique du widget). */
    private const DEFAULT_SORTS = ['price_asc', 'price_desc', 'newest'];

    /** Apparence des filtres : toggle = case à cocher, range = plage min / max, terms = liste de valeurs. */
    public const APPEARANCES = ['toggle' => 'Case à cocher', 'range' => 'Plage (min / max)', 'terms' => 'Liste de valeurs'];

    // ------------------------------------------------------------------ Layers

    /** @return list<array{id:int, name:string, type:string, active:bool, settings:array}> */
    public static function layers(?int $projectId = null): array
    {
        return array_map([self::class, 'decodeLayer'], Db::all(
            'SELECT * FROM layers WHERE project_id = ? ORDER BY active DESC, id',
            [$projectId ?? Project::id()]
        ));
    }

    public static function layer(int $id): ?array
    {
        $row = Db::one('SELECT * FROM layers WHERE id = ? AND project_id = ?', [$id, Project::id()]);
        return $row ? self::decodeLayer($row) : null;
    }

    public static function activeLayer(?int $projectId = null): ?array
    {
        $row = Db::one('SELECT * FROM layers WHERE project_id = ? AND active = 1 ORDER BY id LIMIT 1', [$projectId ?? Project::id()]);
        return $row ? self::decodeLayer($row) : null;
    }

    private static function decodeLayer(array $r): array
    {
        $s = json_decode((string) $r['settings'], true);
        return [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'type' => isset(self::TYPES[$r['type']]) ? (string) $r['type'] : 'below',
            'active' => (int) $r['active'] === 1, 'settings' => self::cleanSettings(is_array($s) ? $s : []),
        ];
    }

    /** Réglages d'un layer, nettoyés (sélecteurs CSS limités, pas de balise). */
    public static function cleanSettings(array $in): array
    {
        $sel = static fn ($v) => mb_substr(trim(str_replace(['<', '>', '{', '}'], '', (string) $v)), 0, 300, 'UTF-8');
        $gap = (int) ($in['gap'] ?? 12);
        return [
            'input' => $sel($in['input'] ?? ''),
            'container' => $sel($in['container'] ?? ''),
            'url_state' => !empty($in['url_state']),
            'gap' => max(0, min(80, $gap)),
        ];
    }

    public static function createLayer(string $name, string $type): int
    {
        $type = isset(self::TYPES[$type]) ? $type : 'below';
        $name = Text::truncate(trim($name), 100) ?: self::TYPES[$type][0];
        $now = date('Y-m-d H:i:s');
        // Premier layer du projet : actif tout de suite (sinon le widget garde l'affichage par défaut)
        $first = (int) Db::value('SELECT COUNT(*) FROM layers WHERE project_id = ?', [Project::id()]) === 0;
        $id = Db::insert('layers', [
            'project_id' => Project::id(), 'name' => $name, 'type' => $type, 'active' => $first ? 1 : 0,
            'settings' => Text::json(self::cleanSettings([])), 'created_at' => $now, 'updated_at' => $now,
        ]);
        \DeepFind\CacheVersion::bump();
        return (int) $id;
    }

    public static function saveLayer(int $id, string $name, string $type, array $settings): void
    {
        $l = self::layer($id);
        if ($l === null) {
            return;
        }
        Db::update('layers', [
            'name' => Text::truncate(trim($name), 100) ?: $l['name'],
            'type' => isset(self::TYPES[$type]) ? $type : $l['type'],
            'settings' => Text::json(self::cleanSettings($settings)),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ? AND project_id = ?', [$id, Project::id()]);
        \DeepFind\CacheVersion::bump();
    }

    /** Active ce layer (les autres du projet sont désactivés) ; $on = false : plus aucun layer actif. */
    public static function activateLayer(int $id, bool $on): void
    {
        if (self::layer($id) === null) {
            return;
        }
        if ($on) {
            Db::exec('UPDATE layers SET active = 0 WHERE project_id = ?', [Project::id()]);
        }
        Db::exec('UPDATE layers SET active = ? WHERE id = ? AND project_id = ?', [$on ? 1 : 0, $id, Project::id()]);
        \DeepFind\CacheVersion::bump();
    }

    public static function deleteLayer(int $id): void
    {
        Db::exec('DELETE FROM layers WHERE id = ? AND project_id = ?', [$id, Project::id()]);
        \DeepFind\CacheVersion::bump();
    }

    // ------------------------------------------------------------------ Filtres

    /** Filtres disponibles : code => [libellé par défaut, apparence]. */
    public static function availableFilters(string $lang): array
    {
        $en = $lang === 'en';
        $out = [
            'in_stock' => [$en ? 'In stock' : 'En stock', 'toggle'],
            'on_sale' => [$en ? 'On sale' : 'En promotion', 'toggle'],
            'brand' => [$en ? 'Brands' : 'Marques', 'terms'],
            'category' => [$en ? 'Categories' : 'Catégories', 'terms'],
            'price' => [$en ? 'Price' : 'Prix', 'range'],
        ];
        foreach (Settings::json('attr_labels_' . $lang) as $code => $label) {
            $out['attr.' . $code] = [(string) $label, 'terms'];
        }
        return $out;
    }

    /**
     * Filtres dans l'ordre choisi, avec nom affiché et visibilité. Les filtres apparus depuis (nouvel attribut importé)
     * sont ajoutés à la fin, visibles ; ceux qui n'existent plus sont ignorés.
     * @return list<array{code:string, label:string, default_label:string, appearance:string, visible:bool}>
     */
    public static function filters(string $lang): array
    {
        $avail = self::availableFilters($lang);
        $out = [];
        foreach (Settings::json('widget_filters_' . $lang) as $f) {
            $code = (string) ($f['code'] ?? '');
            if (!isset($avail[$code]) || isset($out[$code])) {
                continue;
            }
            $label = trim((string) ($f['label'] ?? ''));
            $out[$code] = ['code' => $code, 'label' => $label !== '' ? $label : $avail[$code][0], 'default_label' => $avail[$code][0],
                'appearance' => $avail[$code][1], 'visible' => !empty($f['visible'])];
        }
        foreach ($avail as $code => [$label, $appearance]) {
            if (!isset($out[$code])) {
                $out[$code] = ['code' => $code, 'label' => $label, 'default_label' => $label, 'appearance' => $appearance, 'visible' => true];
            }
        }
        return array_values($out);
    }

    /** @param list<array{code?:mixed, label?:mixed, visible?:mixed}> $in */
    public static function saveFilters(string $lang, array $in): void
    {
        $avail = self::availableFilters($lang);
        $out = [];
        foreach (array_slice($in, 0, 200) as $f) {
            $code = (string) ($f['code'] ?? '');
            if (!isset($avail[$code]) || isset($out[$code])) {
                continue;
            }
            $label = Text::truncate(trim((string) ($f['label'] ?? '')), 60);
            $out[$code] = ['code' => $code, 'label' => $label === $avail[$code][0] ? '' : $label, 'visible' => !empty($f['visible'])];
        }
        Settings::set('widget_filters_' . $lang, Text::json(array_values($out)));
    }

    // ------------------------------------------------------------------ Options de tri

    /** @return array{relevance:string, items:list<array{key:string, label:string}>} */
    public static function sorts(string $lang): array
    {
        $saved = Settings::json('widget_sorts_' . $lang);
        $i = $lang === 'en' ? 1 : 0;
        $items = [];
        $list = isset($saved['items']) && is_array($saved['items']) ? $saved['items'] : array_map(static fn ($k) => ['key' => $k], self::DEFAULT_SORTS);
        foreach ($list as $s) {
            $key = (string) ($s['key'] ?? '');
            if (!isset(self::SORTS[$key])) {
                continue;
            }
            $label = trim((string) ($s['label'] ?? ''));
            $items[] = ['key' => $key, 'label' => $label !== '' ? $label : self::SORTS[$key][$i]];
        }
        $rel = trim((string) ($saved['relevance'] ?? ''));
        return ['relevance' => $rel !== '' ? $rel : ($i ? 'Relevance' : 'Pertinence'), 'items' => $items];
    }

    /** @param list<array{key?:mixed, label?:mixed}> $items */
    public static function saveSorts(string $lang, string $relevance, array $items): void
    {
        $out = [];
        foreach (array_slice($items, 0, 20) as $s) {
            $key = (string) ($s['key'] ?? '');
            if (isset(self::SORTS[$key])) {
                $out[] = ['key' => $key, 'label' => Text::truncate(trim((string) ($s['label'] ?? '')), 60)];
            }
        }
        Settings::set('widget_sorts_' . $lang, Text::json(['relevance' => Text::truncate(trim($relevance), 60), 'items' => $out]));
    }

    // ------------------------------------------------------------------ Pour le widget

    /** Partie « affichage » de /api/v1/config. */
    public static function forWidget(string $lang): array
    {
        $layer = self::activeLayer();
        return [
            'layer' => $layer ? ['type' => $layer['type']] + $layer['settings'] : null,
            'filters' => array_map(static fn ($f) => ['code' => $f['code'], 'label' => $f['label'], 'appearance' => $f['appearance'], 'visible' => $f['visible']],
                self::filters($lang)),
            'sorts' => self::sorts($lang),
        ];
    }
}
