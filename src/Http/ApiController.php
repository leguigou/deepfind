<?php

declare(strict_types=1);

namespace DeepFind\Http;

use DeepFind\Logging\Audience;
use DeepFind\Logging\Journeys;
use DeepFind\Logging\Tracker;
use DeepFind\Logging\SearchLogger;
use DeepFind\Meili\MeiliClient;
use DeepFind\Meili\MeiliException;
use DeepFind\Search\SearchRequest;
use DeepFind\Search\SearchService;
use DeepFind\Security\ApiKeys;
use DeepFind\Security\BotGuard;
use DeepFind\Security\ClientIp;
use DeepFind\Settings;
use DeepFind\Store\Store;
use DeepFind\Text;

/**
 * API publique appelée par le widget :
 *   GET  /api/v1/search?key=pk_…&q=palme&lang=fr&offset=0&limit=20&brand[]=C4&in_stock=1&on_sale=1&sort=price_asc&sid=…
 *   POST /api/v1/click   (key, q, lang, doc_type, doc_id, position, sid)
 *   POST /api/v1/visit   (key, lang, d = m|t|d, p = chemin, t = titre, a = 0|1)   page vue (mesure d'audience anonyme ;
 *        a = 0 : page non mesurée par l'audience, seulement pour le parcours)
 *   POST /api/v1/push/subscribe (key, lang, endpoint, p256dh, auth, d)   abonnement aux notifications ; push/unsubscribe (endpoint)
 *   POST /api/v1/push/event (key, e = iv|ia|il|ig|id, ou c = message et e = s|c)   invitation, réponse du navigateur, notification affichée / cliquée
 *   POST /api/v1/event   (key, lang, e = bar|close|cart|order, d, q | sku, qty, price, name | value, tid, items)   parcours d'achat
 *   POST /api/v1/popin   (key, lang, id, e = v|c|x)   popin affichée, cliquée ou fermée (compteurs du jour, sans donnée personnelle)
 *   GET  /api/v1/carousels?key&lang&page=/chemin[&sku=…][&h=id1,id2]   carrousels de la page et leurs produits
 *        (h : historique du visiteur, envoyé seulement avec son consentement ; jamais enregistré)
 *   POST /api/v1/carousel (key, lang, id, e = v|p|c, p[] = produits)   carrousel vu à l'écran / autres produits vus / produit cliqué
 *   GET  /api/v1/config?key=pk_…&lang=fr   (le widget ne prend la main que si « enabled » est vrai)
 *   GET  /api/v1/health
 */
