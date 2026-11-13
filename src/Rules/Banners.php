<?php

declare(strict_types=1);

namespace DeepFind\Rules;

use DeepFind\Db;
use DeepFind\Search\Relevance;
use DeepFind\Security\HtmlSanitizer;
use DeepFind\Store\Store;
use DeepFind\Text;

/**
 * Bannières affichées en haut des résultats du widget, pour certains termes (exacts ou larges) ou par défaut
 * (quand aucune bannière ne correspond), en continu ou sur une période.
 * Mode « visuel » : image ordinateur + image mobile + lien ; mode « code » : HTML nettoyé (HtmlSanitizer).
 * Suivi : impressions = recherches finales où la bannière est affichée (search_logs.banner_id), clics (click_logs, doc_type = banner).
 */
final class Banners
{
    public const MAX_UPLOAD = 500 * 1024;
    public const IMAGE_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];

    /** @return list<array> */
    public static function all(string $lang): array
    {
        return array_map([self::class, 'decode'], Db::all('SELECT * FROM banners WHERE project_id = ? AND lang = ? ORDER BY active DESC, is_default, name, id',
            [\DeepFind\Project::id(), $lang]));
    }

    public static function find(int $id, string $lang): ?array
    {
        $row = Db::one('SELECT * FROM banners WHERE id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]);
        return $row ? self::decode($row) : null;
    }

    private static function decode(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'active' => (int) $r['active'] === 1, 'is_default' => (int) $r['is_default'] === 1,
            'starts_at' => $r['starts_at'] ?: null, 'ends_at' => $r['ends_at'] ?: null, 'terms' => json_decode((string) $r['terms'], true) ?: [],
            'mode' => $r['mode'] === 'code' ? 'code' : 'visual', 'image_desktop' => (string) $r['image_desktop'], 'image_mobile' => (string) $r['image_mobile'],
            'alt' => (string) $r['alt'], 'link_url' => (string) $r['link_url'], 'new_window' => (int) $r['new_window'] === 1, 'html' => (string) $r['html'],
        ];
    }

    /** Bannière à afficher pour cette recherche : la plus récente qui correspond aux termes, sinon la bannière par défaut. */
    public static function forQuery(string $lang, string $q): ?array
    {
        $qNorm = Text::normalize($q);
        if ($qNorm === '') {
            return null;
        }
        $today = date('Y-m-d');
        $store = Store::get();
        $key = 'banners:' . \DeepFind\Project::id() . ':' . $lang . ':' . $today . ':' . \DeepFind\CacheVersion::get();
        $list = $store->get($key);
        if (!is_array($list)) {
            $list = array_values(array_filter(self::all($lang), static fn ($b) => $b['active'] && CustomResults::period($b, $today) === 'current'));
            usort($list, static fn ($a, $b) => $b['id'] <=> $a['id']);
            $store->set($key, $list, 300);
        }
        $qWords = Relevance::words($q, $lang);
        foreach ($list as $b) {
            if ($b['terms'] !== [] && BoostRules::termsMatch($b['terms'], $qNorm, $qWords, $lang)) {
                return $b;
            }
        }
        foreach ($list as $b) {
            if ($b['is_default']) {
                return $b;
            }
        }
        return null;
    }

    /** Ce que reçoit le widget (aucun champ interne). */
    public static function payload(array $b): array
    {
        return $b['mode'] === 'code'
            ? ['id' => $b['id'], 'mode' => 'code', 'html' => $b['html']]
            : ['id' => $b['id'], 'mode' => 'visual', 'desktop' => $b['image_desktop'], 'mobile' => $b['image_mobile'] ?: $b['image_desktop'],
                'alt' => $b['alt'], 'link' => $b['link_url'], 'new_window' => $b['new_window']];
    }

    /** @return array{0:?array, 1:list<string>} */
    public static function sanitize(array $in, string $lang): array
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
        $terms = [];
        foreach (array_slice((array) ($in['terms'] ?? []), 0, 200) as $t) {
            $term = mb_strtolower(mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($t['term'] ?? ''))), 0, 100, 'UTF-8'), 'UTF-8');
            $match = ($t['match'] ?? '') === 'broad' ? 'broad' : 'exact';
            if (Text::normalize($term) !== '') {
                $terms[$term . '|' . $match] = ['term' => $term, 'match' => $match];
            }
        }
        $isDefault = !empty($in['is_default']);
        if ($terms === [] && !$isDefault) {
            $errors[] = 'Ajoutez au moins un terme de recherche (ou faites-en la bannière par défaut).';
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
                $errors[] = 'Saisissez le code HTML de la bannière.';
            }
        }
        if ($errors !== []) {
            return [null, $errors];
        }
        return [[
            'name' => $name, 'active' => !empty($in['active']), 'is_default' => $isDefault, 'starts_at' => $start, 'ends_at' => $end,
            'terms' => array_values($terms), 'mode' => $mode, 'image_desktop' => $mode === 'visual' ? $desktop : '', 'image_mobile' => $mode === 'visual' ? $mobile : '',
            'alt' => mb_substr(trim((string) ($in['alt'] ?? '')), 0, 190, 'UTF-8'), 'link_url' => $mode === 'visual' ? $link : '',
            'new_window' => !empty($in['new_window']), 'html' => $html,
        ], []];
    }

    private static function date(mixed $v): ?string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
    }

    public static function save(string $lang, array $b, ?int $id): int
    {
        $row = [
            'name' => $b['name'], 'active' => $b['active'] ? 1 : 0, 'is_default' => $b['is_default'] ? 1 : 0, 'starts_at' => $b['starts_at'], 'ends_at' => $b['ends_at'],
            'terms' => Text::json($b['terms']), 'mode' => $b['mode'], 'image_desktop' => $b['image_desktop'], 'image_mobile' => $b['image_mobile'],
            'alt' => $b['alt'], 'link_url' => $b['link_url'], 'new_window' => $b['new_window'] ? 1 : 0, 'html' => $b['html'], 'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($id) {
            Db::update('banners', $row, 'id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]);
        } else {
            $id = Db::insert('banners', $row + ['project_id' => \DeepFind\Project::id(), 'lang' => $lang, 'created_at' => $row['updated_at']]);
        }
        \DeepFind\CacheVersion::bump();
        return (int) $id;
    }

    public static function delete(int $id, string $lang): void
    {
        Db::exec('DELETE FROM banners WHERE id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]);
        \DeepFind\CacheVersion::bump();
    }

    /**
     * Enregistre une image téléversée dans public/uploads/banners/<projet>/ (nom = empreinte du contenu).
     * @return array{0:?string, 1:?string} [adresse publique, erreur]
     */
    public static function storeUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return [null, 'Fichier manquant ou invalide.'];
        }
        if ((int) $file['size'] > self::MAX_UPLOAD) {
            return [null, sprintf('Image trop lourde (%d Ko, maximum %d Ko).', (int) ceil($file['size'] / 1024), self::MAX_UPLOAD / 1024)];
        }
        $info = @getimagesize((string) $file['tmp_name']);
        $ext = $info ? (self::IMAGE_TYPES[$info[2]] ?? null) : null;
        if ($ext === null) {
            return [null, 'Format non accepté : JPG, PNG, GIF ou WebP uniquement.'];
        }
        $dir = '/uploads/banners/' . \DeepFind\Project::id();
        $abs = APP_ROOT . '/public' . $dir;
        if (!is_dir($abs) && !mkdir($abs, 0775, true) && !is_dir($abs)) {
            return [null, 'Dossier de stockage inaccessible.'];
        }
        $name = sha1_file((string) $file['tmp_name']) . '.' . $ext;
        if (!move_uploaded_file((string) $file['tmp_name'], $abs . '/' . $name)) {
            return [null, 'Enregistrement du fichier impossible.'];
        }
        $base = rtrim((string) \DeepFind\Config::get('APP_URL', ''), '/') . rtrim((string) \DeepFind\Config::get('APP_BASE_PATH', ''), '/');
        return [$base . $dir . '/' . $name, null];
    }

    /** @return array<int, array{impressions:int, clicks:int, ctr:?float}> */
    public static function stats(string $lang, array $ids, int $days): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        foreach ($ids as $id) {
            $out[(int) $id] = ['impressions' => 0, 'clicks' => 0, 'ctr' => null];
        }
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $ids = array_map('intval', $ids);
        foreach (Db::all("SELECT banner_id AS id, COUNT(*) AS n FROM search_logs WHERE project_id = ? AND lang = ? AND created_at >= ? AND is_final = 1 AND banner_id IN ($in) GROUP BY banner_id",
            [\DeepFind\Project::id(), $lang, $since, ...$ids]) as $r) {
            $out[(int) $r['id']]['impressions'] = (int) $r['n'];
        }
        foreach (Db::all("SELECT doc_id AS id, COUNT(*) AS n FROM click_logs WHERE project_id = ? AND lang = ? AND created_at >= ? AND doc_type = 'banner' AND doc_id IN ($in) GROUP BY doc_id",
            [\DeepFind\Project::id(), $lang, $since, ...array_map('strval', $ids)]) as $r) {
            $out[(int) $r['id']]['clicks'] = (int) $r['n'];
        }
        foreach ($out as &$s) {
            $s['ctr'] = $s['impressions'] > 0 ? round(100 * $s['clicks'] / $s['impressions'], 1) : null;
        }
        unset($s);
        return $out;
    }
}
