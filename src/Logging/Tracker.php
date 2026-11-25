<?php

declare(strict_types=1);

namespace DeepFind\Logging;

use DeepFind\Store\Store;

/**
 * Événements d'un visiteur (appelé par l'API publique APRÈS l'envoi de la réponse) : compteurs de la journée (Audience),
 * recherches qui rapportent et produits (totaux), parcours (Journeys, si activé). Aucune IP ni aucun cookie gardés :
 * l'empreinte du jour est calculée ici à partir de l'IP et du navigateur.
 */
final class Tracker
{
    /** Événements envoyés par le widget sur /api/v1/event. */
    public const EVENTS = ['bar', 'close', 'cart', 'order'];

    private string $day;
    private ?string $vhash = null;
    private string $device;
    private string $hint;

    public function __construct(private readonly string $ip, private readonly string $ua, string $deviceHint, private readonly string $lang = 'fr')
    {
        $this->day = Audience::measureDay();
        $this->device = Audience::device($ua, $deviceHint);
        $this->hint = $deviceHint;
    }

    private function vhash(): string
    {
        return $this->vhash ??= ($this->ip === '' ? '' : Audience::visitorHash($this->ip, $this->ua, $this->day));
    }

    /** @param array<string,int> $inc */
    private function count(array $inc): void
    {
        Audience::bump($inc, $this->ip, $this->ua, $this->hint, $this->day);
    }

    private function journey(string $type, array $data): void
    {
        if (Journeys::enabled() && $this->vhash() !== '') {
            Journeys::add($type, $data, $this->vhash(), $this->day, $this->device);
        }
    }

    /** Page vue. $counted : page mesurée par l'audience (réglage des pages) ; sinon seulement pour le parcours. */
    public function page(string $path, string $title, bool $counted): void
    {
        if ($counted && Audience::tracks('pageviews')) {
            $this->count(['pageviews' => 1]);
        }
        $this->journey('page', ['path' => self::path($path), 'title' => $title]);
    }

    /**
     * Recherche (chaque requête du widget, pendant la frappe aussi). $new : nouvelle recherche (pas la suite de la frappe).
     * $zeroFirst : premier « aucun résultat » de cette recherche.
     */
    public function search(string $q, int $results, string $filters, bool $new, bool $zeroFirst, string $sid = '', int $approx = 0): void
    {
        $inc = [];
        if (Audience::tracks('searches')) {
            if ($new) {
                $inc['searches'] = 1;
            }
            if ($zeroFirst) {
                $inc['zero_searches'] = 1;
            }
        }
        if ($inc !== []) {
            $this->count($inc);
        }
        $term = mb_strtolower(Journeys::mask($q), 'UTF-8');
        if ($term !== '' && $this->vhash() !== '' && Audience::enabled()) {
            Audience::lastTerm($this->vhash(), $term);
            if (Audience::tracks('searches')) {
                // pendant la frappe (« pa », « pal », « palme »), le compte passe au mot suivant : seul le mot final reste compté
                $store = Store::get();
                $key = 'aud:term:' . $sid;
                $prev = $sid !== '' && !$new ? $store->get($key) : null;
                if ($new) {
                    Audience::term($term, ['searches' => 1], $this->day);
                } elseif (is_string($prev) && $prev !== $term) {
                    Audience::moveTerm($prev, $term, $this->day);
                }
                if ($sid !== '') {
                    $store->set($key, $term, 120);
                }
            }
        }
        $this->journey('search', ['q' => $q, 'n' => $results, 'ap' => $approx, 'f' => $filters]);
    }

    /** Clic sur un résultat de la recherche. */
    public function click(string $q, string $type, string $id, string $name, int $position): void
    {
        if (Audience::tracks('clicks')) {
            $this->count(['clicks' => 1]);
            if ($type === 'product') {
                Audience::product($id, $name, ['clicks' => 1], $this->day);
            }
        }
        $this->journey('click', ['q' => $q, 'id' => $id, 'name' => $name, 'pos' => $position]);
    }

    /** Clic sur un produit d'un carrousel (ouvre un parcours). */
    public function carouselClick(int $carouselId, string $productId, string $name): void
    {
        if (Audience::tracks('clicks')) {
            $this->count(['carousel_clicks' => 1]);
            Audience::product($productId, $name, ['clicks' => 1], $this->day);
        }
        $c = \DeepFind\Rules\Carousels::find($carouselId, $this->lang);
        $this->journey('carousel_click', ['carousel' => $c ? ($c['title'] !== '' ? $c['title'] : $c['name']) : '#' . $carouselId, 'id' => $productId, 'name' => $name]);
    }

    /** Popin cliquée ou fermée (parcours seulement ; les compteurs des popins ont leur propre table). */
    public function popin(int $popinId, string $event): void
    {
        if ($event === 'v' || !Journeys::enabled()) {
            return;
        }
        $p = \DeepFind\Rules\Popins::find($popinId, $this->lang);
        $this->journey('popin', ['popin' => $p['name'] ?? ('#' . $popinId), 'e' => $event]);
    }