final class ApiController
{
    public function handle(string $method, string $path): void
    {
        $t0 = hrtime(true);
        try {
            match (true) {
                $path === '/api/v1/search' && in_array($method, ['GET', 'HEAD'], true) => $this->search(),
                $path === '/api/v1/click' && $method === 'POST' => $this->click(),
                $path === '/api/v1/visit' && $method === 'POST' => $this->visit(),
                $path === '/api/v1/event' && $method === 'POST' => $this->event(),
                str_starts_with($path, '/api/v1/push/') && $method === 'POST' => $this->push(substr($path, 13)),
                $path === '/api/v1/popin' && $method === 'POST' => $this->popin(),
                $path === '/api/v1/carousels' && in_array($method, ['GET', 'HEAD'], true) => $this->carousels(),
                $path === '/api/v1/carousel' && $method === 'POST' => $this->carouselEvent(),
                $path === '/api/v1/config' && in_array($method, ['GET', 'HEAD'], true) => $this->config(),
                $path === '/api/v1/health' => $this->health(),
                $method === 'OPTIONS' => $this->preflight(),
                default => Response::json(['error' => 'not_found'], 404),
            };
        } catch (MeiliException $e) {
            error_log('[deepfind] ' . $e->getMessage());
            Response::json(['error' => 'engine_unavailable'], 503);
        } catch (\Throwable $e) {
            error_log('[deepfind] ' . $e::class . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
            Response::json(['error' => 'server_error'], 500);
        } finally {
            // supervision : après l'envoi de la réponse (compteurs en mémoire, sans écriture en base par requête)
            if ($method !== 'OPTIONS') {
                try {
                    \DeepFind\Logging\Metrics::record($path, (int) (http_response_code() ?: 200), (int) round(((Response::$sentAt ?? hrtime(true)) - $t0) / 1e6),
                        ClientIp::resolve($_SERVER), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
                } catch (\Throwable $e) {
                    error_log('[deepfind] supervision : ' . $e->getMessage());
                }
            }
        }
    }

    private function search(): void
    {
        $t0 = hrtime(true);
        $ip = ClientIp::resolve($_SERVER);
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $lang = (string) ($_GET['lang'] ?? 'fr');
        $lang = preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'fr';

        [$key, $cors] = $this->authorize((string) ($_GET['key'] ?? ''), $lang);
        if ($key === null) {
            return;
        }
        if (!\DeepFind\Project::isActive()) {
            Response::json(['error' => 'project_disabled'], 403, $cors);
            return;
        }
        $req = SearchRequest::fromArray($_GET);
        $req->pageRule = \DeepFind\Rules\PageRules::match($req->lang, $req->page);
        $store = Store::get();
        $deny = (new BotGuard($store))->check($ip, $ua, $req->offset === 0 ? $req->qNorm : '');
        if ($deny !== null) {
            Response::json(['error' => $deny['reason']], $deny['status'], ['Retry-After' => (string) $deny['retry_after']] + $cors);
            return;
        }

        $ttl = Settings::int('cache_ttl');
        $cacheKey = 'res:' . \DeepFind\Project::id() . ':' . \DeepFind\Rules\RulesRepository::version() . ':' . $req->cacheKey(false);
        $data = $ttl > 0 ? $store->get($cacheKey) : null;
        $cached = is_array($data);
        if (!$cached) {
            $data = (new SearchService(MeiliClient::search()))->search($req);
            if ($ttl > 0) {
                $store->set($cacheKey, $data, $ttl);
            }
        }
        $tookMs = (int) round((hrtime(true) - $t0) / 1e6);
        // Résultats personnalisés appliqués : pour le journal (impressions), pas dans la réponse
        $applied = array_map('intval', (array) ($data['_cr'] ?? []));
        unset($data['_cr']);
        $data['took_ms'] = $tookMs;
        \DeepFind\Logging\Metrics::$search = ['cached' => $cached, 'empty' => (int) ($data['total'] ?? 0) === 0];
        $data['widget'] = ['enter_submit' => Settings::int('widget_enter_submit') === 1];
        $data['cached'] = $cached;
        Response::json($data, 200, $cors + ['Cache-Control' => 'private, max-age=30', 'Server-Timing' => 'app;dur=' . $tookMs]);

        // Journalisation après l'envoi de la réponse (pas d'impact sur le temps perçu avec PHP-FPM).
        Response::finish();
        // audience (recherches, sans résultat), recherches qui rapportent, parcours : empreinte du jour, sans cookie
        if ($req->offset === 0 && $req->qNorm !== '' && ($_GET['na'] ?? '') !== '1' && Audience::enabled()) {
            try {
                $sid = self::sid($_GET['sid'] ?? '');
                $isNew = Audience::isNewSearch($store, $sid, $req->qNorm, $req->filtersSummary());
                // « aucun résultat exact » (produits approchants seulement) compte comme sans résultat, comme dans le journal
                $approx = !empty($data['approximate']) ? (int) ($data['total'] ?? 0) : 0;
                $total = $approx > 0 ? 0 : (int) ($data['total'] ?? 0);
                (new Tracker($ip, $ua, (string) ($_GET['d'] ?? ''), $req->lang))
                    ->search($req->q, $total, $req->filtersSummary(), $isNew, Tracker::zeroFirst($sid, $isNew, $total), $sid, $approx);
            } catch (\Throwable $e) {
                error_log('[deepfind] audience : ' . $e->getMessage());
            }
        }
        if ($req->offset === 0) {
            (new SearchLogger($store))->log([
                'api_key_id' => (int) $key['id'], 'lang' => $req->lang, 'query' => $req->q, 'query_norm' => $req->qNorm,
                'filters' => $req->filtersSummary(), 'results' => (int) $data['total'], 'approx' => !empty($data['approximate']), 'took_ms' => $tookMs,
                'ip_hash' => ClientIp::hash($ip), 'ip_masked' => ClientIp::mask($ip), 'user_agent' => $ua,
                'sid' => self::sid($_GET['sid'] ?? ''),
                'custom_ids' => $applied,
                'banner_id' => (int) ($data['banner']['id'] ?? 0),
            ]);
        }
    }

    private function click(): void
    {
        $p = $_POST;
        $lang = (string) ($p['lang'] ?? 'fr');
        $lang = preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'fr';
        [$key, $cors] = $this->authorize((string) ($p['key'] ?? ''), $lang);
        if ($key === null) {
            return;
        }
        if (!\DeepFind\Project::isActive()) {
            Response::noContent($cors);
            return;
        }
        $ip = ClientIp::resolve($_SERVER);
        $store = Store::get();
        if ((new BotGuard($store))->check($ip, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), '', 'click') !== null) {
            Response::noContent($cors);
            return;
        }
        Response::noContent($cors);
        Response::finish();
        if (($p['na'] ?? '') !== '1' && Audience::enabled()) {
            try {
                (new Tracker($ip, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), (string) ($p['d'] ?? ''), $lang))
                    ->click((string) ($p['q'] ?? ''), (string) ($p['doc_type'] ?? 'product'), substr((string) ($p['doc_id'] ?? ''), 0, 64),
                        mb_substr((string) ($p['n'] ?? ''), 0, 200, 'UTF-8'), (int) ($p['position'] ?? 0));
            } catch (\Throwable $e) {
                error_log('[deepfind] audience : ' . $e->getMessage());
            }
        }
        (new SearchLogger($store))->click([
            'lang' => $lang,
            'query_norm' => Text::normalize((string) ($p['q'] ?? '')),
            'doc_type' => (string) ($p['doc_type'] ?? 'product'),
            'doc_id' => (string) ($p['doc_id'] ?? ''),
            'position' => (int) ($p['position'] ?? 0),
            'ip_hash' => ClientIp::hash($ip),
            'sid' => self::sid($p['sid'] ?? ''),
            'custom_result_id' => max(0, (int) ($p['cr'] ?? 0)),
        ]);
    }

