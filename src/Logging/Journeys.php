<?php

declare(strict_types=1);

namespace DeepFind\Logging;

use DeepFind\Db;
use DeepFind\Settings;
use DeepFind\Store\Store;
use DeepFind\Text;

/**
 * Parcours des visiteurs (Analyse › Parcours) : chronologie datée de leur journée, SANS cookie ni stockage sur l'appareil.
 *
 * - Un visiteur est l'empreinte du jour de la mesure d'audience (Audience::visitorHash) : HMAC(IP + navigateur) avec une clé
 *   changée chaque jour puis détruite. On ne peut ni retrouver l'IP, ni relier deux journées.
 * - Seuls les visiteurs qui ont CHERCHÉ (requête dans la barre de recherche) ou CLIQUÉ SUR UN CARROUSEL sont gardés : avant ce
 *   « déclencheur », leurs pages vues restent en mémoire (TRIGGER_BUFFER, quelques heures) puis disparaissent sans être écrites.
 * - Les textes saisis (recherches) et les titres de pages sont masqués : e-mails, téléphones, numéros de carte, IBAN.
 * - Un parcours est supprimé RETENTION_HOURS après son dernier événement.
 */
final class Journeys
{
    /** Conservation d'un parcours après son dernier événement (heures) : 1 jour. */
    public const RETENTION_HOURS = 24;

    /** Événements gardés en mémoire avant le déclencheur (les plus récents), et pendant combien de temps (secondes). */
    public const TRIGGER_BUFFER = 25;
    public const BUFFER_TTL = 6 * 3600;

    /** Événements qui ouvrent un parcours. */
    public const TRIGGERS = ['search', 'carousel_click'];

    /** Libellés des types d'événements (écran Parcours). */
    public const TYPES = [
        'page' => 'Page vue', 'bar' => 'Barre de recherche ouverte', 'search' => 'Recherche', 'click' => 'Clic sur un résultat',
        'close' => 'Fenêtre de recherche fermée sans clic', 'carousel_click' => 'Clic dans un carrousel', 'popin' => 'Popin',
        'cart' => 'Ajout au panier', 'order' => 'Commande',
    ];

    /** Filtres de la liste des parcours. */
    public const FILTERS = ['all' => 'Tous', 'order' => 'Avec commande', 'abandon' => 'Panier sans commande', 'zero' => 'Recherche sans résultat',
        'carousel' => 'Clic dans un carrousel', 'no_click' => 'Recherche sans clic'];

    public static function enabled(): bool
    {
        return Audience::enabled() && Settings::int('journeys_enabled') === 1;
    }

