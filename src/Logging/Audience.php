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
 */
final class Audience
{
    public const DEVICES = ['mobile' => 'Mobile', 'tablet' => 'Tablette', 'desktop' => 'Ordinateur'];

    /** Colonnes cumulables de audience_daily. */
    public const METRICS = ['visitors', 'pageviews', 'searchers', 'searches', 'clickers', 'clicks', 'searchers_1', 'searchers_2_3', 'searchers_4p'];

    /** Types de mesure, activables séparément (en cas de trop forte sollicitation du serveur). */
    public const KINDS = ['pageviews' => 'Pages vues', 'searches' => 'Recherches', 'clicks' => 'Clics sur les résultats'];

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
    public static function visitorHash(string $ip, string $ua, string $day): string
    {
        return substr(hash_hmac('sha256', $ip . "\n" . $ua . "\n" . \DeepFind\Project::id(), self::salt($day)), 0, 32);
    }

    /**
     * Compte une page vue, une recherche ou un clic pour le visiteur (empreinte du jour).
     * @param 'pageview'|'search'|'click' $kind
     */
    public static function record(string $kind, string $ip, string $ua, string $deviceHint = '', ?string $day = null): void
    {
        if (!self::enabled() || $ip === '') {
            return;
        }
        $day ??= date('Y-m-d');
        $inc = ['pageviews' => (int) ($kind === 'pageview'), 'searches' => (int) ($kind === 'search'), 'clicks' => (int) ($kind === 'click')];
        // Le type d'appareil reste celui du premier signal de la journée
        Db::exec(
            'INSERT INTO audience_visitors (project_id, day, vhash, device, pageviews, searches, clicks) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE pageviews = pageviews + VALUES(pageviews), searches = searches + VALUES(searches), clicks = clicks + VALUES(clicks)',
            [\DeepFind\Project::id(), $day, self::visitorHash($ip, $ua, $day), self::device($ua, $deviceHint), $inc['pageviews'], $inc['searches'], $inc['clicks']]
        );
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
        $today ??= date('Y-m-d');
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
                SUM(searches BETWEEN 2 AND 3) AS searchers_2_3, SUM(searches >= 4) AS searchers_4p';
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