    /** Page vue (mesure d'audience anonyme) : réponse immédiate, comptage après l'envoi. */
    private function visit(): void
    {
        $p = $_POST;
        $lang = (string) ($p['lang'] ?? 'fr');
        $lang = preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'fr';
        [$key, $cors] = $this->authorize((string) ($p['key'] ?? ''), $lang);
        if ($key === null) {
            return;
        }
        // a = 0 : page hors des pages mesurées par l'audience, envoyée seulement pour les parcours ou la provenance (ciblage)
        $counted = ($p['a'] ?? '1') !== '0';
        $source = \DeepFind\Rules\Segments::fromInput($p);
        if (!\DeepFind\Project::isActive() || (!($counted && Audience::tracks('pageviews')) && !Journeys::enabled() && $source === [])) {
            Response::noContent($cors);
            return;
        }
        $ip = ClientIp::resolve($_SERVER);
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        // robots, IP bloquées, rafales : non comptés (compteurs séparés de ceux des recherches)
        if ((new BotGuard(Store::get()))->check($ip, $ua, '', 'visit') !== null) {
            Response::noContent($cors);
            return;
        }
        Response::noContent($cors);
        Response::finish();
        // chemin de la page : envoyé par le widget (anciennes versions : page d'origine de la requête)
        $path = (string) ($p['p'] ?? parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH) ?? '/');
        (new Tracker($ip, $ua, (string) ($p['d'] ?? ''), $lang))->page($path, mb_substr((string) ($p['t'] ?? ''), 0, 200, 'UTF-8'), $counted,
            mb_substr(trim((string) ($p['s'] ?? '')), 0, 64, 'UTF-8'), $source);
    }

    /** Notifications : abonnement, désabonnement, événements (invitation, navigateur, notification affichée / cliquée). */
    private function push(string $action): void
    {
        $p = $_POST;
        $lang = (string) ($p['lang'] ?? 'fr');
        $lang = preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'fr';
        [$key, $cors] = $this->authorize((string) ($p['key'] ?? ''), $lang);
        if ($key === null) {
            return;
        }
        $ip = ClientIp::resolve($_SERVER);
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ((new BotGuard(Store::get()))->check($ip, $ua, '', 'push') !== null) {
            Response::noContent($cors);
            return;
        }
        if ($action === 'subscribe') {
            if (!\DeepFind\Push\Push::enabled()) {
                Response::json(['error' => 'push_disabled'], 403, $cors);
                return;
            }
            $device = ['m' => 'phone', 't' => 'tablet', 'd' => 'desktop'][(string) ($p['d'] ?? '')] ?? 'phone';
            $src = \DeepFind\Rules\Segments::forRequest($p, $ip, $ua);
            $ok = \DeepFind\Push\Push::subscribe($lang, (string) ($p['endpoint'] ?? ''), (string) ($p['p256dh'] ?? ''), (string) ($p['auth'] ?? ''),
                $device, \DeepFind\Rules\Segments::channel($src), (int) $key['id'], $ua);
            Response::json(['ok' => $ok], $ok ? 200 : 422, $cors);
            return;
        }
        if ($action === 'unsubscribe') {
            Response::json(['ok' => \DeepFind\Push\Push::unsubscribe((string) ($p['endpoint'] ?? ''))], 200, $cors);
            return;
        }
        Response::noContent($cors);
        if ($action !== 'event') {
            return;
        }
        Response::finish();
        $e = (string) ($p['e'] ?? '');
        if (isset($p['c'])) {
            \DeepFind\Push\Push::campaignEvent((int) $p['c'], $e, (int) ($p['s'] ?? 0), substr((string) ($p['g'] ?? ''), 0, 32));
        } elseif (($p['na'] ?? '') !== '1') {
            \DeepFind\Push\Push::stat($e);
        }
    }

    /**
     * Événement du parcours d'achat envoyé par le widget : barre de recherche ouverte, fenêtre fermée sans clic,
     * ajout au panier, commande (montant). Réponse immédiate, comptage après l'envoi.
     */
    private function event(): void
    {
        $p = $_POST;
        $lang = (string) ($p['lang'] ?? 'fr');
        $lang = preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'fr';
        [$key, $cors] = $this->authorize((string) ($p['key'] ?? ''), $lang);
        if ($key === null) {
            return;
        }
        $e = (string) ($p['e'] ?? '');
        if (!\DeepFind\Project::isActive() || ($p['na'] ?? '') === '1' || !in_array($e, Tracker::EVENTS, true) || !Audience::enabled()) {
            Response::noContent($cors);
            return;
        }
        $ip = ClientIp::resolve($_SERVER);
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ((new BotGuard(Store::get()))->check($ip, $ua, '', 'event') !== null) {
            Response::noContent($cors);
            return;
        }
        Response::noContent($cors);
        Response::finish();
        if (isset($p['items']) && is_string($p['items'])) {
            $p['items'] = json_decode(substr($p['items'], 0, 20000), true) ?: [];
        }
        (new Tracker($ip, $ua, (string) ($p['d'] ?? ''), $lang))->event($e, $p);
    }

    /** Événement d'une popin (affichage, clic, fermeture) : réponse immédiate, comptage après l'envoi. */
    private function popin(): void
    {
        $p = $_POST;
        $lang = (string) ($p['lang'] ?? 'fr');
        $lang = preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'fr';
        [$key, $cors] = $this->authorize((string) ($p['key'] ?? ''), $lang);
        if ($key === null) {
            return;
        }
        $id = (int) ($p['id'] ?? 0);
        $event = (string) ($p['e'] ?? '');
        if (!\DeepFind\Project::isActive() || ($p['na'] ?? '') === '1' || $id <= 0 || !isset(\DeepFind\Rules\Popins::EVENTS[$event])) {
            Response::noContent($cors);
            return;
        }
        // robots, IP bloquées, rafales : non comptés (compteurs anti-robots séparés)
        if ((new BotGuard(Store::get()))->check(ClientIp::resolve($_SERVER), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), '', 'popin') !== null) {
            Response::noContent($cors);
            return;
        }
        Response::noContent($cors);
        Response::finish();
        \DeepFind\Rules\Popins::record($lang, $id, $event);
        if (Journeys::enabled()) {
            (new Tracker(ClientIp::resolve($_SERVER), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), (string) ($p['d'] ?? ''), $lang))->popin($id, $event);
        }
    }

    /** Carrousels de la page (produits compris). Réponse non mise en cache par le navigateur si elle dépend de l'historique. */
    private function carousels(): void
    {
        $lang = (string) ($_GET['lang'] ?? 'fr');
        $lang = preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'fr';
        [$key, $cors] = $this->authorize((string) ($_GET['key'] ?? ''), $lang);
        if ($key === null) {
            return;
        }
        if (!\DeepFind\Project::isActive()) {
            Response::json(['carousels' => [], 'page_product' => null], 200, $cors);
            return;
        }
        $deny = (new BotGuard(Store::get()))->check(ClientIp::resolve($_SERVER), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), '', 'carousel');
        if ($deny !== null) {
            Response::json(['error' => $deny['reason']], $deny['status'], ['Retry-After' => (string) $deny['retry_after']] + $cors);
            return;
        }
        $history = isset($_GET['h']) ? array_filter(explode(',', substr((string) $_GET['h'], 0, 1500)), 'strlen') : null;
        $cart = \DeepFind\Rules\Carousels::cartContext($lang, $_GET);
        $data = \DeepFind\Rules\Carousels::forPage($lang, [
            'page' => substr((string) ($_GET['page'] ?? '/'), 0, 500), 'sku' => substr((string) ($_GET['sku'] ?? ''), 0, 64), 'history' => $history,
            // provenance du visiteur (ciblage) : page d'arrivée, sinon gardée pour la journée sous son empreinte du jour
            'segment' => \DeepFind\Rules\Segments::forRequest($_GET, ClientIp::resolve($_SERVER), (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'cart' => $cart,
        ]);
        // dépend du visiteur (provenance, panier, historique) : pas de cache partagé
        Response::json($data, 200, $cors + ['Cache-Control' => 'private, no-store']);
    }

    /** Événement d'un carrousel (vu à l'écran, clic sur un produit) : réponse immédiate, comptage après l'envoi. */
    private function carouselEvent(): void
    {
        $p = $_POST;
        $lang = (string) ($p['lang'] ?? 'fr');
        $lang = preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'fr';
        [$key, $cors] = $this->authorize((string) ($p['key'] ?? ''), $lang);
        if ($key === null) {
            return;
        }
        $id = (int) ($p['id'] ?? 0);
        $event = (string) ($p['e'] ?? '');
        if (!\DeepFind\Project::isActive() || ($p['na'] ?? '') === '1' || $id <= 0 || !in_array($event, ['v', 'p', 'c'], true)) {
            Response::noContent($cors);
            return;
        }
        if ((new BotGuard(Store::get()))->check(ClientIp::resolve($_SERVER), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), '', 'carousel') !== null) {
            Response::noContent($cors);
            return;
        }
        Response::noContent($cors);
        Response::finish();
        $products = array_values((array) ($p['p'] ?? []));
        \DeepFind\Rules\Carousels::record($lang, $id, $event, $products);
        if ($event === 'c' && isset($products[0]) && Audience::enabled()) {
            (new Tracker(ClientIp::resolve($_SERVER), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), (string) ($p['d'] ?? ''), $lang))
                ->carouselClick($id, substr((string) $products[0], 0, 64), mb_substr((string) ($p['n'] ?? ''), 0, 200, 'UTF-8'));
        }
    }

    /**
     * Configuration du widget, demandée avant toute prise en main de la page : projet actif ou non, comportement de la touche Entrée.
     * Courte mise en cache navigateur : la désactivation d'un projet est prise en compte en une minute au plus.
     */
    private function config(): void
    {
        $lang = (string) ($_GET['lang'] ?? 'fr');
        $lang = preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'fr';
        [$key, $cors] = $this->authorize((string) ($_GET['key'] ?? ''), $lang);
        if ($key === null) {
            return;
        }
        Response::json([
            'enabled' => \DeepFind\Project::isActive(),
            'enter_submit' => Settings::int('widget_enter_submit') === 1,
            // neutraliser l'autocomplétion d'origine du site (data-native="keep" / "block" sur le script reste prioritaire)
            'block_native' => Settings::int('widget_block_native') === 1,
            // deuxième photo du produit au survol de sa carte
            'hover_image' => Settings::int('widget_hover_image') === 1,
        ] + \DeepFind\Widget\WidgetTexts::forWidget($lang) + [
            // mesure d'audience : pages qui signalent une page vue (sans cookie, sans donnée personnelle), ou false
            'audience' => Audience::widgetConfig(),
            // parcours : page vue envoyée sur toutes les pages (le serveur ne garde que les visiteurs qui cherchent ou cliquent un carrousel)
            'journey' => \DeepFind\Project::isActive() && Journeys::enabled(),
            // barre de recherche ouverte, fenêtre fermée sans clic (audience et parcours)
            'events' => Audience::tracks('searches') || Journeys::enabled(),
            // ajouts au panier et commandes : DeepFind.track(), et lecture du dataLayer (GA4) si activée
            'commerce' => Audience::tracks('commerce') || Journeys::enabled() ? ['datalayer' => Settings::int('audience_datalayer') === 1] : false,
            // popins en cours : le widget choisit celle de la page (adresse, appareil, fermeture mémorisée)
            'popins' => \DeepFind\Project::isActive() ? \DeepFind\Rules\Popins::forWidget($lang) : [],
            // notifications : invitation à s'abonner (forme, appareils, textes, style), ou false
            'push' => \DeepFind\Push\Push::widgetConfig(),
            // carrousels : le widget demande ceux de la page seulement s'il en existe ; historique du visiteur (off | consent | always)
            'carousels' => \DeepFind\Project::isActive() && \DeepFind\Rules\Carousels::active($lang) !== [],
            'personal' => \DeepFind\Rules\Carousels::personalMode(),
            // panier du visiteur demandé avec les carrousels (« achetés ensemble », « aussi regardé », page panier)
            'cart_context' => \DeepFind\Project::isActive() && \DeepFind\Rules\Carousels::needsCart($lang),
            // ciblage selon la provenance : le widget envoie la provenance d'arrivée ; segment = provenance du visiteur (canal, UTM)
            'segments' => $needSeg = \DeepFind\Project::isActive() && \DeepFind\Rules\Carousels::needsSegments($lang),
            'segment' => $needSeg ? \DeepFind\Rules\Segments::describe(\DeepFind\Rules\Segments::forRequest($_GET, ClientIp::resolve($_SERVER), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) : null,
        ] + \DeepFind\Widget\WidgetConfig::forWidget($lang, $preview = substr((string) ($_GET['preview'] ?? ''), 0, 80)),
            200, $cors + ['Cache-Control' => $preview !== '' ? 'no-store' : 'private, max-age=60']);
    }

    private function health(): void
    {
        $ok = MeiliClient::search()->health();
        Response::json(['status' => $ok ? 'ok' : 'degraded', 'engine' => $ok], $ok ? 200 : 503, ['Cache-Control' => 'no-store']);
    }

    private function preflight(): void
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        Response::noContent($origin !== '' ? [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => 'GET, POST',
            'Access-Control-Max-Age' => '86400',
            'Vary' => 'Origin',
        ] : []);
    }

    /** @return array{0:array|null, 1:array<string,string>} */
    private function authorize(string $publicKey, string $lang): array
    {
        $key = ApiKeys::find($publicKey);
        if ($key === null || (int) $key['active'] !== 1 || \DeepFind\Project::find((int) ($key['project_id'] ?? 0)) === null) {
            Response::json(['error' => 'invalid_key'], 401);
            return [null, []];
        }
        // Toute la suite (paramètres, index, règles, journaux) concerne le projet de cette clé
        \DeepFind\Project::use((int) $key['project_id']);
        \DeepFind\Logging\Metrics::$project = (int) $key['project_id'];
        $origin = ApiKeys::checkOrigin($key, $_SERVER);
        if ($origin === null) {
            Response::json(['error' => 'origin_not_allowed'], 403);
            return [null, []];
        }
        $cors = $origin === '' ? ['Vary' => 'Origin'] : ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin'];
        $langs = array_filter(explode(',', (string) $key['langs']));
        if ($langs !== [] && !in_array($lang, $langs, true)) {
            Response::json(['error' => 'lang_not_allowed'], 400, $cors);
            return [null, []];
        }
        return [$key, $cors];
    }

    private static function sid(mixed $v): string
    {
        $v = (string) $v;
        return preg_match('/^[a-zA-Z0-9]{8,32}$/', $v) ? $v : '';
    }
}