    /**
     * Événement envoyé par le widget : bar (barre de recherche ouverte), close (fenêtre fermée sans clic), cart (ajout au panier),
     * order (commande). @param array<string,mixed> $p paramètres reçus
     */
    public function event(string $e, array $p): void
    {
        switch ($e) {
            case 'bar':
                if (Audience::tracks('searches')) {
                    $this->count(['bar_opens' => 1]);
                }
                $this->journey('bar', []);
                return;
            case 'close':
                if (Audience::tracks('searches')) {
                    $this->count(['closes' => 1]);
                }
                $this->journey('close', ['q' => (string) ($p['q'] ?? '')]);
                return;
            case 'cart':
                $this->cart($p);
                return;
            case 'order':
                $this->order($p);
                return;
        }
    }

    private function commerce(): bool
    {
        return Audience::tracks('commerce');
    }

    /** Ajout au panier : SKU (et quantité, prix unitaire si le site les donne ; sinon prix du catalogue). */
    private function cart(array $p): void
    {
        $sku = mb_substr(trim((string) ($p['sku'] ?? '')), 0, 64, 'UTF-8');
        if ($sku === '') {
            return;
        }
        $qty = max(1, min(999, (int) ($p['qty'] ?? 1)));
        $prod = Audience::productBySku($this->lang, $sku);
        $unit = isset($p['price']) && (string) $p['price'] !== '' ? Audience::cents($p['price']) : (int) round(($prod['price'] ?? 0) * 100);
        $value = $unit * $qty;
        $name = mb_substr(trim((string) ($p['name'] ?? '')), 0, 200, 'UTF-8') ?: (string) ($prod['name'] ?? '');
        if ($this->commerce()) {
            $this->count(['carts' => 1, 'cart_value' => $value]);
            if ($prod !== null) {
                Audience::product($prod['id'], $name, ['carts' => 1], $this->day);
            }
            $term = $this->vhash() !== '' ? Audience::lastTerm($this->vhash()) : '';
            if ($term !== '') {
                Audience::term($term, ['carts' => 1], $this->day);
            }
        }
        $this->journey('cart', ['sku' => $sku, 'name' => $name, 'qty' => $qty, 'value' => $value]);
    }

    /**
     * Commande : montant total (value), lignes (items : [{sku, qty, price}]) ; numéro de commande (tid) seulement pour ne pas
     * compter deux fois la même (empreinte gardée 2 jours en mémoire, le numéro n'est jamais enregistré).
     */
    private function order(array $p): void
    {
        $value = Audience::cents($p['value'] ?? 0);
        $items = is_array($p['items'] ?? null) ? array_slice($p['items'], 0, 100) : [];
        $tid = trim((string) ($p['tid'] ?? ''));
        if ($tid !== '') {
            $key = 'aud:ord:' . \DeepFind\Project::id() . ':' . hash_hmac('sha256', $tid, (string) \DeepFind\Config::get('APP_SECRET', 'deepfind'));
            if (Store::get()->incr($key, 2 * 86400) > 1) {
                return; // déjà comptée (page de confirmation rechargée…)
            }
        }
        $lines = [];
        foreach ($items as $it) {
            $it = (array) $it;
            $sku = mb_substr(trim((string) ($it['sku'] ?? $it['item_id'] ?? '')), 0, 64, 'UTF-8');
            if ($sku === '') {
                continue;
            }
            $qty = max(1, min(999, (int) ($it['qty'] ?? $it['quantity'] ?? 1)));
            $lines[] = ['sku' => $sku, 'qty' => $qty, 'cents' => Audience::cents($it['price'] ?? 0) * $qty];
        }
        if ($value === 0) {
            $value = array_sum(array_column($lines, 'cents'));
        }
        if ($value === 0 && $lines === []) {
            return;
        }
        if ($this->commerce()) {
            $this->count(['orders' => 1, 'revenue' => $value]);
            foreach ($lines as $l) {
                $prod = Audience::productBySku($this->lang, $l['sku']);
                if ($prod !== null) {
                    Audience::product($prod['id'], $prod['name'], ['orders' => 1, 'revenue' => $l['cents']], $this->day);
                }
            }
            $term = $this->vhash() !== '' ? Audience::lastTerm($this->vhash()) : '';
            if ($term !== '') {
                Audience::term($term, ['orders' => 1, 'revenue' => $value], $this->day);
            }
        }
        $this->journey('order', ['value' => $value, 'items' => array_sum(array_column($lines, 'qty'))]);
    }

    /** Chemin de page reçu du widget : chemin seul (sans domaine ni paramètres), 300 caractères au plus. */
    public static function path(string $p): string
    {
        $p = (string) (parse_url($p, PHP_URL_PATH) ?? '/');
        return mb_substr('/' . ltrim($p, '/'), 0, 300, 'UTF-8');
    }

    /** Recherche sans résultat déjà comptée pour cette suite de frappe (identifiant de page sid) ? */
    public static function zeroFirst(string $sid, bool $isNew, int $results): bool
    {
        if ($sid === '') {
            return $results === 0 && $isNew;
        }
        $store = Store::get();
        $key = 'aud:zero:' . $sid;
        if ($isNew) {
            $store->delete($key);
        }
        if ($results !== 0) {
            return false;
        }
        return $store->incr($key, 120) === 1;
    }

    /** @internal tests */
    public function day(): string
    {
        return $this->day;
    }

    /** @internal tests */
    public function hash(): string
    {
        return $this->vhash();
    }
}
