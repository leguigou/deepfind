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

    /**
     * Champs proposés pour les options de tri : code => [libellé, type, principal ?, champ du moteur].
     * type text : A à Z / Z à A ; num : du plus bas au plus élevé / l'inverse ; date : du plus récent / du plus ancien.
     * Jamais la marge ni le score interne : ils restent réservés aux tris de l'aperçu interne.
     */
    public const SORT_FIELDS = [
        'price' => ['Prix', 'num', true, 'sort_price'],
        'name' => ['Nom du produit', 'text', true, 'name'],
        'created' => ['Date d\'ajout', 'date', true, 'created_ts'],
        'discount' => ['Remise (%)', 'num', true, 'discount_percent'],
        'brand' => ['Marque', 'text', true, 'brand'],
        'sales' => ['Ventes 30 jours', 'num', true, 'sales_30d'],
        'rating' => ['Note des avis', 'num', true, 'rating'],
        'regular_price' => ['Prix avant remise', 'num', false, 'price'],
        'reviews' => ['Nombre d\'avis', 'num', false, 'reviews_count'],
        'sku' => ['SKU', 'text', false, 'sku'],
        'type' => ['Type de produit', 'text', false, 'type'],
        'in_stock' => ['Disponibilité', 'num', false, 'in_stock'],
        'on_sale' => ['En promotion', 'num', false, 'on_sale'],
        'is_new' => ['Nouveauté', 'num', false, 'is_new'],
    ];

    /** Ordres selon le type de champ : dir => [libellé de l'ordre, suffixe du nom par défaut fr, en]. */
    public const SORT_DIRS = [
        'text' => ['asc' => ['A à Z', 'de A à Z', 'A to Z'], 'desc' => ['Z à A', 'de Z à A', 'Z to A']],
        'num' => ['asc' => ['Du plus bas au plus élevé', 'croissant', 'low to high'], 'desc' => ['Du plus élevé au plus bas', 'décroissant', 'high to low']],
        'date' => ['desc' => ['Du plus récent au plus ancien', 'du plus récent', 'newest first'], 'asc' => ['Du plus ancien au plus récent', 'du plus ancien', 'oldest first']],
    ];

    /** Anciennes clés de tri (widget ?v=7/8, réglages déjà enregistrés) → champ:ordre. */
    public const LEGACY_SORTS = ['price_asc' => 'price:asc', 'price_desc' => 'price:desc', 'newest' => 'created:desc', 'name_asc' => 'name:asc', 'name_desc' => 'name:desc'];

    /** Tris proposés tant que rien n'est enregistré (comportement historique du widget). */
    private const DEFAULT_SORTS = ['price:asc', 'price:desc', 'created:desc'];

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
        $color = static fn ($v) => preg_match('/^#[0-9a-f]{6}$/i', trim((string) $v)) ? strtolower(trim((string) $v)) : '';
        $logo = trim((string) ($in['logo'] ?? ''));
        $gap = (int) ($in['gap'] ?? 12);
        return [
            'input' => $sel($in['input'] ?? ''),
            'container' => $sel($in['container'] ?? ''),
            'url_state' => !empty($in['url_state']),
            'gap' => max(0, min(80, $gap)),
            // largeur maximale de la fenêtre (sauf plein écran) en % de l'écran (par défaut) ou en pixels ; 0 = largeur par défaut
            // (réglage enregistré avant le choix de l'unité : en pixels)
            'max_width_unit' => $unit = (($in['max_width_unit'] ?? (isset($in['max_width']) && (int) $in['max_width'] > 0 ? 'px' : 'pct')) === 'px' ? 'px' : 'pct'),
            'max_width' => ($mw = (int) ($in['max_width'] ?? 0)) > 0 ? ($unit === 'px' ? max(320, min(3000, $mw)) : max(10, min(100, $mw))) : 0,
            // caractères tapés avant d'afficher les résultats
            'min_chars' => max(1, min(10, (int) ($in['min_chars'] ?? 2))),
            // nombre de produits : par ligne (0 = automatique selon la largeur, ordinateur) et chargés à la fois, ordinateur / mobile
            'cols_desktop' => max(0, min(8, (int) ($in['cols_desktop'] ?? 0))),
            'cols_mobile' => max(1, min(3, (int) ($in['cols_mobile'] ?? 2))),
            'per_page_desktop' => max(4, min(48, (int) ($in['per_page_desktop'] ?? 20))),
            'per_page_mobile' => max(4, min(48, (int) ($in['per_page_mobile'] ?? 20))),
            // identité de marque : logo (https uniquement) et couleurs #rrggbb (vide = couleurs par défaut du widget)
            'logo' => preg_match('#^https://[^\s"\'<>]+$#i', $logo) ? mb_substr($logo, 0, 500, 'UTF-8') : '',
            'color_primary' => $color($in['color_primary'] ?? ''),
            'color_contrast' => $color($in['color_contrast'] ?? ''),
            'color_text' => $color($in['color_text'] ?? ''),
            // personnalisation avancée : CSS ajouté à la page (texte brut), modèle HTML de la carte produit (nettoyé)
            // versions mobile (écrans ≤ 900 px) : vides = la version ordinateur s'applique partout
            'css' => mb_substr(str_replace("\0", '', (string) ($in['css'] ?? '')), 0, 20000, 'UTF-8'),
            'css_mobile' => mb_substr(str_replace("\0", '', (string) ($in['css_mobile'] ?? '')), 0, 20000, 'UTF-8'),
            // modèle égal au modèle par défaut (ordinateur) ou à celui de l'ordinateur (mobile) : non stocké (vide = celui-ci)
            'card_template' => $tplDesk = self::sameTemplate($d = self::cleanCardTemplate((string) ($in['card_template'] ?? '')), self::DEFAULT_CARD_TEMPLATE) ? '' : $d,
            'card_template_mobile' => self::sameTemplate($m = self::cleanCardTemplate((string) ($in['card_template_mobile'] ?? '')), $tplDesk !== '' ? $tplDesk : self::DEFAULT_CARD_TEMPLATE) ? '' : $m,
        ];
    }

    /**
     * Carte produit par défaut du widget : c'est CE modèle que le widget utilise quand aucun modèle n'est enregistré
     * (copie identique dans public/widget/deepfind.js, DEFAULT_CARD ; un test vérifie qu'elles sont égales).
     * Un élément de classe df-if-<variable> est retiré quand la variable est vide ; img.df-img change d'image au survol.
     */
    public const DEFAULT_CARD_TEMPLATE = <<<'HTML'
