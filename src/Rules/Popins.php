<?php

declare(strict_types=1);

namespace DeepFind\Rules;

use DeepFind\Db;
use DeepFind\Security\HtmlSanitizer;
use DeepFind\Store\Store;
use DeepFind\Text;

/**
 * Popins (Recommandations › Popins) : fenêtre affichée par le widget sur les pages du site.
 *   - pages : toutes, ou au choix (page d'accueil et/ou adresses, mêmes comparaisons que les règles selon la page),
 *     avec des adresses exclues (ex. panier, paiement) ;
 *   - période (en continu ou dates), appareil (tous / ordinateur / mobile), délai avant affichage ;
 *   - fréquence : au plus une fois par visite, ou à chaque page tant qu'elle n'est pas fermée ;
 *     après fermeture (ou clic), plus affichée pendant N jours (mémorisé dans le navigateur du visiteur, sans cookie) ;
 *   - contenu : image ordinateur / mobile + lien (comme les bannières) ou HTML nettoyé (HtmlSanitizer).
 * Une seule popin par page : la première de la liste (ordre « position ») qui correspond et n'a pas été fermée.
 * Le choix de la page se fait dans le widget (aucune requête supplémentaire par page vue) : la liste des popins
 * en cours arrive avec /api/v1/config. Suivi : popin_stats (affichages, clics, fermetures, agrégés par jour).
 */
final class Popins
{
    public const DEVICES = ['all' => 'Ordinateur et mobile', 'desktop' => 'Ordinateur uniquement', 'mobile' => 'Mobile uniquement'];
    public const REPEATS = [
        'visit' => 'Une seule fois par visite',
        'page' => 'À chaque page concernée, tant que le visiteur ne l\'a pas fermée',
    ];
    public const PLACEMENTS = ['center' => 'Au centre (fond assombri)', 'bottom_right' => 'En bas à droite', 'bottom_left' => 'En bas à gauche'];
    public const EVENTS = ['v' => 'views', 'c' => 'clicks', 'x' => 'closes'];
    /** Popins envoyées au widget au plus (les suivantes de la liste sont ignorées). */
    public const MAX_WIDGET = 30;

    /** @return list<array> popins du projet et de la langue, dans l'ordre de priorité */
    public static function all(string $lang): array
    {
        return array_map([self::class, 'decode'], Db::all('SELECT * FROM popins WHERE project_id = ? AND lang = ? ORDER BY position, id',
            [\DeepFind\Project::id(), $lang]));
    }

