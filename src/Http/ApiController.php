<?php

declare(strict_types=1);

namespace DeepFind\Http;

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
 *   GET  /api/v1/config?key=pk_…&lang=fr   (le widget ne prend la main que si « enabled » est vrai)
 *   GET  /api/v1/health
 */
final class ApiController
{
    public function handle(string $method, string $path): void
    {
        try {
            match (true) {
                $path === '/api/v1/search' && in_array($method, ['GET', 'HEAD'], true) => $this->search(),
                $path === '/api/v1/click' && $method === 'POST' => $this->click(),
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
        $data['widget'] = ['enter_submit' => Settings::int('widget_enter_submit') === 1];
        $data['cached'] = $cached;
        Response::json($data, 200, $cors + ['Cache-Control' => 'private, max-age=30', 'Server-Timing' => 'app;dur=' . $tookMs]);

        // Journalisation après l'envoi de la réponse (pas d'impact sur le temps perçu avec PHP-FPM).
        Response::finish();
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
        ], 200, $cors + ['Cache-Control' => 'private, max-age=60']);
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
