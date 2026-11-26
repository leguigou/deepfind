<?php

declare(strict_types=1);

namespace DeepFind\Push;

use DeepFind\Db;
use DeepFind\Settings;
use DeepFind\Text;

/**
 * Notifications (Web Push) : réglages de l'invitation (Paramètres › Notifications), abonnés, messages (Recommandations ›
 * Envoi de notifications), envoi programmé (schedule:run) et statistiques.
 */
final class Push
{
    public const FORMS = [
        'banner_bottom' => 'Bandeau en bas de l\'écran',
        'banner_top' => 'Bandeau en haut de l\'écran',
        'popin_center' => 'Popin au centre',
        'popin_corner' => 'Popin dans un coin',
        'bell' => 'Cloche flottante (le visiteur clique quand il veut ; sert aussi à se désabonner)',
        'block' => 'Bloc dans la page (sélecteur CSS)',
    ];
    public const DEVICES = [
        'phone' => 'Téléphones uniquement',
        'mobile' => 'Téléphones et tablettes',
        'all' => 'Tous les appareils (ordinateur compris)',
        'desktop' => 'Ordinateurs uniquement',
    ];
    public const POSITIONS = ['right' => 'À droite', 'left' => 'À gauche'];
    /** Événements de l'invitation et du navigateur (push_stats). */
    public const METRICS = ['iv' => 'Invitation affichée', 'ia' => 'Clic sur « Oui »', 'il' => 'Clic sur « Plus tard »', 'ig' => 'Notifications autorisées',
        'id' => 'Notifications bloquées', 'sub' => 'Abonnements', 'uns' => 'Désabonnements'];
    /** Abonné retiré après N échecs d'envoi consécutifs (hors 404 / 410 : retiré tout de suite). */
    public const MAX_FAILURES = 5;
    public const TEXT_FIELDS = ['push_title' => 190, 'push_text' => 400, 'push_accept' => 60, 'push_later' => 60, 'push_thanks' => 190];

    public static function enabled(): bool
    {
        return Settings::int('push_enabled') === 1;
    }

    /** Réglages envoyés au widget (/config) ; false si désactivé. */
    public static function widgetConfig(): array|false
    {
        if (!self::enabled() || !\DeepFind\Project::isActive()) {
            return false;
        }
        $pick = static fn (array $choices, string $k, string $def) => isset($choices[Settings::get($k)]) ? Settings::get($k) : $def;
        return [
            'key' => WebPush::vapid()['public'],
            'form' => $pick(self::FORMS, 'push_form', 'banner_bottom'),
            'devices' => $pick(self::DEVICES, 'push_devices', 'phone'),
            'exclude_ios' => Settings::int('push_exclude_ios') === 1,
            'delay' => max(0, min(600, Settings::int('push_delay'))),
            'min_pages' => max(1, min(20, Settings::int('push_min_pages'))),
            'exclude' => self::paths(Settings::get('push_exclude_paths')),
            'title' => Settings::get('push_title'), 'text' => Settings::get('push_text'), 'accept' => Settings::get('push_accept'),
            'later' => Settings::get('push_later'), 'thanks' => Settings::get('push_thanks'),
            'image' => self::url(Settings::get('push_image')),
            'colors' => ['bg' => self::color(Settings::get('push_color_bg'), '#ffffff'), 'text' => self::color(Settings::get('push_color_text'), '#1f2937'),
                'button' => self::color(Settings::get('push_color_button'), '#e11d48')],
            'css' => Settings::get('push_css'),
            'remember_days' => max(0, min(365, Settings::int('push_remember_days'))),
            'selector' => Settings::get('push_selector'),
            'position' => $pick(self::POSITIONS, 'push_position', 'right'),
            'sw' => self::swPath(Settings::get('push_sw_path')),
        ];
    }

    // ------------------------------------------------------------------ Réglages (validation)

    /** Chemin du fichier sw.js sur le site : « /sw.js » ou un sous-dossier du site, fichier .js. */
    public static function swPath(string $p): string
    {
        $p = '/' . ltrim(trim($p), '/');
        return preg_match('~^/[A-Za-z0-9._\-/]{0,200}\.js$~', $p) && !str_contains($p, '..') ? $p : '/sw.js';
    }

