<?php

declare(strict_types=1);

namespace DeepFind\Widget;

use DeepFind\Settings;

/**
 * Paramètres › Variables.
 *   - variables système : remplies par le widget (WidgetConfig::CARD_VARS, lien de la carte), non modifiables ;
 *   - textes du widget : libellés affichés sur le site (« dès », « Nouveau », « Voir tout »…), modifiables par langue
 *     (widget_texts_<langue> : seules les valeurs différentes du texte d'origine sont gardées) ;
 *   - variables personnalisées : {{code}} défini par l'équipe, valeur par langue (custom_vars = codes et descriptions,
 *     custom_vars_<langue> = valeurs), utilisables dans les modèles de carte, le HTML des popins et bannières, le titre des carrousels.
 * Le widget reçoit textes modifiés et variables avec /api/v1/config (texte brut, inséré avec textContent ou échappé).
 */
final class WidgetTexts
{
    /** Textes d'origine : copie de la table T de public/widget/deepfind.js (un test vérifie qu'elles sont identiques). */
    public const DEFAULTS = [
        'fr' => [
            'products' => 'Produits', 'brands' => 'Marques', 'posts' => 'Articles de blog', 'filters' => 'Filtres', 'inStock' => 'En stock', 'onSale' => 'En promotion',
            'seeAll' => 'Tout voir', 'sort' => 'Trier', 'relevance' => 'Pertinence', 'priceAsc' => 'Prix croissant', 'priceDesc' => 'Prix décroissant', 'newest' => 'Nouveautés',
            'none' => 'Aucun résultat pour', 'tryOther' => 'Vérifiez l\'orthographe ou essayez un terme plus général.', 'from' => 'dès', 'outOfStock' => 'Rupture',
            'isNew' => 'Nouveau', 'close' => 'Fermer', 'busy' => 'Trop de recherches en peu de temps, patientez quelques secondes…', 'error' => 'Recherche momentanément indisponible.',
            'goTo' => 'Voir la page', 'closeFilters' => 'Fermer les filtres', 'clear' => 'Effacer les filtres', 'results' => 'résultats', 'loading' => 'Chargement…',
            'approx' => 'Aucun résultat exact pour « %s ». Produits approchants :', 'approxTitle' => 'Produits approchants',
            'price' => 'Prix', 'min' => 'Min.', 'max' => 'Max.', 'apply' => 'Valider', 'reset' => 'Réinitialiser', 'search' => 'Rechercher',
            'pageLimited' => 'Résultats limités à', 'pageRemove' => 'Retirer le filtre « %s » et chercher dans tout le catalogue',
            'pageAll' => 'Résultats de tout le catalogue.', 'pageBack' => 'Revenir à « %s »', 'popin' => 'Message',
            'carousel' => 'Sélection de produits', 'prev' => 'Produits précédents', 'next' => 'Produits suivants', 'seeMore' => 'Voir tout',
            'freeShippingLeft' => 'Plus que %s pour profiter de la livraison offerte', 'freeShippingDone' => 'La livraison vous est offerte !',
            'pushError' => 'Inscription impossible pour le moment, réessayez plus tard.',
        ],
        'en' => [
            'products' => 'Products', 'brands' => 'Brands', 'posts' => 'Blog posts', 'filters' => 'Filters', 'inStock' => 'In stock', 'onSale' => 'On sale',
            'seeAll' => 'See all', 'sort' => 'Sort', 'relevance' => 'Relevance', 'priceAsc' => 'Price: low to high', 'priceDesc' => 'Price: high to low', 'newest' => 'Newest',
            'none' => 'No results for', 'tryOther' => 'Check the spelling or try a more general term.', 'from' => 'from', 'outOfStock' => 'Out of stock',
            'isNew' => 'New', 'close' => 'Close', 'busy' => 'Too many searches, please wait a few seconds…', 'error' => 'Search temporarily unavailable.',
            'goTo' => 'Go to page', 'closeFilters' => 'Close filters', 'clear' => 'Clear filters', 'results' => 'results', 'loading' => 'Loading…',
            'approx' => 'No exact match for “%s”. Similar products:', 'approxTitle' => 'Similar products',
            'price' => 'Price', 'min' => 'Min.', 'max' => 'Max.', 'apply' => 'Apply', 'reset' => 'Reset', 'search' => 'Search',
            'pageLimited' => 'Results limited to', 'pageRemove' => 'Remove the “%s” filter and search the whole catalogue',
            'pageAll' => 'Results from the whole catalogue.', 'pageBack' => 'Back to “%s”', 'popin' => 'Message',
            'carousel' => 'Product selection', 'prev' => 'Previous products', 'next' => 'Next products', 'seeMore' => 'See all',
            'freeShippingLeft' => 'Only %s away from free delivery', 'freeShippingDone' => 'You get free delivery!',
            'pushError' => 'Sign-up failed, please try again later.',
        ],
    ];