    public static function find(int $id, string $lang): ?array
    {
        $row = Db::one('SELECT * FROM popins WHERE id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]);
        return $row ? self::decode($row) : null;
    }

    public static function blank(): array
    {
        return [
            'id' => 0, 'name' => '', 'active' => true, 'position' => 0, 'starts_at' => null, 'ends_at' => null,
            'pages' => 'all', 'home' => false, 'urls' => [], 'exclude_urls' => [], 'device' => 'all', 'delay_seconds' => 3,
            'repeat_mode' => 'visit', 'remember_days' => 7, 'placement' => 'center', 'max_width' => 600, 'min_height' => 0,
            'mode' => 'visual', 'image_desktop' => '', 'image_mobile' => '', 'alt' => '', 'link_url' => '', 'new_window' => false, 'html' => '', 'rev' => 1,
        ];
    }

    private static function decode(array $r): array
    {
        $pick = static fn (array $choices, mixed $v, string $def) => isset($choices[(string) $v]) ? (string) $v : $def;
        return [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'active' => (int) $r['active'] === 1, 'position' => (int) $r['position'],
            'starts_at' => $r['starts_at'] ?: null, 'ends_at' => $r['ends_at'] ?: null,
            'pages' => $r['pages'] === 'custom' ? 'custom' : 'all', 'home' => (int) $r['home'] === 1,
            'urls' => json_decode((string) $r['urls'], true) ?: [], 'exclude_urls' => json_decode((string) $r['exclude_urls'], true) ?: [],
            'device' => $pick(self::DEVICES, $r['device'], 'all'), 'delay_seconds' => (int) $r['delay_seconds'],
            'repeat_mode' => $pick(self::REPEATS, $r['repeat_mode'], 'visit'), 'remember_days' => (int) $r['remember_days'],
            'placement' => $pick(self::PLACEMENTS, $r['placement'], 'center'), 'max_width' => (int) $r['max_width'],
            'min_height' => (int) ($r['min_height'] ?? 0),
            'mode' => $r['mode'] === 'code' ? 'code' : 'visual', 'image_desktop' => (string) $r['image_desktop'], 'image_mobile' => (string) $r['image_mobile'],
            'alt' => (string) $r['alt'], 'link_url' => (string) $r['link_url'], 'new_window' => (int) $r['new_window'] === 1, 'html' => (string) $r['html'],
            'rev' => (int) $r['rev'],
        ];
    }

    /**
     * Popins actives aujourd'hui, prêtes pour le widget (aucun champ interne : ni nom, ni dates).
     * Cache invalidé par CacheVersion ; la date du jour fait partie de la clé.
     * @return list<array>
     */
    public static function forWidget(string $lang): array
    {
        $today = date('Y-m-d');
        $store = Store::get();
        $key = 'popins:' . \DeepFind\Project::id() . ':' . $lang . ':' . $today . ':' . \DeepFind\CacheVersion::get();
        $cached = $store->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $list = [];
        foreach (self::all($lang) as $p) {
            if ($p['active'] && CustomResults::period($p, $today) === 'current' && count($list) < self::MAX_WIDGET) {
                $list[] = self::payload($p);
            }
        }
        $store->set($key, $list, 300);
        return $list;
    }

    public static function payload(array $p): array
    {
        $out = [
            'id' => $p['id'], 'rev' => $p['rev'], 'pages' => $p['pages'], 'home' => $p['home'], 'urls' => $p['urls'], 'exclude' => $p['exclude_urls'],
            'device' => $p['device'], 'delay' => $p['delay_seconds'], 'repeat' => $p['repeat_mode'], 'remember_days' => $p['remember_days'],
            'placement' => $p['placement'], 'max_width' => $p['max_width'], 'min_height' => $p['min_height'], 'mode' => $p['mode'],
        ];
        return $out + ($p['mode'] === 'code'
            ? ['html' => $p['html']]
            : ['desktop' => $p['image_desktop'], 'mobile' => $p['image_mobile'] ?: $p['image_desktop'], 'alt' => $p['alt'], 'link' => $p['link_url'], 'new_window' => $p['new_window']]);
    }

    /**
     * Valide une popin envoyée par le formulaire.
     * @return array{0:?array, 1:list<string>}
     */
    public static function sanitize(array $in): array
    {
        $errors = [];
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 190, 'UTF-8');
        if ($name === '') {
            $errors[] = 'Le nom est obligatoire.';
        }
        $start = $end = null;
        if (($in['duration'] ?? 'always') === 'period') {
            $start = self::date($in['starts_at'] ?? '');
            $end = self::date($in['ends_at'] ?? '');
            if ($start === null || $end === null) {
                $errors[] = 'Choisissez la date de début et la date de fin de la période.';
            } elseif ($end < $start) {
                $errors[] = 'La date de fin est antérieure à la date de début.';
            }
        }
        $pages = ($in['pages'] ?? 'all') === 'custom' ? 'custom' : 'all';
        $home = !empty($in['home']);
        $urls = PageRules::sanitizeUrls((array) ($in['urls'] ?? []));
        if ($pages === 'custom' && !$home && $urls === []) {
            $errors[] = 'Choisissez les pages : la page d\'accueil et/ou au moins une adresse (ex. contient « /nautisme/ »).';
        }
        $mode = ($in['mode'] ?? '') === 'code' ? 'code' : 'visual';
        $url = static fn ($v) => mb_substr(trim((string) $v), 0, 500, 'UTF-8');
        $desktop = $url($in['image_desktop'] ?? '');
        $mobile = $url($in['image_mobile'] ?? '');
        $link = $url($in['link_url'] ?? '');
        $html = '';
        if ($mode === 'visual') {
            if ($desktop === '') {
                $errors[] = 'Ajoutez au moins l\'image « Ordinateur ».';
            }
            foreach (['Image ordinateur' => $desktop, 'Image mobile' => $mobile, 'Lien cible' => $link] as $label => $u) {
                if ($u !== '' && !preg_match('#^https?://#i', $u)) {
                    $errors[] = $label . ' : adresse complète attendue (https://…).';
                }
            }
        } else {
            $html = HtmlSanitizer::clean((string) ($in['html'] ?? ''));
            if ($html === '') {
                $errors[] = 'Saisissez le code HTML de la popin.';
            }
        }
        if ($errors !== []) {
            return [null, $errors];
        }
        $int = static fn (string $k, int $min, int $max, int $def) => isset($in[$k]) && is_numeric($in[$k]) ? max($min, min($max, (int) $in[$k])) : $def;
        $pick = static fn (array $choices, string $k, string $def) => isset($choices[(string) ($in[$k] ?? '')]) ? (string) $in[$k] : $def;
        return [[
            'name' => $name, 'active' => !empty($in['active']), 'starts_at' => $start, 'ends_at' => $end,
            'pages' => $pages, 'home' => $pages === 'custom' && $home, 'urls' => $pages === 'custom' ? $urls : [],
            'exclude_urls' => PageRules::sanitizeUrls((array) ($in['exclude_urls'] ?? [])),
            'device' => $pick(self::DEVICES, 'device', 'all'), 'delay_seconds' => $int('delay_seconds', 0, 600, 3),
            'repeat_mode' => $pick(self::REPEATS, 'repeat_mode', 'visit'), 'remember_days' => $int('remember_days', 0, 365, 7),
            'placement' => $pick(self::PLACEMENTS, 'placement', 'center'), 'max_width' => $int('max_width', 280, 1200, 600),
            'min_height' => $int('min_height', 0, 1200, 0),
            'mode' => $mode, 'image_desktop' => $mode === 'visual' ? $desktop : '', 'image_mobile' => $mode === 'visual' ? $mobile : '',
            'alt' => mb_substr(trim((string) ($in['alt'] ?? '')), 0, 190, 'UTF-8'), 'link_url' => $mode === 'visual' ? $link : '',
            'new_window' => !empty($in['new_window']), 'html' => $html, 'reset_memory' => !empty($in['reset_memory']),
        ], []];
    }