<div class="df-media {{media_class}}">
  <img class="df-img df-if-image" src="{{image}}" alt="">
  <span class="df-badge df-badge-sale df-if-discount">{{discount}}</span>
  <span class="df-badge df-badge-new df-if-new">{{new}}</span>
  <span class="df-badge df-badge-oos df-if-out_of_stock">{{out_of_stock}}</span>
</div>
<div class="df-brand df-if-brand">{{brand}}</div>
<div class="df-name">{{name}}</div>
<div class="df-variant df-if-variant">{{variant}}</div>
<div class="df-price {{price_class}}">
  <span class="df-from df-if-from">{{from}}</span>
  <span class="df-final">{{price}}</span>
  <span class="df-old df-if-old_price">{{old_price}}</span>
</div>
HTML;

    /** Variables du modèle de carte produit : {{nom}} => description (valeurs échappées par le widget). */
    public const CARD_VARS = [
        'name' => 'Nom du produit', 'brand' => 'Marque', 'image' => 'Image', 'price' => 'Prix (formaté)',
        'old_price' => 'Prix avant remise (vide sans remise)', 'discount' => 'Remise, ex. « -20% » (vide sans remise)',
        'from' => '« dès » si le prix varie selon la déclinaison', 'sku' => 'SKU', 'variant' => 'SKU de la déclinaison trouvée (sinon vide)',
        'stock' => '« En stock » ou « Rupture »', 'out_of_stock' => '« Rupture » ou vide', 'new' => '« Nouveau » (sans remise affichée) ou vide',
        'rating' => 'Note des avis', 'price_class' => 'Classe df-sale si en promotion', 'media_class' => 'Classe df-noimg sans image',
    ];

    /**
     * Modèle de carte : mêmes balises et attributs que les bannières (HtmlSanitizer), variables {{…}} conservées
     * (y compris dans href / src). Le widget le nettoie de nouveau après avoir remplacé les variables.
     */
    /** Deux modèles identiques aux espaces près. */
    public static function sameTemplate(string $a, string $b): bool
    {
        $n = static fn (string $s) => preg_replace('/\s+/', ' ', trim($s));
        return $n($a) === $n($b);
    }

    public static function cleanCardTemplate(string $html): string
    {
        $html = trim(mb_substr($html, 0, 10000, 'UTF-8'));
        if ($html === '') {
            return '';
        }
        $vars = implode('|', array_keys(self::CARD_VARS));
        $html = preg_replace('/\{\{\s*(' . $vars . ')\s*\}\}/', '/__df__/$1', $html) ?? '';
        $html = \DeepFind\Security\HtmlSanitizer::clean($html);
        // (?![a-z_]) : « price » ne doit pas mordre sur « price_class »
        return preg_replace('#/__df__/(' . $vars . ')(?![a-z_])#', '{{$1}}', $html) ?? '';
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

    /** Ordre des valeurs d'un filtre « liste » : par nombre de produits (décroissant) ou alphabétique. */
    public const VALUE_ORDERS = ['count' => 'Nombre de produits (du plus grand au plus petit)', 'alpha' => 'Ordre alphabétique (A à Z)'];

    /** Ordre par défaut : alphabétique pour les marques, par nombre de produits pour les autres (comportement historique). */
    public static function defaultOrder(string $code): string
    {
        return $code === 'brand' ? 'alpha' : 'count';
    }

    /** Nombre de valeurs affichées par défaut dans un filtre « liste » (les suivantes derrière « + N »). */
    public static function defaultLimit(string $code): int
    {
        return $code === 'brand' ? 12 : 8;
    }

    /**
     * Filtres ajoutés, dans l'ordre choisi (nom affiché, statut, nombre de valeurs affichées).
     * Tant que rien n'est enregistré : tous les filtres disponibles. Ensuite : seulement ceux ajoutés
     * (un nouvel attribut importé se trouve dans « Ajouter un filtre ») ; ceux qui n'existent plus sont ignorés.
     * @return list<array{code:string, label:string, default_label:string, appearance:string, visible:bool, limit:int, open:bool, order:string}>
     */
    public static function filters(string $lang): array
    {
        $avail = self::availableFilters($lang);
        $saved = Settings::get('widget_filters_' . $lang) === ''
            ? array_map(static fn ($c) => ['code' => $c, 'visible' => true], array_keys($avail))
            : Settings::json('widget_filters_' . $lang);
        $out = [];
        foreach ($saved as $f) {
            $code = (string) ($f['code'] ?? '');
            if (!isset($avail[$code]) || isset($out[$code])) {
                continue;
            }
            $label = trim((string) ($f['label'] ?? ''));
            $limit = (int) ($f['limit'] ?? 0);
            $out[$code] = ['code' => $code, 'label' => $label !== '' ? $label : $avail[$code][0], 'default_label' => $avail[$code][0],
                'appearance' => $avail[$code][1], 'visible' => !empty($f['visible']),
                'limit' => $limit >= 1 ? min(100, $limit) : self::defaultLimit($code),
                // bloc déplié à l'ouverture de la fenêtre (le visiteur peut le replier / déplier)
                'open' => !array_key_exists('open', $f) || !empty($f['open']),
                'order' => isset(self::VALUE_ORDERS[$f['order'] ?? '']) ? (string) $f['order'] : self::defaultOrder($code)];
        }
        return array_values($out);
    }

    /** Filtres disponibles pas encore ajoutés (fenêtre « Ajouter un filtre »). @return array<string, array{0:string, 1:string}> */
    public static function addableFilters(string $lang): array
    {
        return array_diff_key(self::availableFilters($lang), array_flip(array_column(self::filters($lang), 'code')));
    }

    /** @param list<array{code?:mixed, label?:mixed, visible?:mixed, limit?:mixed}> $in */
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
            $row = ['code' => $code, 'label' => $label === $avail[$code][0] ? '' : $label, 'visible' => !empty($f['visible'])];
            if ($avail[$code][1] !== 'toggle') {
                $row['open'] = !array_key_exists('open', $f) || !empty($f['open']);
            }
            if ($avail[$code][1] === 'terms') {
                $row['order'] = isset(self::VALUE_ORDERS[$f['order'] ?? '']) ? (string) $f['order'] : self::defaultOrder($code);
                $row['limit'] = max(1, min(100, (int) ($f['limit'] ?? self::defaultLimit($code)) ?: self::defaultLimit($code)));
            }
            $out[$code] = $row;
        }
        Settings::set('widget_filters_' . $lang, Text::json(array_values($out)));
    }

    // ------------------------------------------------------------------ Options de tri

    /** « champ:ordre » valide (ancienne clé acceptée), sinon null. @return ?array{0:string, 1:string} */
    public static function parseSort(string $key): ?array
    {
        $key = self::LEGACY_SORTS[$key] ?? $key;
        if (!preg_match('/^([a-z_]+):(asc|desc)$/', $key, $m) || !isset(self::SORT_FIELDS[$m[1]])) {
            return null;
        }
        return [$m[1], $m[2]];
    }

    /** Nom affiché par défaut d'un tri (« Prix croissant », « Nom du produit de A à Z »). */
    public static function sortDefaultLabel(string $field, string $dir, string $lang): string
    {
        [$label, $type] = self::SORT_FIELDS[$field];
        $d = self::SORT_DIRS[$type][$dir];
        return $label . ' ' . ($lang === 'en' ? $d[2] : $d[1]);
    }

    /** Tris enregistrés (label vide = pas de nom personnalisé). @return list<array{field:string, dir:string, label:string}> */
    public static function sortItems(string $lang): array
    {
        $saved = Settings::json('widget_sorts_' . $lang);
        $list = isset($saved['items']) && is_array($saved['items']) ? $saved['items'] : array_map(static fn ($k) => ['key' => $k], self::DEFAULT_SORTS);
        $items = [];
        foreach ($list as $s) {
            $p = self::parseSort((string) ($s['key'] ?? ''));
            if ($p !== null) {
                $items[] = ['field' => $p[0], 'dir' => $p[1], 'label' => trim((string) ($s['label'] ?? ''))];
            }
        }
        return $items;
    }

    /** Pour le widget : nom par défaut quand aucun nom personnalisé. @return array{relevance:string, items:list<array{key:string, label:string}>} */
    public static function sorts(string $lang): array
    {
        $saved = Settings::json('widget_sorts_' . $lang);
        $items = array_map(static fn ($s) => [
            'key' => $s['field'] . ':' . $s['dir'],
            'label' => $s['label'] !== '' ? $s['label'] : self::sortDefaultLabel($s['field'], $s['dir'], $lang),
        ], self::sortItems($lang));
        $rel = trim((string) ($saved['relevance'] ?? ''));
        return ['relevance' => $rel !== '' ? $rel : ($lang === 'en' ? 'Relevance' : 'Pertinence'), 'items' => $items];
    }

    /** @param list<array{field?:mixed, dir?:mixed, label?:mixed}> $items */
    public static function saveSorts(string $lang, string $relevance, array $items): void
    {
        $out = [];
        $seen = [];
        foreach (array_slice($items, 0, 20) as $s) {
            $p = self::parseSort((string) ($s['field'] ?? '') . ':' . (string) ($s['dir'] ?? ''));
            if ($p === null || isset($seen[$p[0] . ':' . $p[1]])) {
                continue;
            }
            $seen[$p[0] . ':' . $p[1]] = true;
            $out[] = ['key' => $p[0] . ':' . $p[1], 'label' => Text::truncate(trim((string) ($s['label'] ?? '')), 60)];
        }
        Settings::set('widget_sorts_' . $lang, Text::json(['relevance' => Text::truncate(trim($relevance), 60), 'items' => $out]));
    }

    // ------------------------------------------------------------------ Pour le widget

    /** Version du widget à charger (paramètre ?v= du code du site et du favori « Tester DeepFind »). */
    public const WIDGET_VERSION = 29;

    /** Durée de validité d'un favori « Tester DeepFind » (jeton d'aperçu). */
    public const PREVIEW_DAYS = 7;

    /**
     * Jeton d'aperçu d'un layer (favori « Tester DeepFind ») : projet + layer + expiration, signé avec APP_SECRET.
     * Il ne donne accès qu'à l'affichage de ce layer dans le navigateur de la personne qui teste ; rien n'est modifié.
     */
    public static function previewToken(int $layerId, ?int $projectId = null, ?int $expires = null): string
    {
        $payload = ($projectId ?? Project::id()) . '.' . $layerId . '.' . ($expires ?? time() + self::PREVIEW_DAYS * 86400);
        return $payload . '.' . substr(hash_hmac('sha256', $payload, (string) \DeepFind\Config::get('APP_SECRET', 'change-me')), 0, 24);
    }

    /** Layer désigné par un jeton d'aperçu valide pour ce projet, sinon null. */
    public static function previewLayer(string $token, ?int $projectId = null): ?array
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)\.([0-9a-f]{24})$/', $token, $m)) {
            return null;
        }
        $pid = $projectId ?? Project::id();
        if ((int) $m[1] !== $pid || (int) $m[3] < time() || !hash_equals(self::previewToken((int) $m[2], $pid, (int) $m[3]), $token)) {
            return null;
        }
        $row = Db::one('SELECT * FROM layers WHERE id = ? AND project_id = ?', [(int) $m[2], $pid]);
        return $row ? self::decodeLayer($row) : null;
    }

    /** Partie « affichage » de /api/v1/config ; $preview = jeton d'un favori « Tester DeepFind » (layer choisi au lieu du layer actif). */
    public static function forWidget(string $lang, string $preview = ''): array
    {
        $layer = $preview !== '' ? self::previewLayer($preview) : null;
        $isPreview = $layer !== null;
        $layer ??= self::activeLayer();
        return [
            'layer' => $layer ? ['type' => $layer['type']] + $layer['settings'] : null,
            'filters' => array_map(static fn ($f) => ['code' => $f['code'], 'label' => $f['label'], 'appearance' => $f['appearance'], 'visible' => $f['visible'],
                'limit' => $f['limit'], 'open' => $f['open']],
                self::filters($lang)),
            'sorts' => self::sorts($lang),
        ] + ($preview !== '' ? ['preview' => $isPreview ? ['layer' => $layer['name']] : ['error' => 'expired']] : []);
    }
}