    /** Groupes de l'écran : titre => [clé => description]. */
    public const GROUPS = [
        'Cartes produit' => [
            'from' => 'Devant le prix quand il varie selon la déclinaison (variable {{from}})',
            'isNew' => 'Badge des nouveautés (variable {{new}})',
            'outOfStock' => 'Badge et texte des produits en rupture (variables {{out_of_stock}}, {{stock}})',
            'inStock' => 'Produits en stock (variable {{stock}}) et case « En stock » des filtres',
        ],
        'Fenêtre de recherche' => [
            'search' => 'Champ de recherche de la fenêtre (plein écran, flottant)',
            'products' => 'Titre de la liste des produits', 'posts' => 'Titre des articles de blog', 'brands' => 'Titre des marques',
            'seeAll' => 'Bouton « Tout voir » (page de résultats du site)',
            'none' => 'Aucun résultat : début de la phrase (suivi de la recherche)', 'tryOther' => 'Aucun résultat : conseil',
            'approx' => 'Aucun résultat exact (%s = la recherche)', 'approxTitle' => 'Titre des produits approchants',
            'results' => 'Après le nombre de résultats', 'loading' => 'Chargement de la suite',
            'busy' => 'Trop de recherches en peu de temps', 'error' => 'Recherche indisponible',
            'goTo' => 'Lien vers une page (redirection proposée)',
        ],
        'Filtres et tri' => [
            'filters' => 'Bouton et titre des filtres', 'closeFilters' => 'Fermer les filtres (lecteurs d\'écran)', 'clear' => 'Effacer les filtres',
            'onSale' => 'Case « En promotion »', 'sort' => 'Liste de tri', 'relevance' => 'Tri par pertinence', 'priceAsc' => 'Tri prix croissant',
            'priceDesc' => 'Tri prix décroissant', 'newest' => 'Tri nouveautés',
            'price' => 'Filtre de prix', 'min' => 'Prix minimum', 'max' => 'Prix maximum', 'apply' => 'Valider le prix', 'reset' => 'Réinitialiser le prix',
            'pageLimited' => 'Règle selon la page : « Résultats limités à »', 'pageRemove' => 'Règle selon la page : retirer le filtre (%s = le filtre)',
            'pageAll' => 'Règle selon la page : tout le catalogue', 'pageBack' => 'Règle selon la page : revenir au filtre (%s = le filtre)',
        ],
        'Carrousels et popins' => [
            'seeMore' => 'Lien « Voir tout » d\'un carrousel (si son texte n\'est pas renseigné)',
            'prev' => 'Flèche précédente (lecteurs d\'écran)', 'next' => 'Flèche suivante (lecteurs d\'écran)',
            'carousel' => 'Nom d\'un carrousel sans titre (lecteurs d\'écran)', 'popin' => 'Nom d\'une popin sans texte alternatif (lecteurs d\'écran)',
            'close' => 'Bouton de fermeture (lecteurs d\'écran)',
            'freeShippingLeft' => 'Carrousel « page panier » : montant restant pour la livraison offerte (%s = le montant)',
            'freeShippingDone' => 'Carrousel « page panier » : livraison offerte atteinte',
            'pushError' => 'Notifications : l\'inscription a échoué (service d\'envoi ou serveur injoignable)',
        ],
    ];

    public const MAX_VARS = 50;

