<?php

declare(strict_types=1);

namespace DeepFind\Rules;

use DeepFind\Store\Store;

/**
 * Ciblage selon la provenance du visiteur (popins, carrousels) : canal d'arrivée (accès direct, moteur de recherche, publicité,
 * réseaux sociaux, e-mail, affiliation, autre site) et paramètres UTM de la campagne.
 *
 * Le widget lit la provenance sur la page d'ARRIVÉE (paramètres utm_*, identifiant de clic publicitaire, site d'origine) et
 * l'envoie au serveur, qui la garde en mémoire pour la journée sous l'empreinte du jour du visiteur (aucun cookie, rien sur
 * l'appareil, aucune base de données) : les pages suivantes de la visite connaissent ainsi la provenance.
 */
final class Segments
{
    public const CHANNELS = [
        'direct' => 'Accès direct (adresse tapée, favori)',
        'organic' => 'Moteurs de recherche (Google, Bing, Qwant…)',
        'paid' => 'Publicité (Google Ads, Meta Ads… : clic publicitaire ou utm_medium cpc)',
        'social' => 'Réseaux sociaux (Facebook, Instagram, TikTok, Pinterest…)',
        'email' => 'E-mail et newsletter',
        'affiliate' => 'Affiliation et partenaires',
        'referral' => 'Autres sites (liens entrants)',
        'campaign' => 'Autre campagne UTM',
    ];
    public const UTM_FIELDS = ['s' => 'utm_source', 'm' => 'utm_medium', 'c' => 'utm_campaign'];
    public const UTM_OPS = ['contains' => 'contient', 'equals' => 'est', 'not_contains' => 'ne contient pas'];

    private const ENGINES = ['google', 'bing', 'qwant', 'duckduckgo', 'ecosia', 'yahoo', 'yandex', 'baidu', 'brave', 'startpage', 'lilo', 'ask', 'aol', 'seznam', 'naver'];
    private const SOCIAL = ['facebook', 'fb', 'instagram', 'tiktok', 'pinterest', 'youtube', 'linkedin', 'lnkd', 'twitter', 't.co', 'x.com', 'snapchat',
        'reddit', 'threads', 'whatsapp', 'messenger', 'telegram', 'vk', 'tumblr'];
    private const MEDIUMS = [
        'paid' => ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-search', 'paidsocial', 'paid_social', 'paid-social', 'display', 'cpm', 'cpv', 'ads', 'retargeting', 'banner', 'sea', 'sma'],
        'email' => ['email', 'e-mail', 'mail', 'newsletter', 'emailing', 'crm'],
        'affiliate' => ['affiliate', 'affiliation', 'affilie', 'partner', 'partenaire', 'partners'],
        'social' => ['social', 'social-network', 'social_network', 'social-media', 'social_media', 'sm', 'reseaux-sociaux'],
        'organic' => ['organic', 'seo'],
        'referral' => ['referral', 'referer', 'link'],
    ];

    /** Provenance reçue du widget (src_s, src_m, src_c, src_r, src_ad) : valeurs nettoyées, en minuscules. @return array<string,string> */
    public static function fromInput(array $in): array
    {
        $out = [];
        foreach (['s', 'm', 'c', 'r'] as $k) {
            $v = mb_strtolower(trim((string) ($in['src_' . $k] ?? '')), 'UTF-8');
            $v = (string) preg_replace('/[^\p{L}\p{N} ._\-\/:+]/u', '', $v);
            if ($v !== '') {
                $out[$k] = mb_substr($v, 0, 100, 'UTF-8');
            }
        }
        if (($in['src_ad'] ?? '') === '1') {
            $out['ad'] = '1';
        }
        return $out;
    }

    /** Canal d'arrivée d'après la provenance ([] = accès direct). */
    public static function channel(array $src): string
    {
        if ($src === []) {
            return 'direct';
        }
        $m = (string) ($src['m'] ?? '');
        $s = (string) ($src['s'] ?? '');
        $r = (string) ($src['r'] ?? '');
        if (($src['ad'] ?? '') === '1') {
            return 'paid';
        }
        foreach (self::MEDIUMS as $channel => $list) {
            if ($m !== '' && in_array($m, $list, true)) {
                return $channel;
            }
        }
        if ($s !== '' && (str_contains($s, 'newsletter') || str_contains($s, 'mailchimp') || str_contains($s, 'brevo') || str_contains($s, 'sendinblue') || str_contains($s, 'klaviyo'))) {
            return 'email';
        }
        $is = static function (string $v, array $names): bool {
            foreach ($names as $n) {
                if ($v === $n || str_contains($v, $n . '.') || str_starts_with($v, $n) || str_contains($v, '.' . $n)) {
                    return true;
                }
            }
            return false;
        };
        if ($s !== '') {
            if ($is($s, self::SOCIAL)) {
                return 'social';
            }
            return $is($s, self::ENGINES) && $m === '' ? 'organic' : 'campaign';
        }
        if ($r !== '') {
            if (preg_match('/(^|\.)(mail|webmail|outlook|messagerie)\./', $r) || str_starts_with($r, 'mail.') || str_contains($r, 'webmail')) {
                return 'email';
            }
            if ($is($r, self::SOCIAL)) {
                return 'social';
            }
            if ($is($r, self::ENGINES)) {
                return 'organic';
            }
            return 'referral';
        }
        return 'direct';
    }