    /**
     * Masque ce qui ressemble à une donnée personnelle dans un texte saisi : e-mail, téléphone (France et international),
     * numéro de carte bancaire (groupes de 4 chiffres), IBAN. Les références produit (SKU, EAN) restent lisibles.
     */
    public static function mask(string $text, int $max = 190): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', $text));
        $t = (string) preg_replace('/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.[\p{L}]{2,}/u', '[e-mail]', $t);
        $t = (string) preg_replace('/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){3,7}(?:[ ]?[A-Z0-9]{1,4})?\b/i', '[iban]', $t);
        $t = (string) preg_replace('/\b\d{4}(?:[ \-]\d{4}){3}\b/', '[carte]', $t);
        $t = (string) preg_replace('/(?<![\w])(?:\+\d{2}[ .\-]?|00\d{2}[ .\-]?|0)[1-9](?:[ .\-]?\d{2}){4}(?![\w])/', '[téléphone]', $t);
        return mb_substr($t, 0, $max, 'UTF-8');
    }

    /**
     * Ajoute un événement au parcours du visiteur (empreinte du jour $vhash).
     * Parcours déjà ouvert : écrit tout de suite. Sinon : déclencheur → parcours créé avec les pages vues gardées en mémoire ;
     * autre événement → gardé en mémoire seulement.
     * @param array<string,mixed> $data détails (q, n, id, name, path, title, value…)
     * @param ?int $atMs instant en millisecondes (maintenant par défaut)
     * @return ?int identifiant du parcours (null : pas de parcours)
     */
    public static function add(string $type, array $data, string $vhash, string $day, string $device, ?int $atMs = null): ?int
    {
        if (!self::enabled() || !isset(self::TYPES[$type]) || $vhash === '') {
            return null;
        }
        $atMs ??= (int) floor(microtime(true) * 1000);
        $pid = \DeepFind\Project::id();
        $store = Store::get();
        $bufKey = 'jb:' . $pid . ':' . $day . ':' . $vhash;
        $jid = self::journeyId($vhash, $day);
        if ($jid === null) {
            if (!in_array($type, self::TRIGGERS, true)) {
                // avant le déclencheur : en mémoire seulement (jamais écrit si le visiteur ne cherche pas)
                $buf = $store->get($bufKey);
                $buf = is_array($buf) ? $buf : [];
                $buf[] = ['t' => $type, 'd' => $data, 'at' => $atMs];
                $store->set($bufKey, array_slice($buf, -self::TRIGGER_BUFFER), self::BUFFER_TTL);
                return null;
            }
            $buf = $store->get($bufKey);
            $buf = is_array($buf) ? $buf : [];
            $store->delete($bufKey);
            $first = $buf[0] ?? ['t' => $type, 'd' => $data, 'at' => $atMs];
            $entry = '';
            foreach ($buf as $b) {
                if ($b['t'] === 'page') {
                    $entry = (string) ($b['d']['path'] ?? '');
                    break;
                }
            }
            $device = isset(Audience::DEVICES[$device]) ? $device : 'desktop';
            Db::exec('INSERT IGNORE INTO journeys (project_id, day, vhash, device, started_at, last_at, entry_path, trigger_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $day, $vhash, $device, self::dt((int) $first['at']), self::dt($atMs), mb_substr($entry, 0, 300, 'UTF-8'), $type === 'carousel_click' ? 'carousel' : 'search']);
            $jid = self::journeyId($vhash, $day, true);
            if ($jid === null) {
                return null;
            }
            foreach ($buf as $b) {
                self::write($jid, (string) $b['t'], (array) $b['d'], (int) $b['at']);
            }
        }
        // recherche tapée : « pal », « palm », « palme » ne font qu'une ligne (la dernière saisie, et son nombre de résultats)
        if ($type === 'search' && self::mergeSearch($jid, $data, $atMs)) {
            return $jid;
        }
        self::write($jid, $type, $data, $atMs);
        return $jid;
    }

    /** Identifiant du parcours du visiteur pour la journée (mémoire, puis base). */
    private static function journeyId(string $vhash, string $day, bool $fresh = false): ?int
    {
        $store = Store::get();
        $key = 'jid:' . \DeepFind\Project::id() . ':' . $day . ':' . $vhash;
        if (!$fresh) {
            $v = $store->get($key);
            if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                return (int) $v > 0 ? (int) $v : null;
            }
        }
        $id = Db::value('SELECT id FROM journeys WHERE project_id = ? AND day = ? AND vhash = ?', [\DeepFind\Project::id(), $day, $vhash]);
        $id = $id === null || $id === false ? 0 : (int) $id;
        // « pas de parcours » gardé peu de temps : le prochain déclencheur doit le voir
        $store->set($key, $id, $id > 0 ? 30 * 3600 : 20);
        return $id > 0 ? $id : null;
    }

    /** Écrit un événement et met à jour les compteurs du parcours. */
    private static function write(int $jid, string $type, array $data, int $atMs): void
    {
        $id = Db::insert('journey_events', ['journey_id' => $jid, 'at' => self::dt($atMs, true), 'type' => $type,
            'data' => \DeepFind\Text::json(self::clean($type, $data))]);
        $inc = match ($type) {
            'page' => ['pageviews' => 1],
            'search' => ['searches' => 1] + (((int) ($data['n'] ?? -1)) === 0 ? ['zero_searches' => 1] : []),
            'click' => ['clicks' => 1],
            'carousel_click' => ['carousel_clicks' => 1],
            'cart' => ['carts' => 1, 'cart_value' => (int) ($data['value'] ?? 0)],
            'order' => ['orders' => 1, 'revenue' => (int) ($data['value'] ?? 0)],
            default => [],
        };
        $set = ['last_at = GREATEST(last_at, ?)'];
        $params = [self::dt($atMs)];
        foreach ($inc as $col => $n) {
            $set[] = "$col = $col + ?";
            $params[] = max(0, $n);
        }
        Db::exec('UPDATE journeys SET ' . implode(', ', $set) . ' WHERE id = ?', [...$params, $jid]);
        if ($type === 'search') {
            Store::get()->set('jls:' . $jid, ['id' => $id, 'q' => Text::normalize((string) ($data['q'] ?? '')), 'f' => (string) ($data['f'] ?? ''),
                'n' => (int) ($data['n'] ?? -1), 't' => intdiv($atMs, 1000)], 3600);
        }
    }

    /** Suite de la recherche précédente (moins de 20 s, l'une prolonge l'autre, mêmes filtres) : met à jour sa ligne. */
    private static function mergeSearch(int $jid, array $data, int $atMs): bool
    {
        $store = Store::get();
        $prev = $store->get('jls:' . $jid);
        $q = Text::normalize((string) ($data['q'] ?? ''));
        $f = (string) ($data['f'] ?? '');
        if (!is_array($prev) || $q === '' || intdiv($atMs, 1000) - (int) $prev['t'] > 20 || (string) $prev['f'] !== $f) {
            return false;
        }
        $a = (string) $prev['q'];
        if (!($a === $q || str_starts_with($q, $a) || str_starts_with($a, $q))) {
            return false;
        }
        Db::exec('UPDATE journey_events SET data = ?, at = ? WHERE id = ? AND journey_id = ?',
            [\DeepFind\Text::json(self::clean('search', $data)), self::dt($atMs, true), (int) $prev['id'], $jid]);
        // sans résultat : compté une fois par recherche (la dernière saisie décide)
        $wasZero = (int) $prev['n'] === 0;
        $isZero = (int) ($data['n'] ?? -1) === 0;
        $zero = $isZero && !$wasZero ? 'zero_searches = zero_searches + 1, ' : (!$isZero && $wasZero ? 'zero_searches = GREATEST(zero_searches, 1) - 1, ' : '');
        Db::exec("UPDATE journeys SET {$zero}last_at = GREATEST(last_at, ?) WHERE id = ?", [self::dt($atMs), $jid]);
        $store->set('jls:' . $jid, ['id' => (int) $prev['id'], 'q' => $q, 'f' => $f, 'n' => (int) ($data['n'] ?? -1), 't' => intdiv($atMs, 1000)], 3600);
        return true;
    }

    /** Détails gardés par type d'événement (liste blanche, textes masqués et tronqués). */
    private static function clean(string $type, array $d): array
    {
        $s = static fn (string $k, int $max = 190) => mb_substr(trim((string) ($d[$k] ?? '')), 0, $max, 'UTF-8');
        $out = match ($type) {
            'page' => ['path' => $s('path', 300), 'title' => self::mask($s('title', 200), 160)],
            'search' => ['q' => self::mask($s('q')), 'n' => (int) ($d['n'] ?? 0), 'ap' => (int) ($d['ap'] ?? 0), 'f' => $s('f', 300)],
            'click' => ['q' => self::mask($s('q')), 'id' => $s('id', 64), 'name' => $s('name', 200), 'pos' => (int) ($d['pos'] ?? 0)],
            'close' => ['q' => self::mask($s('q'))],
            'carousel_click' => ['carousel' => $s('carousel', 190), 'id' => $s('id', 64), 'name' => $s('name', 200)],
            'popin' => ['popin' => $s('popin', 190), 'e' => $s('e', 2)],
            'cart' => ['sku' => $s('sku', 64), 'name' => $s('name', 200), 'qty' => max(1, (int) ($d['qty'] ?? 1)), 'value' => max(0, (int) ($d['value'] ?? 0))],
            'order' => ['value' => max(0, (int) ($d['value'] ?? 0)), 'items' => max(0, (int) ($d['items'] ?? 0))],
            default => [],
        };
        return array_filter($out, static fn ($v, $k) => $v !== '' && $v !== null && !($k === 'ap' && $v === 0), ARRAY_FILTER_USE_BOTH);
    }

    private static function dt(int $ms, bool $withMs = false): string
    {
        $s = date('Y-m-d H:i:s', intdiv($ms, 1000));
        return $withMs ? $s . '.' . str_pad((string) ($ms % 1000), 3, '0', STR_PAD_LEFT) : $s;
    }

    // ------------------------------------------------------------------ Lecture (extranet)

    /**
     * Parcours du projet courant, du plus récent au plus ancien.
     * @return array{rows: list<array>, total: int}
     */
    public static function list(string $filter = 'all', string $q = '', string $day = '', int $page = 1, int $perPage = 50): array
    {
        $where = ['j.project_id = ?'];
        $params = [\DeepFind\Project::id()];
        if ($day !== '') {
            $where[] = 'j.day = ?';
            $params[] = $day;
        }
        $where[] = match ($filter) {
            'order' => 'j.orders > 0',
            'abandon' => 'j.carts > 0 AND j.orders = 0',
            'zero' => 'j.zero_searches > 0',
            'carousel' => 'j.carousel_clicks > 0',
            'no_click' => 'j.searches > 0 AND j.clicks = 0 AND j.carousel_clicks = 0',
            default => '1 = 1',
        };
        $q = trim($q);
        if ($q !== '') {
            $where[] = "EXISTS (SELECT 1 FROM journey_events e WHERE e.journey_id = j.id AND e.type IN ('search', 'click', 'close', 'page', 'carousel_click', 'cart') AND e.data LIKE ?)";
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $sql = ' FROM journeys j WHERE ' . implode(' AND ', $where);
        $total = (int) Db::value('SELECT COUNT(*)' . $sql, $params);
        $page = max(1, $page);
        $rows = Db::all('SELECT j.*' . $sql . ' ORDER BY j.last_at DESC, j.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage), $params);
        // première recherche de chaque parcours (aperçu dans la liste)
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $firstQ = [];
        if ($ids !== []) {
            $in = implode(',', $ids);
            foreach (Db::all("SELECT journey_id, data FROM journey_events WHERE journey_id IN ($in) AND type = 'search' ORDER BY id") as $e) {
                $firstQ[(int) $e['journey_id']] ??= [];
                if (count($firstQ[(int) $e['journey_id']]) < 3) {
                    $firstQ[(int) $e['journey_id']][] = (string) (json_decode((string) $e['data'], true)['q'] ?? '');
                }
            }
        }
        foreach ($rows as &$r) {
            $r['queries'] = array_values(array_unique(array_filter($firstQ[(int) $r['id']] ?? [])));
            $r['label'] = self::label((string) $r['vhash']);
        }
        return ['rows' => $rows, 'total' => $total];
    }

    /** Parcours et ses événements, du premier au dernier. @return ?array{journey: array, events: list<array>} */
    public static function find(int $id): ?array
    {
        $j = Db::one('SELECT * FROM journeys WHERE id = ? AND project_id = ?', [$id, \DeepFind\Project::id()]);
        if (!$j) {
            return null;
        }
        $j['label'] = self::label((string) $j['vhash']);
        $events = [];
        foreach (Db::all('SELECT id, at, type, data FROM journey_events WHERE journey_id = ? ORDER BY at, id', [$id]) as $e) {
            $events[] = ['id' => (int) $e['id'], 'at' => (string) $e['at'], 'type' => (string) $e['type'], 'data' => (array) (json_decode((string) $e['data'], true) ?: [])];
        }
        return ['journey' => $j, 'events' => $events];
    }

    /** Nom affiché d'un visiteur : 6 caractères de son empreinte du jour (aucun lien avec son identité). */
    public static function label(string $vhash): string
    {
        return 'Visiteur ' . strtoupper(substr($vhash, 0, 6));
    }

    /** Journées encore consultables (parcours conservés), de la plus récente à la plus ancienne. @return list<string> */
    public static function days(): array
    {
        return array_map(static fn ($r) => (string) $r['day'], Db::all('SELECT DISTINCT day FROM journeys WHERE project_id = ? ORDER BY day DESC', [\DeepFind\Project::id()]));
    }

    /** Supprime les parcours dont le dernier événement date de plus de RETENTION_HOURS (tous projets). @return int parcours supprimés */
    public static function purge(?int $now = null): int
    {
        $limit = date('Y-m-d H:i:s', ($now ?? time()) - self::RETENTION_HOURS * 3600);
        $n = 0;
        do {
            $ids = array_map(static fn ($r) => (int) $r['id'], Db::all('SELECT id FROM journeys WHERE last_at < ? LIMIT 500', [$limit]));
            if ($ids === []) {
                break;
            }
            $in = implode(',', $ids);
            Db::exec("DELETE FROM journey_events WHERE journey_id IN ($in)");
            $n += Db::exec("DELETE FROM journeys WHERE id IN ($in)");
        } while (count($ids) === 500);
        return $n;
    }
}
