<?php

declare(strict_types=1);

namespace DeepFind\Logging;

use DeepFind\Db;
use DeepFind\Settings;
use DeepFind\Store\KvStore;
use DeepFind\Store\Store;

/**
 * Mesure d'audience anonyme : statistiques globales quotidiennes, sans cookie ni donnée personnelle.
 *
 * - Un visiteur est reconnu pendant UNE journée par une empreinte : HMAC(IP + navigateur + projet) avec un sel
 *   aléatoire propre au jour. L'IP n'est jamais stockée ; rien n'est déposé sur l'appareil du visiteur.
 * - Chaque jour passé est agrégé (audience_daily : totaux par type d'appareil), puis les empreintes du jour et
 *   son sel sont supprimés : il devient impossible de recalculer une empreinte ou de suivre un visiteur d'un jour à l'autre.
 * - La « journée de mesure » va d'une heure réglable (4 h par défaut, heure de Paris) à la même heure le lendemain :
 *   elle porte la date de son début (mesure du 24/11 = 24/11 4 h → 25/11 4 h).
 * - Parcours d'achat : ouverture de la barre de recherche, fermeture sans clic, recherches sans résultat, clics sur
 *   les carrousels, ajouts au panier, commandes (montants en centimes), comptés pour la journée puis agrégés.
 */
final class Audience
{
    public const DEVICES = ['mobile' => 'Mobile', 'tablet' => 'Tablette', 'desktop' => 'Ordinateur'];

    /** Colonnes cumulables de audience_daily. */
    public const METRICS = ['visitors', 'pageviews', 'searchers', 'searches', 'clickers', 'clicks', 'searchers_1', 'searchers_2_3', 'searchers_4p',
        'bar_openers', 'closers', 'zero_searchers', 'zero_leavers', 'carousel_clickers', 'carters', 'buyers', 'orders', 'revenue', 'abandoners',
        'searcher_carters', 'searcher_buyers', 'searcher_orders', 'searcher_revenue', 'searcher_abandoners'];

    /** Compteurs d'un visiteur pour la journée (audience_visitors). */
    public const COUNTERS = ['pageviews', 'searches', 'clicks', 'bar_opens', 'closes', 'zero_searches', 'carousel_clicks', 'carts', 'cart_value', 'orders', 'revenue'];

    /** Types de mesure, activables séparément (en cas de trop forte sollicitation du serveur). */
    public const KINDS = ['pageviews' => 'Pages vues', 'searches' => 'Recherches', 'clicks' => 'Clics sur les résultats',
        'commerce' => 'Panier et commandes'];

    /** Heure de changement de la clé du jour par défaut (heure de Paris). */
    public const DEFAULT_DAY_HOUR = 4;

    /** Pages qui envoient le signal « page vue ». */
    public const PAGES = ['all' => 'Toutes les pages', 'home' => 'Page d\'accueil uniquement', 'paths' => 'Pages choisies'];

    /** Mesure d'audience activée (interrupteur général) ? */
    public static function enabled(): bool
    {
        return Settings::int('audience_enabled') === 1;
    }

    /** Ce type de mesure est-il actif ? @param 'pageviews'|'searches'|'clicks' $kind */
    public static function tracks(string $kind): bool
    {
        return self::enabled() && isset(self::KINDS[$kind]) && Settings::int('audience_' . $kind) === 1;
    }