    public static function color(string $c, string $def): string
    {
        return preg_match('/^#[0-9a-f]{6}$/i', trim($c)) ? strtolower(trim($c)) : $def;
    }

    public static function url(string $u): string
    {
        $u = trim($u);
        return $u !== '' && \DeepFind\Security\HtmlSanitizer::safeUrl($u) ? $u : '';
    }

    /** Débuts d'adresse exclus (un par ligne). @return list<string> */
    public static function paths(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $l) {
            $l = trim($l);
            if ($l !== '') {
                $out[] = mb_strtolower('/' . ltrim((string) (parse_url($l, PHP_URL_PATH) ?? $l), '/'), 'UTF-8');
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 30);
    }

    /** CSS personnalisé : sans balises ni imports (inséré comme texte dans une balise style). */
    public static function css(string $css): string
    {
        return mb_substr(trim(str_ireplace(['</style', '<style', '@import', 'expression(', 'javascript:'], '', strip_tags($css))), 0, 10000, 'UTF-8');
    }

    // ------------------------------------------------------------------ Abonnés

    /**
     * Abonnement envoyé par le widget (adresse d'envoi et clés publiques du navigateur).
     * @return bool vrai si l'abonnement est valable
     */
    public static function subscribe(string $lang, string $endpoint, string $p256dh, string $auth, string $device, string $channel, ?int $apiKeyId = null): bool
    {
        $endpoint = trim($endpoint);
        // adresse d'envoi du navigateur : https ; http://127.0.0.1 accepté seulement en environnement de test (faux service d'envoi)
        $testEndpoint = \DeepFind\Config::get('APP_ENV', '') === 'test' && preg_match('~^http://127\.0\.0\.1:\d+/~', $endpoint);
        if ((!preg_match('~^https://[A-Za-z0-9.\-]+(:\d+)?/~', $endpoint) && !$testEndpoint) || strlen($endpoint) > 2000
            || strlen(WebPush::b64uDecode($p256dh)) !== 65 || strlen(WebPush::b64uDecode($auth)) !== 16) {
            return false;
        }
        $device = in_array($device, ['phone', 'tablet', 'desktop'], true) ? $device : 'phone';
        $channel = isset(\DeepFind\Rules\Segments::CHANNELS[$channel]) ? $channel : 'direct';
        $now = date('Y-m-d H:i:s');
        $new = Db::exec('INSERT INTO push_subscriptions (project_id, lang, endpoint_hash, endpoint, p256dh, auth, device, channel, api_key_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), project_id = VALUES(project_id), lang = VALUES(lang),
                api_key_id = VALUES(api_key_id), failures = 0, updated_at = VALUES(updated_at)',
            [\DeepFind\Project::id(), $lang, hash('sha256', $endpoint), $endpoint, $p256dh, $auth, $device, $channel, $apiKeyId, $now, $now]);
        if ($new === 1) {
            self::stat('sub');
        }
        return true;
    }

    public static function unsubscribe(string $endpoint): bool
    {
        $n = Db::exec('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND project_id = ?', [hash('sha256', trim($endpoint)), \DeepFind\Project::id()]);
        if ($n > 0) {
            self::stat('uns');
        }
        return $n > 0;
    }

    /** Abonnés par appareil. @return array{total:int, phone:int, tablet:int, desktop:int} */
    public static function counts(string $lang): array
    {
        $out = ['total' => 0, 'phone' => 0, 'tablet' => 0, 'desktop' => 0];
        foreach (Db::all('SELECT device, COUNT(*) AS n FROM push_subscriptions WHERE project_id = ? AND lang = ? GROUP BY device', [\DeepFind\Project::id(), $lang]) as $r) {
            $out[(string) $r['device']] = (int) $r['n'];
            $out['total'] += (int) $r['n'];
        }
        return $out;
    }

    public static function stat(string $metric, int $n = 1, ?string $day = null): void
    {
        if (!isset(self::METRICS[$metric]) || $n <= 0) {
            return;
        }
        Db::exec('INSERT INTO push_stats (project_id, day, metric, n) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE n = n + VALUES(n)',
            [\DeepFind\Project::id(), $day ?? date('Y-m-d'), $metric, $n]);
    }

