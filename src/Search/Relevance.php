<?php

declare(strict_types=1);

namespace DeepFind\Search;

use DeepFind\Settings;

/**
 * Réglages de pertinence modifiables dans l'extranet (page « Pertinence & classement ») :
 *  - ordre d'importance des champs pour la recherche texte (un mot trouvé dans le nom compte plus que dans la description) ;
 *  - importance du score métier (stock, marge, popularité, nouveauté, boost) face à la pertinence texte.
 */
final class Relevance
{
    /** champ indexé => [libellé, aide, désactivable] — ordre par défaut */
    public const FIELDS = [
        'name' => ['Nom du produit', 'Toujours cherchable', false],
        'brand' => ['Marque', '« cressi » trouve les produits Cressi', true],
        'sku' => ['SKU du parent', 'La recherche exacte par SKU fonctionne même si désactivé', true],
        'all_skus' => ['SKU des variantes', 'Recherche partielle sur les références enfants', true],
        'search_keywords' => ['Mots-clés de recherche', 'Champ « search_keywords » du flux', true],
        'category_terms' => ['Catégories', 'Noms des catégories et de leurs parents', true],
        'attr_values' => ['Valeurs d\'attributs', 'Couleur, taille, usage…', true],
        'all_eans' => ['EAN / GTIN', 'Codes-barres', true],
        'short_description' => ['Description courte', '', true],
        'description' => ['Description', 'Beaucoup de texte : à laisser en dernier', true],
    ];

    public const IMPORTANCE = [
        'low' => ['Faible (recommandé)', 'Le score métier ne départage que des produits aussi pertinents les uns que les autres.'],
        'medium' => ['Moyenne', 'Le score métier passe avant le champ où le mot a été trouvé (nom, description…) et l\'exactitude.'],
        'high' => ['Forte', 'À nombre de mots trouvés égal, le score métier passe avant les fautes de frappe et la proximité des mots.'],
    ];

    public const MATCHING = [
        'exact_then_approx' => ['Exacts, sinon approchants signalés (recommandé)', 'Tous les mots doivent être trouvés. Si aucun produit ne correspond, des produits approchants sont proposés avec la mention « Aucun résultat exact », et la recherche compte comme « sans résultat » dans les statistiques.'],
        'strict' => ['Exacts uniquement', 'Tous les mots doivent être trouvés, sinon « Aucun résultat ». Le plus précis, mais une recherche longue ou mal orthographiée peut ne rien donner.'],
        'loose' => ['Le plus de résultats possible', 'Le moteur retire des mots jusqu\'à trouver des produits : il affiche presque toujours quelque chose, même sans rapport (« barbe à papa » → produits contenant « barbe »…).'],
    ];

    public static function matchingMode(): string
    {
        $v = Settings::get('matching_mode');
        return isset(self::MATCHING[$v]) ? $v : 'exact_then_approx';
    }

    /** Seuil de pertinence minimum (0 à 1) sous lequel un produit n'est pas renvoyé ; 0 = désactivé. */
    public static function minScore(): float
    {
        return max(0.0, min(0.99, Settings::float('min_relevance')));
    }

    /** @return list<array{field:string, enabled:bool}> */
    public static function fields(): array
    {
        $saved = Settings::json('searchable_fields');
        $out = [];
        $seen = [];
        foreach ($saved as $row) {
            $f = (string) ($row['field'] ?? '');
            if (isset(self::FIELDS[$f]) && !isset($seen[$f])) {
                $out[] = ['field' => $f, 'enabled' => $f === 'name' || !empty($row['enabled'])];
                $seen[$f] = true;
            }
        }
        foreach (array_keys(self::FIELDS) as $f) {
            if (!isset($seen[$f])) {
                $out[] = ['field' => $f, 'enabled' => true];
            }
        }
        return $out;
    }

    /** @return list<string> */
    public static function searchableAttributes(): array
    {
        return array_values(array_map(static fn ($r) => $r['field'], array_filter(self::fields(), static fn ($r) => $r['enabled'])));
    }

    public static function importance(): string
    {
        $v = Settings::get('biz_importance');
        return isset(self::IMPORTANCE[$v]) ? $v : 'low';
    }

    /** @return list<string> */
    public static function rankingRules(?string $importance = null, ?bool $fieldFirst = null): array
    {
        $rules = match ($importance ?? self::importance()) {
            'high' => ['words', 'sort', 'biz_score:desc', 'typo', 'proximity', 'attribute', 'exactness'],
            'medium' => ['words', 'sort', 'typo', 'proximity', 'biz_score:desc', 'attribute', 'exactness'],
            default => ['words', 'sort', 'typo', 'proximity', 'attribute', 'exactness', 'biz_score:desc'],
        };
        // « Priorité au champ où le mot est trouvé » : l'ordre des champs compte juste après le nombre de mots trouvés.
        if ($fieldFirst ?? Settings::get('attribute_first') === '1') {
            $rules = array_values(array_diff($rules, ['attribute']));
            array_splice($rules, array_search('sort', $rules, true) + 1, 0, ['attribute']);
        }
        return $rules;
    }
}