    /** Codes interdits pour une variable personnalisée (variables système). @return list<string> */
    public static function reserved(): array
    {
        return array_values(array_unique([...array_keys(WidgetConfig::CARD_VARS), ...array_keys(WidgetConfig::LINK_VARS)]));
    }

    public static function defaults(string $lang): array
    {
        return self::DEFAULTS[$lang] ?? self::DEFAULTS['fr'];
    }

    /** Textes modifiés de la langue (clé => texte). @return array<string,string> */
    public static function overrides(string $lang): array
    {
        $def = self::defaults($lang);
        $out = [];
        foreach (Settings::json('widget_texts_' . $lang) as $k => $v) {
            if (isset($def[$k]) && is_string($v) && $v !== '' && $v !== $def[$k]) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** Enregistre les textes de la langue : vides ou identiques au texte d'origine = texte d'origine. */
    public static function saveTexts(string $lang, array $in): int
    {
        $def = self::defaults($lang);
        $out = [];
        foreach ($in as $k => $v) {
            $v = self::text((string) $v);
            if (isset($def[$k]) && $v !== '' && $v !== $def[$k]) {
                $out[$k] = $v;
            }
        }
        Settings::set('widget_texts_' . $lang, \DeepFind\Text::json((object) $out));
        return count($out);
    }

    /** Texte brut d'une ligne : sans balise ni retour à la ligne, 300 caractères au plus. */
    private static function text(string $v): string
    {
        $v = trim((string) preg_replace('/\s+/u', ' ', strip_tags($v)));
        return mb_substr($v, 0, 300, 'UTF-8');
    }

    /** Variables personnalisées : codes et descriptions (communs à toutes les langues). @return list<array{code:string, description:string}> */
    public static function customVars(): array
    {
        $out = [];
        foreach (Settings::json('custom_vars') as $row) {
            if (is_array($row) && isset($row['code']) && self::validCode((string) $row['code'])) {
                $out[] = ['code' => (string) $row['code'], 'description' => (string) ($row['description'] ?? '')];
            }
        }
        return $out;
    }

    /** @return array<string,string> code => valeur dans la langue */
    public static function customValues(string $lang): array
    {
        $codes = array_column(self::customVars(), 'code');
        $out = [];
        foreach (Settings::json('custom_vars_' . $lang) as $k => $v) {
            if (in_array((string) $k, $codes, true) && is_string($v)) {
                $out[(string) $k] = $v;
            }
        }
        return $out;
    }

    public static function validCode(string $code): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{1,30}$/', $code) && !in_array($code, self::reserved(), true);
    }

    /**
     * Enregistre les variables personnalisées : liste de {code, description, value} (valeur de la langue en cours).
     * Les valeurs des autres langues sont gardées pour les codes conservés.
     * @return list<string> erreurs (codes refusés)
     */
    public static function saveCustom(string $lang, array $rows): array
    {
        $errors = [];
        $vars = [];
        $values = [];
        foreach (array_slice($rows, 0, self::MAX_VARS) as $r) {
            $code = strtolower(trim((string) ($r['code'] ?? '')));
            $code = (string) preg_replace('/^\{\{\s*|\s*\}\}$/', '', $code);
            if ($code === '') {
                continue;
            }
            if (!self::validCode($code)) {
                $errors[] = in_array($code, self::reserved(), true)
                    ? '« ' . $code . ' » est une variable système : choisissez un autre code.'
                    : '« ' . $code . ' » : code invalide (lettres minuscules sans accent, chiffres et _, 2 à 31 caractères, commence par une lettre).';
                continue;
            }
            if (isset($values[$code])) {
                continue;
            }
            $vars[] = ['code' => $code, 'description' => self::text((string) ($r['description'] ?? ''))];
            $values[$code] = self::text((string) ($r['value'] ?? ''));
        }
        Settings::set('custom_vars', \DeepFind\Text::json($vars));
        Settings::set('custom_vars_' . $lang, \DeepFind\Text::json((object) $values));
        return $errors;
    }

    /** Pour le widget : textes modifiés et variables personnalisées non vides de la langue. */
    public static function forWidget(string $lang): array
    {
        return ['texts' => (object) self::overrides($lang), 'vars' => (object) array_filter(self::customValues($lang), static fn ($v) => $v !== '')];
    }
}