    /** Garde la provenance d'arrivée du visiteur pour la journée (mémoire, empreinte du jour). */
    public static function remember(string $vhash, array $src): void
    {
        if ($vhash === '' || $src === []) {
            return;
        }
        Store::get()->set('seg:' . \DeepFind\Project::id() . ':' . $vhash, $src, 30 * 3600);
    }

    /** Provenance du visiteur : celle de la page d'arrivée ($hint), sinon celle gardée pour la journée, sinon accès direct. */
    public static function current(string $vhash, array $hint = []): array
    {
        if ($hint !== []) {
            return $hint;
        }
        if ($vhash === '') {
            return [];
        }
        $v = Store::get()->get('seg:' . \DeepFind\Project::id() . ':' . $vhash);
        return is_array($v) ? $v : [];
    }

    /** Provenance du visiteur de la requête en cours (IP + navigateur → empreinte du jour ; rien n'est gardé ici). */
    public static function forRequest(array $in, string $ip, string $ua): array
    {
        $hint = self::fromInput($in);
        $vhash = $ip !== '' ? \DeepFind\Logging\Audience::visitorHash($ip, $ua) : '';
        if ($hint !== [] && $vhash !== '') {
            self::remember($vhash, $hint);
        }
        return self::current($vhash, $hint);
    }

    /** Pour le widget : canal et UTM (aucun identifiant). @return array{channel:string, s:string, m:string, c:string} */
    public static function describe(array $src): array
    {
        return ['channel' => self::channel($src), 's' => (string) ($src['s'] ?? ''), 'm' => (string) ($src['m'] ?? ''), 'c' => (string) ($src['c'] ?? '')];
    }

    /**
     * Règle de ciblage saisie dans une fiche : canaux cochés (aucun = tous) et conditions UTM (toutes doivent être remplies).
     * @return array{channels: list<string>, utm: list<array{field:string, op:string, text:string}>}
     */
    public static function sanitize(mixed $in): array
    {
        $in = is_array($in) ? $in : [];
        $channels = array_values(array_intersect(array_keys(self::CHANNELS), array_map('strval', (array) ($in['channels'] ?? []))));
        $utm = [];
        foreach (array_slice((array) ($in['utm'] ?? []), 0, 10) as $u) {
            $u = (array) $u;
            $text = mb_strtolower(trim((string) ($u['text'] ?? '')), 'UTF-8');
            $field = isset(self::UTM_FIELDS[$u['field'] ?? '']) ? (string) $u['field'] : 's';
            $op = isset(self::UTM_OPS[$u['op'] ?? '']) ? (string) $u['op'] : 'contains';
            if ($text !== '') {
                $utm[] = ['field' => $field, 'op' => $op, 'text' => mb_substr($text, 0, 100, 'UTF-8')];
            }
        }
        return ['channels' => count($channels) === count(self::CHANNELS) ? [] : $channels, 'utm' => $utm];
    }

    public static function isEmpty(?array $rule): bool
    {
        return $rule === null || (($rule['channels'] ?? []) === [] && ($rule['utm'] ?? []) === []);
    }

    /** Le visiteur (provenance $src) est-il dans la cible ? */
    public static function matches(?array $rule, array $src): bool
    {
        if (self::isEmpty($rule)) {
            return true;
        }
        if (($rule['channels'] ?? []) !== [] && !in_array(self::channel($src), $rule['channels'], true)) {
            return false;
        }
        foreach ($rule['utm'] ?? [] as $u) {
            $v = (string) ($src[$u['field']] ?? '');
            $ok = match ($u['op']) {
                'equals' => $v === $u['text'],
                'not_contains' => !str_contains($v, $u['text']),
                default => $v !== '' && str_contains($v, $u['text']),
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /** Résumé lisible d'une règle (listes de l'extranet). */
    public static function summary(?array $rule): string
    {
        if (self::isEmpty($rule)) {
            return '';
        }
        $parts = [];
        if ($rule['channels'] !== []) {
            $parts[] = implode(', ', array_map(static fn ($c) => explode(' (', self::CHANNELS[$c])[0], $rule['channels']));
        }
        foreach ($rule['utm'] as $u) {
            $parts[] = self::UTM_FIELDS[$u['field']] . ' ' . self::UTM_OPS[$u['op']] . ' « ' . $u['text'] . ' »';
        }
        return 'Provenance : ' . implode(' ; ', $parts);
    }
}