    /** Totaux des événements sur N jours. @return array<string,int> */
    public static function stats(int $days = 30): array
    {
        $out = array_fill_keys(array_keys(self::METRICS), 0);
        foreach (Db::all('SELECT metric, SUM(n) AS n FROM push_stats WHERE project_id = ? AND day >= ? GROUP BY metric',
            [\DeepFind\Project::id(), date('Y-m-d', time() - ($days - 1) * 86400)]) as $r) {
            $out[(string) $r['metric']] = (int) $r['n'];
        }
        return $out;
    }

    // ------------------------------------------------------------------ Messages

    /** @return list<array> */
    public static function campaigns(string $lang): array
    {
        return array_map([self::class, 'decode'], Db::all('SELECT * FROM push_campaigns WHERE project_id = ? AND lang = ? ORDER BY COALESCE(sent_at, scheduled_at, created_at) DESC, id DESC LIMIT 200',
            [\DeepFind\Project::id(), $lang]));
    }

    public static function find(int $id, string $lang): ?array
    {
        $r = Db::one('SELECT * FROM push_campaigns WHERE id = ? AND project_id = ? AND lang = ?', [$id, \DeepFind\Project::id(), $lang]);
        return $r ? self::decode($r) : null;
    }

    public static function blank(): array
    {
        return ['id' => 0, 'title' => '', 'body' => '', 'image' => '', 'url' => '', 'devices' => 'all', 'channels' => [], 'status' => 'draft',
            'scheduled_at' => null, 'sent_at' => null, 'targeted' => 0, 'sent' => 0, 'failed' => 0, 'shown' => 0, 'clicks' => 0];
    }