    private static function date(mixed $v): ?string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
    }

    /** @return int identifiant */
    public static function save(string $lang, array $p, ?int $id): int
    {
        $row = [
            'name' => $p['name'], 'active' => $p['active'] ? 1 : 0, 'starts_at' => $p['starts_at'], 'ends_at' => $p['ends_at'],
            'pages' => $p['pages'], 'home' => $p['home'] ? 1 : 0, 'urls' => Text::json($p['urls']), 'exclude_urls' => Text::json($p['exclude_urls']),
            'device' => $p['device'], 'delay_seconds' => $p['delay_seconds'], 'repeat_mode' => $p['repeat_mode'], 'remember_days' => $p['remember_days'],
            'placement' => $p['placement'], 'max_width' => $p['max_width'], 'min_height' => $p['min_height'], 'mode' => $p['mode'], 'image_desktop' => $p['image_desktop'],
            'image_mobile' => $p['image_mobile'], 'alt' => $p['alt'], 'link_url' => $p['link_url'], 'new_window' => $p['new_window'] ? 1 : 0,
            'html' => $p['html'], 'updated_at' => date('Y-m-d H:i:s'),
        ];
        $pid = \DeepFind\Project::id();
        if ($id !== null && self::find($id, $lang) !== null) {
            Db::update('popins', $row, 'id = ? AND project_id = ? AND lang = ?', [$id, $pid, $lang]);
            if (!empty($p['reset_memory'])) {
                // nouvelle version : les visiteurs qui l'avaient fermée la reverront
                Db::exec('UPDATE popins SET rev = rev + 1 WHERE id = ? AND project_id = ?', [$id, $pid]);
            }
        } else {
            // nouvelle popin : à la fin de la liste (elle ne passe pas devant les popins existantes)
            $pos = (int) Db::value('SELECT COALESCE(MAX(position), 0) + 1 FROM popins WHERE project_id = ? AND lang = ?', [$pid, $lang]);
            $id = Db::insert('popins', $row + ['project_id' => $pid, 'lang' => $lang, 'position' => $pos, 'rev' => 1, 'created_at' => $row['updated_at']]);
        }
        \DeepFind\CacheVersion::bump();
        return $id;
    }

    public static function setActive(int $id, string $lang, bool $on): void
    {
        Db::exec('UPDATE popins SET active = ?, updated_at = ? WHERE id = ? AND project_id = ? AND lang = ?',
            [$on ? 1 : 0, date('Y-m-d H:i:s'), $id, \DeepFind\Project::id(), $lang]);
        \DeepFind\CacheVersion::bump();
    }

    /** Nouvel ordre de priorité (identifiants dans l'ordre voulu ; les autres gardent leur rang, après). */
    public static function reorder(string $lang, array $ids): void
    {
        $known = array_column(self::all($lang), 'id');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => in_array($i, $known, true))));
        $order = [...$ids, ...array_values(array_diff($known, $ids))];
        foreach ($order as $pos => $id) {
            Db::exec('UPDATE popins SET position = ? WHERE id = ? AND project_id = ?', [$pos + 1, $id, \DeepFind\Project::id()]);
        }
        \DeepFind\CacheVersion::bump();
    }

    public static function delete(int $id, string $lang): void
    {
        if (Db::exec('DELETE FROM popins WHERE id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]) > 0) {
            Db::exec('DELETE FROM popin_stats WHERE popin_id = ? AND project_id = ?', [$id, \DeepFind\Project::id()]);
        }
        \DeepFind\CacheVersion::bump();
    }

    /**
     * Compte un événement envoyé par le widget (v = affichage, c = clic, x = fermeture).
     * Seules les popins en cours (celles que le widget a pu recevoir) sont comptées : pas de ligne pour un identifiant inventé.
     */
    public static function record(string $lang, int $id, string $event): bool
    {
        $col = self::EVENTS[$event] ?? null;
        if ($col === null || !in_array($id, array_column(self::forWidget($lang), 'id'), true)) {
            return false;
        }
        Db::exec("INSERT INTO popin_stats (project_id, popin_id, day, `$col`) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE `$col` = `$col` + 1",
            [\DeepFind\Project::id(), $id, date('Y-m-d')]);
        return true;
    }

    /** @return array<int, array{views:int, clicks:int, closes:int, ctr:?float}> sur les $days derniers jours (aujourd'hui compris) */
    public static function stats(array $ids, int $days): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[(int) $id] = ['views' => 0, 'clicks' => 0, 'closes' => 0, 'ctr' => null];
        }
        if ($out === []) {
            return $out;
        }
        $in = implode(',', array_fill(0, count($out), '?'));
        $since = date('Y-m-d', time() - (max(1, $days) - 1) * 86400);
        foreach (Db::all("SELECT popin_id, SUM(views) AS v, SUM(clicks) AS c, SUM(closes) AS x FROM popin_stats
                WHERE project_id = ? AND day >= ? AND popin_id IN ($in) GROUP BY popin_id", [\DeepFind\Project::id(), $since, ...array_keys($out)]) as $r) {
            $out[(int) $r['popin_id']] = ['views' => (int) $r['v'], 'clicks' => (int) $r['c'], 'closes' => (int) $r['x'], 'ctr' => null];
        }
        foreach ($out as &$s) {
            $s['ctr'] = $s['views'] > 0 ? round(100 * $s['clicks'] / $s['views'], 1) : null;
        }
        unset($s);
        return $out;
    }

    /** Résumé lisible des pages : « Toutes les pages », « Accueil, contient /nautisme/ ». */
    public static function describePages(array $p): string
    {
        if ($p['pages'] !== 'custom') {
            return 'Toutes les pages';
        }
        $parts = $p['home'] ? ['Page d\'accueil'] : [];
        foreach ($p['urls'] as $u) {
            $parts[] = (PageRules::URL_OPS[$u['match']] ?? $u['match']) . ' « ' . $u['text'] . ' »';
        }
        return implode(' ou ', $parts);
    }
}