    /**
     * Débuts d'adresse des « pages choisies » (un par ligne) : « /fr/plongee » ou une adresse complète
     * (seul le chemin est gardé). 50 au plus.
     * @return list<string>
     */
    public static function paths(?string $raw = null): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $raw ?? Settings::get('audience_paths')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $path = preg_match('~^https?://~i', $line) ? (string) (parse_url($line, PHP_URL_PATH) ?? '/') : $line;
            $path = '/' . ltrim(mb_substr($path, 0, 300, 'UTF-8'), '/');
            if ($path !== '/' && !in_array($path, $out, true)) {
                $out[] = $path;
            }
        }
        return array_slice($out, 0, 50);
    }

    /**
     * Réglages envoyés au widget (/config) : false si les pages vues ne sont pas mesurées.
     * @return array{pages:string, paths:list<string>, home:bool}|false
     */
    public static function widgetConfig(): array|false
    {
        if (!self::tracks('pageviews')) {
            return false;
        }
        $pages = Settings::get('audience_pages');
        $pages = isset(self::PAGES[$pages]) ? $pages : 'all';
        return ['pages' => $pages, 'paths' => $pages === 'paths' ? self::paths() : [], 'home' => $pages === 'paths' && Settings::int('audience_paths_home') === 1];
    }

    /**
     * Type d'appareil. Indication du widget (taille d'écran, écran tactile) prioritaire, sinon déduit du user-agent.
     * (iPad récents : user-agent « Macintosh », d'où l'intérêt de l'indication du widget.)
     */
    public static function device(string $ua, string $hint = ''): string
    {
        $hint = ['m' => 'mobile', 't' => 'tablet', 'd' => 'desktop'][$hint] ?? '';
        if ($hint !== '') {
            return $hint;
        }
        if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle|Android(?!.*Mobi)/i', $ua) === 1) {
            return 'tablet';
        }
        return preg_match('/Mobi|iPhone|iPod|Android|Windows Phone|Opera Mini/i', $ua) === 1 ? 'mobile' : 'desktop';
    }

    // ------------------------------------------------------------------ Journée de mesure

    /** Heure de changement de la clé du jour applicable à l'instant $ts (changement programmé compris). */
    public static function dayHour(?int $ts = null): int
    {
        $ts ??= time();
        $next = Settings::get('audience_day_hour_next');
        $at = Settings::int('audience_day_hour_at');
        if ($next !== '' && $at > 0 && $ts >= $at) {
            return max(0, min(23, (int) $next));
        }
        $h = Settings::get('audience_day_hour');
        return $h === '' ? self::DEFAULT_DAY_HOUR : max(0, min(23, (int) $h));
    }

    /** Journée de mesure de l'instant $ts : date (heure de Paris) de l'instant moins l'heure de changement. */
    public static function measureDay(?int $ts = null): string
    {
        $ts ??= time();
        return (new \DateTimeImmutable('@' . ($ts - self::dayHour($ts) * 3600)))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
    }

    /** Début (horodatage) de la journée de mesure en cours, et de la suivante. @return array{0:int, 1:int} */
    public static function dayBounds(?int $ts = null): array
    {
        $ts ??= time();
        $h = self::dayHour($ts);
        $tz = new \DateTimeZone(date_default_timezone_get());
        $start = (new \DateTimeImmutable(self::measureDay($ts) . sprintf(' %02d:00:00', $h), $tz))->getTimestamp();
        $end = (new \DateTimeImmutable(self::measureDay($ts) . sprintf(' %02d:00:00', $h), $tz))->modify('+1 day')->getTimestamp();
        return [$start, $end];
    }

    /**
     * Nouvelle heure de changement de la clé : appliquée à la PROCHAINE bascule (jamais en cours de journée, pour ne pas compter
     * deux fois les visiteurs). La journée de la bascule est alors plus courte ou plus longue.
     * @return ?int horodatage de la bascule, ou null si rien ne change
     */
    public static function scheduleDayHour(int $hour, ?int $now = null): ?int
    {
        $now ??= time();
        $hour = max(0, min(23, $hour));
        self::promoteDayHour($now);
        if ($hour === self::dayHour($now)) {
            Settings::set('audience_day_hour_next', '');
            Settings::set('audience_day_hour_at', '0');
            return null;
        }
        $at = self::dayBounds($now)[1];
        Settings::set('audience_day_hour_next', (string) $hour);
        Settings::set('audience_day_hour_at', (string) $at);
        return $at;
    }

    /** Changement programmé échu : devient l'heure en vigueur (appelé chaque minute par aggregate()). */
    public static function promoteDayHour(?int $now = null): void
    {
        $now ??= time();
        $next = Settings::get('audience_day_hour_next');
        $at = Settings::int('audience_day_hour_at');
        if ($next !== '' && $at > 0 && $now >= $at) {
            Settings::set('audience_day_hour', (string) max(0, min(23, (int) $next)));
            Settings::set('audience_day_hour_next', '');
            Settings::set('audience_day_hour_at', '0');
        }
    }

    // ------------------------------------------------------------------ Empreinte du jour

    /** Sel du jour (créé à la première visite du jour, partagé par tous les serveurs via la base). */
    private static function salt(string $day): string
    {
        $store = Store::get();
        $cached = $store->get('aud:salt:' . $day);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        Db::exec('INSERT IGNORE INTO audience_salts (day, salt) VALUES (?, ?)', [$day, bin2hex(random_bytes(32))]);
        $salt = (string) Db::value('SELECT salt FROM audience_salts WHERE day = ?', [$day]);
        $store->set('aud:salt:' . $day, $salt, 3600);
        return $salt;
    }

    /** Empreinte du jour d'un visiteur (32 caractères hexadécimaux). */
    public static function visitorHash(string $ip, string $ua, ?string $day = null): string
    {
        $day ??= self::measureDay();
        return substr(hash_hmac('sha256', $ip . "\n" . $ua . "\n" . \DeepFind\Project::id(), self::salt($day)), 0, 32);
    }

    /**
     * Compte une page vue, une recherche ou un clic pour le visiteur (empreinte du jour).
     * @param 'pageview'|'search'|'click' $kind
     */
    public static function record(string $kind, string $ip, string $ua, string $deviceHint = '', ?string $day = null): void
    {
        $map = ['pageview' => 'pageviews', 'search' => 'searches', 'click' => 'clicks'];
        if (isset($map[$kind])) {
            self::bump([$map[$kind] => 1], $ip, $ua, $deviceHint, $day);
        }
    }

    /**
     * Ajoute des compteurs à la journée du visiteur (COUNTERS : pageviews, searches, carts, revenue…).
     * @param array<string,int> $inc
     */
    public static function bump(array $inc, string $ip, string $ua, string $deviceHint = '', ?string $day = null): void
    {
        if (!self::enabled() || $ip === '') {
            return;
        }
        $inc = array_intersect_key($inc, array_flip(self::COUNTERS));
        if ($inc === []) {
            return;
        }
        $day ??= self::measureDay();
        $cols = array_keys($inc);
        // Le type d'appareil reste celui du premier signal de la journée
        Db::exec(
            'INSERT INTO audience_visitors (project_id, day, vhash, device, ' . implode(', ', $cols) . ') VALUES (?, ?, ?, ?' . str_repeat(', ?', count($cols)) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($c) => "$c = $c + VALUES($c)", $cols)),
            [\DeepFind\Project::id(), $day, self::visitorHash($ip, $ua, $day), self::device($ua, $deviceHint), ...array_map('intval', array_values($inc))]
        );
    }

    // ------------------------------------------------------------------ Recherches qui rapportent, produits

    /** Dernière recherche du visiteur dans la journée (attribution des paniers et commandes), gardée en mémoire. */
    public static function lastTerm(string $vhash, ?string $set = null): string
    {
        $key = 'aud:lt:' . \DeepFind\Project::id() . ':' . $vhash;
        $store = Store::get();
        if ($set !== null) {
            $store->set($key, $set, 30 * 3600);
            return $set;
        }
        $v = $store->get($key);
        return is_string($v) ? $v : '';
    }

    /** @param array<string,int> $inc searches, carts, orders, revenue */
    public static function term(string $term, array $inc, ?string $day = null): void
    {
        $term = mb_substr(trim($term), 0, 190, 'UTF-8');
        $inc = array_intersect_key($inc, array_flip(['searches', 'carts', 'orders', 'revenue']));
        if ($term === '' || $inc === []) {
            return;
        }
        $cols = array_keys($inc);
        Db::exec('INSERT INTO audience_terms (project_id, day, term, ' . implode(', ', $cols) . ') VALUES (?, ?, ?' . str_repeat(', ?', count($cols)) . ')
            ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($c) => "$c = $c + VALUES($c)", $cols)),
            [\DeepFind\Project::id(), $day ?? self::measureDay(), $term, ...array_map('intval', array_values($inc))]);
    }

    /** Recherche tapée : le compte de « pal » passe à « palme » (une recherche, comptée sur son mot final). */
    public static function moveTerm(string $from, string $to, ?string $day = null): void
    {
        $day ??= self::measureDay();
        $from = mb_substr(trim($from), 0, 190, 'UTF-8');
        $n = Db::exec('UPDATE audience_terms SET searches = searches - 1 WHERE project_id = ? AND day = ? AND term = ? AND searches > 0', [\DeepFind\Project::id(), $day, $from]);
        Db::exec('DELETE FROM audience_terms WHERE project_id = ? AND day = ? AND term = ? AND searches = 0 AND carts = 0 AND orders = 0', [\DeepFind\Project::id(), $day, $from]);
        if ($n > 0) {
            self::term($to, ['searches' => 1], $day);
        }
    }

    /** @param array<string,int> $inc clicks, carts, orders, revenue */
    public static function product(string $productId, string $name, array $inc, ?string $day = null): void
    {
        $productId = substr(trim($productId), 0, 64);
        $inc = array_intersect_key($inc, array_flip(['clicks', 'carts', 'orders', 'revenue']));
        if ($productId === '' || $inc === []) {
            return;
        }
        $cols = array_keys($inc);
        Db::exec('INSERT INTO audience_products (project_id, day, product_id, name, ' . implode(', ', $cols) . ') VALUES (?, ?, ?, ?' . str_repeat(', ?', count($cols)) . ')
            ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($c) => "$c = $c + VALUES($c)", $cols)) . ", name = IF(VALUES(name) <> '', VALUES(name), name)",
            [\DeepFind\Project::id(), $day ?? self::measureDay(), $productId, mb_substr($name, 0, 255, 'UTF-8'), ...array_map('intval', array_values($inc))]);
    }

    // ------------------------------------------------------------------ Chiffre d'affaires par emplacement

    /** Emplacements : search, carousel:<id>, direct (aucun clic DeepFind sur ce produit dans la journée). */
    public static function placementLabel(string $placement, string $lang = 'fr'): string
    {
        if ($placement === 'search') {
            return 'Recherche';
        }
        if (str_starts_with($placement, 'carousel:')) {
            $c = \DeepFind\Rules\Carousels::find((int) substr($placement, 9), $lang);
            return 'Carrousel « ' . ($c['name'] ?? '#' . substr($placement, 9)) . ' »';
        }
        return 'Sans clic DeepFind (navigation du site)';
    }

    /** @param array<string,int> $inc clicks, carts, orders, revenue */
    public static function attrib(string $placement, string $productId, array $inc, ?string $day = null): void
    {
        $productId = substr(trim($productId), 0, 64);
        $placement = substr($placement, 0, 40);
        $inc = array_intersect_key($inc, array_flip(['clicks', 'carts', 'orders', 'revenue']));
        if ($productId === '' || $placement === '' || $inc === []) {
            return;
        }
        $cols = array_keys($inc);
        Db::exec('INSERT INTO audience_attrib (project_id, day, placement, product_id, ' . implode(', ', $cols) . ') VALUES (?, ?, ?, ?' . str_repeat(', ?', count($cols)) . ')
            ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($c) => "$c = $c + VALUES($c)", $cols)),
            [\DeepFind\Project::id(), $day ?? self::measureDay(), $placement, $productId, ...array_map('intval', array_values($inc))]);
    }

    /** Dernier emplacement où le visiteur a cliqué sur ce produit dans la journée (mémoire seulement). */
    public static function lastPlacement(string $vhash, string $productId, ?string $set = null): string
    {
        $key = 'aud:pl:' . \DeepFind\Project::id() . ':' . $vhash . ':' . md5($productId);
        $store = Store::get();
        if ($set !== null) {
            $store->set($key, $set, 30 * 3600);
            return $set;
        }
        $v = $store->get($key);
        return is_string($v) && $v !== '' ? $v : 'direct';
    }

    /**
     * Produit du catalogue d'après son SKU (ou celui d'une déclinaison), pour les ajouts au panier et commandes envoyés par le site :
     * identifiant, nom, prix. Gardé une heure en mémoire. @return ?array{id:string, name:string, price:float}
     */
    public static function productBySku(string $lang, string $sku): ?array
    {
        $sku = mb_strtoupper(trim($sku), 'UTF-8');
        if ($sku === '') {
            return null;
        }
        $store = Store::get();
        $key = 'aud:sku:' . \DeepFind\Project::id() . ':' . $lang . ':' . md5($sku);
        $hit = $store->get($key);
        if (is_array($hit)) {
            return $hit['id'] === '' ? null : $hit;
        }
        $found = ['id' => '', 'name' => '', 'price' => 0.0];
        try {
            $res = \DeepFind\Meili\MeiliClient::search()->multiSearch([['indexUid' => \DeepFind\Config::productIndex($lang), 'q' => '',
                'filter' => [\DeepFind\Search\Filter::in('all_skus', [$sku])], 'limit' => 1, 'attributesToRetrieve' => ['id', 'name', 'final_price']]]);
            $doc = $res[0]['hits'][0] ?? null;
            if (is_array($doc)) {
                $found = ['id' => (string) $doc['id'], 'name' => (string) ($doc['name'] ?? ''), 'price' => (float) ($doc['final_price'] ?? 0)];
            }
        } catch (\Throwable $e) {
            error_log('[deepfind] audience : produit ' . $e->getMessage());
        }
        $store->set($key, $found, 3600);
        return $found['id'] === '' ? null : $found;
    }

    /** Montant en euros (texte du site : « 54,90 », « 1 234.5 € ») → centimes, 0 si illisible. */
    public static function cents(mixed $v): int
    {
        if (is_int($v) || is_float($v)) {
            return max(0, (int) round($v * 100));
        }
        $s = preg_replace('/[^\d,.\-]/', '', (string) $v) ?? '';
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = strrpos($s, ',') > strrpos($s, '.') ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
        } else {
            $s = str_replace(',', '.', $s);
        }
        return is_numeric($s) ? max(0, min(100000000, (int) round((float) $s * 100))) : 0;
    }

    /**
     * Recherche « nouvelle » ? Pendant la frappe, « pal », « palm », « palme » ne comptent qu'une fois
     * (même identifiant de page sid, moins de 20 s, l'une prolonge l'autre, mêmes filtres).
     */
    public static function isNewSearch(KvStore $store, string $sid, string $qNorm, string $filters): bool
    {
        if ($sid === '' || $qNorm === '') {
            return $qNorm !== '';
        }
        $prev = $store->get('aud:last:' . $sid);
        $store->set('aud:last:' . $sid, ['q' => $qNorm, 'f' => $filters, 't' => time()], 60);
        if (!is_array($prev) || (time() - (int) $prev['t']) > 20 || ($prev['f'] ?? '') !== $filters) {
            return true;
        }
        $a = (string) $prev['q'];
        return !($a === $qNorm || str_starts_with($qNorm, $a) || str_starts_with($a, $qNorm));
    }

    /**
     * Agrège les jours passés (tous projets) dans audience_daily, puis supprime leurs empreintes et leurs sels.
     * Appelé chaque minute par schedule:run (ne fait rien s'il n'y a pas de jour passé à traiter).
     * @return int jours agrégés
     */
    public static function aggregate(?string $today = null): int
    {
        if ($today === null) {
            self::promoteDayHour();
            $today = self::measureDay();
        }
        $days = (int) Db::value('SELECT COUNT(DISTINCT day) FROM audience_visitors WHERE day < ?', [$today]);
        if ($days > 0) {
            $pdo = Db::pdo();
            $pdo->beginTransaction();
            try {
                Db::exec(
                    'INSERT INTO audience_daily (project_id, day, device, ' . implode(', ', self::METRICS) . ')
                     SELECT project_id, day, device, ' . self::aggregateSelect() . '
                     FROM audience_visitors WHERE day < ? GROUP BY project_id, day, device
                     ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($m) => "$m = $m + VALUES($m)", self::METRICS)),
                    [$today]
                );
                Db::exec('DELETE FROM audience_visitors WHERE day < ?', [$today]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
        Db::exec('DELETE FROM audience_salts WHERE day < ?', [$today]);
        return $days;
    }

    private static function aggregateSelect(): string
    {
        return 'COUNT(*) AS visitors, SUM(pageviews) AS pageviews, SUM(searches > 0) AS searchers, SUM(searches) AS searches,
                SUM(clicks > 0) AS clickers, SUM(clicks) AS clicks, SUM(searches = 1) AS searchers_1,
                SUM(searches BETWEEN 2 AND 3) AS searchers_2_3, SUM(searches >= 4) AS searchers_4p,
                SUM(bar_opens > 0) AS bar_openers, SUM(closes > 0 AND clicks = 0) AS closers, SUM(zero_searches > 0) AS zero_searchers,
                SUM(zero_searches > 0 AND clicks = 0 AND carousel_clicks = 0 AND carts = 0 AND orders = 0) AS zero_leavers,
                SUM(carousel_clicks > 0) AS carousel_clickers, SUM(carts > 0) AS carters, SUM(orders > 0) AS buyers, SUM(orders) AS orders,
                SUM(revenue) AS revenue, SUM(carts > 0 AND orders = 0) AS abandoners, SUM(searches > 0 AND carts > 0) AS searcher_carters,
                SUM(searches > 0 AND orders > 0) AS searcher_buyers, SUM(IF(searches > 0, orders, 0)) AS searcher_orders,
                SUM(IF(searches > 0, revenue, 0)) AS searcher_revenue, SUM(searches > 0 AND carts > 0 AND orders = 0) AS searcher_abandoners';
    }

    /**
     * Statistiques du projet courant, jour par jour et par appareil, de $from à $to inclus
     * (la journée en cours est calculée en direct).
     * @return array<string, array<string, array<string,int>>> [jour => [appareil => [métrique => valeur]]]
     */
    public static function series(string $from, string $to): array
    {
        $pid = \DeepFind\Project::id();
        $out = [];
        $add = static function (array $r) use (&$out): void {
            foreach (self::METRICS as $m) {
                $out[$r['day']][$r['device']][$m] = ($out[$r['day']][$r['device']][$m] ?? 0) + (int) $r[$m];
            }
        };
        foreach (Db::all('SELECT day, device, ' . implode(', ', self::METRICS) . ' FROM audience_daily WHERE project_id = ? AND day BETWEEN ? AND ?', [$pid, $from, $to]) as $r) {
            $add($r);
        }
        // Jours pas encore agrégés (aujourd'hui, ou planificateur arrêté)
        $live = Db::all('SELECT day, device, ' . self::aggregateSelect() . ' FROM audience_visitors WHERE project_id = ? AND day BETWEEN ? AND ? GROUP BY day, device', [$pid, $from, $to]);
        foreach ($live as $r) {
            $add($r);
        }
        ksort($out);
        return $out;
    }

    /**
     * Recherches qui rapportent sur la période (ajouts au panier et commandes attribués à la dernière recherche du visiteur).
     * @return list<array{term:string, searches:int, carts:int, orders:int, revenue:int}>
     */
    public static function topTerms(string $from, string $to, int $limit = 20): array
    {
        return array_map(static fn ($r) => ['term' => (string) $r['term'], 'searches' => (int) $r['searches'], 'carts' => (int) $r['carts'],
            'orders' => (int) $r['orders'], 'revenue' => (int) $r['revenue']],
            Db::all('SELECT term, SUM(searches) AS searches, SUM(carts) AS carts, SUM(orders) AS orders, SUM(revenue) AS revenue FROM audience_terms
                WHERE project_id = ? AND day BETWEEN ? AND ? GROUP BY term HAVING SUM(carts) > 0 OR SUM(orders) > 0
                ORDER BY revenue DESC, orders DESC, carts DESC LIMIT ' . max(1, $limit), [\DeepFind\Project::id(), $from, $to]));
    }

    /**
     * Produits souvent cliqués (recherche, carrousels) mais jamais mis au panier sur la période : prix, photos ou fiche à revoir ?
     * @return list<array{product_id:string, name:string, clicks:int}>
     */
    public static function clickedNotCarted(string $from, string $to, int $limit = 20): array
    {
        return array_map(static fn ($r) => ['product_id' => (string) $r['product_id'], 'name' => (string) $r['name'], 'clicks' => (int) $r['clicks']],
            Db::all("SELECT product_id, MAX(name) AS name, SUM(clicks) AS clicks FROM audience_products
                WHERE project_id = ? AND day BETWEEN ? AND ? GROUP BY product_id HAVING SUM(carts) = 0 AND SUM(orders) = 0 AND SUM(clicks) >= 3
                ORDER BY clicks DESC LIMIT " . max(1, $limit), [\DeepFind\Project::id(), $from, $to]));
    }

    /** Des ajouts au panier ou commandes ont-ils été reçus sur la période (site branché) ? */
    public static function hasCommerce(string $from, string $to): bool
    {
        $pid = \DeepFind\Project::id();
        return (int) Db::value('SELECT COUNT(*) FROM audience_daily WHERE project_id = ? AND day BETWEEN ? AND ? AND (carters > 0 OR buyers > 0)', [$pid, $from, $to]) > 0
            || (int) Db::value('SELECT COUNT(*) FROM audience_visitors WHERE project_id = ? AND day BETWEEN ? AND ? AND (carts > 0 OR orders > 0)', [$pid, $from, $to]) > 0;
    }

    /**
     * Totaux de la période : par jour (tous appareils) et par appareil (tous jours).
     * @return array{days: array<string, array<string,int>>, devices: array<string, array<string,int>>, total: array<string,int>, mobile: array<string,int>}
     */
    public static function summary(string $from, string $to): array
    {
        $zero = array_fill_keys(self::METRICS, 0);
        $days = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $days[$d] = $zero;
        }
        $devices = array_fill_keys(array_keys(self::DEVICES), $zero);
        $total = $zero;
        $mobile = [];
        foreach (self::series($from, $to) as $day => $byDevice) {
            $mobile[$day] = (int) ($byDevice['mobile']['visitors'] ?? 0);
            foreach ($byDevice as $dev => $metrics) {
                foreach ($metrics as $m => $v) {
                    $days[$day][$m] += $v;
                    $devices[$dev][$m] += $v;
                    $total[$m] += $v;
                }
            }
        }
        return ['days' => $days, 'devices' => $devices, 'total' => $total, 'mobile' => $mobile];
    }
}