    private static function decode(array $r): array
    {
        return ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'body' => (string) $r['body'], 'image' => (string) $r['image'], 'url' => (string) $r['url'],
            'devices' => (string) $r['devices'], 'channels' => json_decode((string) ($r['channels'] ?? ''), true) ?: [], 'status' => (string) $r['status'],
            'scheduled_at' => $r['scheduled_at'] ?: null, 'sent_at' => $r['sent_at'] ?: null, 'targeted' => (int) $r['targeted'], 'sent' => (int) $r['sent'],
            'failed' => (int) $r['failed'], 'shown' => (int) $r['shown'], 'clicks' => (int) $r['clicks']];
    }

    /**
     * Message saisi dans l'extranet. $when : draft (brouillon), now (envoi dans la minute), at (date et heure).
     * @return array{0:?array, 1:list<string>}
     */
    public static function sanitize(array $in): array
    {
        $errors = [];
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 80, 'UTF-8');
        $body = mb_substr(trim((string) ($in['body'] ?? '')), 0, 250, 'UTF-8');
        if ($title === '') {
            $errors[] = 'Le titre est obligatoire.';
        }
        $url = trim((string) ($in['url'] ?? ''));
        if ($url !== '' && !preg_match('~^(https?://|/(?!/))~i', $url)) {
            $errors[] = 'Lien : adresse complète (https://…) ou chemin du site (/…).';
        }
        $image = self::url((string) ($in['image'] ?? ''));
        if (trim((string) ($in['image'] ?? '')) !== '' && $image === '') {
            $errors[] = 'Image : adresse complète (https://…) attendue.';
        }
        $when = (string) ($in['when'] ?? 'draft');
        $at = null;
        if ($when === 'at') {
            $ts = strtotime(str_replace('T', ' ', (string) ($in['scheduled_at'] ?? '')));
            if ($ts === false) {
                $errors[] = 'Choisissez la date et l\'heure d\'envoi.';
            } else {
                $at = date('Y-m-d H:i:00', max($ts, time()));
            }
        } elseif ($when === 'now') {
            $at = date('Y-m-d H:i:s');
        }
        $devices = isset(self::DEVICES[$in['devices'] ?? '']) ? (string) $in['devices'] : 'all';
        $channels = array_values(array_intersect(array_keys(\DeepFind\Rules\Segments::CHANNELS), array_map('strval', (array) ($in['channels'] ?? []))));
        return $errors !== [] ? [null, $errors] : [['title' => $title, 'body' => $body, 'url' => mb_substr($url, 0, 500, 'UTF-8'), 'image' => mb_substr($image, 0, 500, 'UTF-8'),
            'devices' => $devices, 'channels' => $channels, 'status' => $at === null ? 'draft' : 'scheduled', 'scheduled_at' => $at], []];
    }

    public static function save(string $lang, array $c, ?int $id): int
    {
        $row = ['title' => $c['title'], 'body' => $c['body'], 'url' => $c['url'], 'image' => $c['image'], 'devices' => $c['devices'],
            'channels' => $c['channels'] !== [] ? Text::json($c['channels']) : null, 'status' => $c['status'], 'scheduled_at' => $c['scheduled_at'],
            'updated_at' => date('Y-m-d H:i:s')];
        $pid = \DeepFind\Project::id();
        if ($id !== null) {
            $cur = self::find($id, $lang);
            if ($cur !== null && in_array($cur['status'], ['draft', 'scheduled'], true)) {
                Db::update('push_campaigns', $row, 'id = ? AND project_id = ? AND lang = ? AND status IN (\'draft\', \'scheduled\')', [$id, $pid, $lang]);
                return $id;
            }
        }
        return Db::insert('push_campaigns', $row + ['project_id' => $pid, 'lang' => $lang, 'created_at' => $row['updated_at']]);
    }

    public static function delete(int $id, string $lang): void
    {
        Db::exec("DELETE FROM push_campaigns WHERE id = ? AND project_id = ? AND lang = ? AND status <> 'sending'", [$id, \DeepFind\Project::id(), $lang]);
    }

    /** Abonnés visés par un message (appareil, provenance à l'abonnement). */
    private static function targetWhere(array $c): array
    {
        $where = ['project_id = ?', 'lang = ?'];
        $params = [\DeepFind\Project::id(), $c['lang'] ?? 'fr'];
        $dev = ['phone' => ['phone'], 'mobile' => ['phone', 'tablet'], 'desktop' => ['desktop']][$c['devices']] ?? null;
        if ($dev !== null) {
            $where[] = 'device IN (' . implode(',', array_fill(0, count($dev), '?')) . ')';
            array_push($params, ...$dev);
        }
        if ($c['channels'] !== []) {
            $where[] = 'channel IN (' . implode(',', array_fill(0, count($c['channels']), '?')) . ')';
            array_push($params, ...$c['channels']);
        }
        return [implode(' AND ', $where), $params];
    }

    public static function audience(array $c, string $lang): int
    {
        [$w, $p] = self::targetWhere($c + ['lang' => $lang]);
        return (int) Db::value("SELECT COUNT(*) FROM push_subscriptions WHERE $w", $p);
    }

    /**
     * Envoie les messages programmés dont l'heure est passée (tous projets) ; appelé chaque minute par schedule:run.
     * @return int messages envoyés
     */
    public static function runDue(?callable $log = null): int
    {
        $n = 0;
        foreach (Db::all("SELECT id, project_id, lang FROM push_campaigns WHERE status = 'scheduled' AND scheduled_at <= ? ORDER BY scheduled_at LIMIT 5", [date('Y-m-d H:i:s')]) as $r) {
            // verrou : un seul processus passe le message en « envoi en cours »
            if (Db::exec("UPDATE push_campaigns SET status = 'sending' WHERE id = ? AND status = 'scheduled'", [(int) $r['id']]) !== 1) {
                continue;
            }
            \DeepFind\Project::with((int) $r['project_id'], static function () use ($r, $log, &$n): void {
                $res = self::sendCampaign((int) $r['id'], (string) $r['lang']);
                $n++;
                if ($log) {
                    $log(sprintf('notification #%d : %d envoyée(s), %d échec(s)', $r['id'], $res['sent'], $res['failed']));
                }
            });
        }
        return $n;
    }

    /** @return array{targeted:int, sent:int, failed:int} */
    public static function sendCampaign(int $id, string $lang): array
    {
        $c = self::find($id, $lang);
        if ($c === null) {
            return ['targeted' => 0, 'sent' => 0, 'failed' => 0];
        }
        $base = rtrim((string) \DeepFind\Config::get('APP_URL', ''), '/');
        // clé publique reprise dans le message : celle avec laquelle l'abonné s'est inscrit (autorisée pour le domaine de son site),
        // sinon la première clé active du projet. Elle ne sert qu'aux compteurs « affiché » / « cliqué » renvoyés par le service worker.
        $keys = [];
        foreach (Db::all('SELECT id, public_key FROM api_keys WHERE project_id = ? AND active = 1 ORDER BY id', [\DeepFind\Project::id()]) as $k) {
            $keys[(int) $k['id']] = (string) $k['public_key'];
        }
        $defaultKey = (string) (reset($keys) ?: '');
        $payloadFor = static fn (string $key): string => Text::json(array_filter([
            't' => $c['title'], 'b' => $c['body'], 'u' => $c['url'] !== '' ? $c['url'] : '/', 'i' => $c['image'], 'ic' => self::url(Settings::get('push_icon')),
            'c' => $c['id'], 'k' => $key, 'a' => $base . '/api/v1', 'l' => $lang,
        ], static fn ($v) => $v !== '' && $v !== null));
        $subject = str_starts_with($base, 'https://') ? $base : 'mailto:' . (string) \DeepFind\Config::get('PUSH_CONTACT', 'contact@example.com');
        [$w, $p] = self::targetWhere($c + ['lang' => $lang]);
        $total = ['targeted' => 0, 'sent' => 0, 'failed' => 0];
        $lastId = 0;
        do {
            $subs = Db::all("SELECT id, endpoint, p256dh, auth, api_key_id FROM push_subscriptions WHERE $w AND id > ? ORDER BY id LIMIT 500", [...$p, $lastId]);
            if ($subs === []) {
                break;
            }
            $lastId = (int) end($subs)['id'];
            // un message par clé d'abonnement (même contenu, clé différente)
            $byKey = [];
            foreach ($subs as $s) {
                $byKey[$keys[(int) $s['api_key_id']] ?? $defaultKey][] = $s;
            }
            $codes = [];
            foreach ($byKey as $k => $group) {
                $codes += WebPush::send($group, $payloadFor((string) $k), $subject);
            }
            $gone = $retry = $ok = [];
            foreach ($codes as $sid => $code) {
                if ($code >= 200 && $code < 300) {
                    $ok[] = $sid;
                } elseif ($code === 404 || $code === 410) {
                    $gone[] = $sid; // abonnement expiré ou retiré par le visiteur
                } else {
                    $retry[] = $sid;
                }
            }
            if ($ok !== []) {
                Db::exec('UPDATE push_subscriptions SET failures = 0 WHERE id IN (' . implode(',', array_map('intval', $ok)) . ')');
            }
            if ($gone !== []) {
                Db::exec('DELETE FROM push_subscriptions WHERE id IN (' . implode(',', array_map('intval', $gone)) . ')');
            }
            if ($retry !== []) {
                Db::exec('UPDATE push_subscriptions SET failures = failures + 1 WHERE id IN (' . implode(',', array_map('intval', $retry)) . ')');
                Db::exec('DELETE FROM push_subscriptions WHERE failures >= ? AND id IN (' . implode(',', array_map('intval', $retry)) . ')', [self::MAX_FAILURES]);
            }
            $total['targeted'] += count($subs);
            $total['sent'] += count($ok);
            $total['failed'] += count($gone) + count($retry);
        } while (count($subs) === 500);
        Db::exec("UPDATE push_campaigns SET status = 'sent', sent_at = ?, targeted = ?, sent = ?, failed = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), $total['targeted'], $total['sent'], $total['failed'], $id]);
        return $total;
    }

    /** Notification affichée (s) ou cliquée (c), signalée par le service worker du site. */
    public static function campaignEvent(int $id, string $e): void
    {
        $col = ['s' => 'shown', 'c' => 'clicks'][$e] ?? null;
        if ($col !== null && $id > 0) {
            Db::exec("UPDATE push_campaigns SET $col = $col + 1 WHERE id = ? AND project_id = ?", [$id, \DeepFind\Project::id()]);
        }
    }
}
