<?php

declare(strict_types=1);

namespace DeepFind\Search;

/**
 * Réglages Meilisearch des index produits / articles.
 */
final class IndexSettings
{
    private const LOCALES = ['fr' => 'fra', 'en' => 'eng', 'de' => 'deu', 'es' => 'spa', 'it' => 'ita', 'nl' => 'nld', 'pt' => 'por'];

    private const STOP_WORDS = [
        'fr' => ['le', 'la', 'les', 'l', 'de', 'des', 'du', 'd', 'un', 'une', 'et', 'pour', 'en', 'au', 'aux', 'a', 'avec', 'sur', 'par'],
        'en' => ['the', 'a', 'an', 'of', 'for', 'and', 'with', 'in', 'on', 'to'],
    ];

    /** @param list<string> $filterableAttrCodes */
    public static function products(string $lang, array $filterableAttrCodes, array $synonyms): array
    {
        $filterable = [
            'id', 'sku', 'all_skus', 'all_eans', 'brand', 'category_names', 'category_ids', 'in_stock', 'on_sale',
            'final_price', 'margin_rate', 'type', 'is_new',
        ];
        foreach ($filterableAttrCodes as $code) {
            $filterable[] = 'attr.' . $code;
        }
        $s = [
            // Ordre = importance (réglable dans l'extranet : Pertinence & classement)
            'searchableAttributes' => Relevance::searchableAttributes(),
            'filterableAttributes' => $filterable,
            'sortableAttributes' => ['sort_price', 'final_price', 'created_ts', 'in_stock', 'margin_rate', 'biz_score', 'sales_30d'],
            // "sort" après "words" : un tri demandé (prix, stock) ne fait pas passer un produit peu pertinent devant.
            // Position de biz_score selon l'importance choisie pour le score métier.
            'rankingRules' => Relevance::rankingRules(),
            'typoTolerance' => [
                'enabled' => true,
                'minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8],
                'disableOnAttributes' => ['sku', 'all_skus', 'all_eans'],
            ],
            'pagination' => ['maxTotalHits' => 1000],
            'faceting' => ['maxValuesPerFacet' => 100],
            'proximityPrecision' => 'byAttribute',
            'stopWords' => self::STOP_WORDS[$lang] ?? [],
            'synonyms' => $synonyms === [] ? new \stdClass() : $synonyms,
        ];
        if (isset(self::LOCALES[$lang])) {
            $s['localizedAttributes'] = [['attributePatterns' => ['*'], 'locales' => [self::LOCALES[$lang]]]];
        }
        return $s;
    }

    public static function posts(string $lang, array $synonyms): array
    {
        $s = [
            'searchableAttributes' => ['title', 'tags', 'categories', 'excerpt', 'content'],
            'filterableAttributes' => ['related_skus', 'categories', 'tags'],
            'sortableAttributes' => ['published_ts'],
            'rankingRules' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness', 'manual_boost:desc', 'published_ts:desc'],
            'stopWords' => self::STOP_WORDS[$lang] ?? [],
            'synonyms' => $synonyms === [] ? new \stdClass() : $synonyms,
            'pagination' => ['maxTotalHits' => 200],
        ];
        if (isset(self::LOCALES[$lang])) {
            $s['localizedAttributes'] = [['attributePatterns' => ['*'], 'locales' => [self::LOCALES[$lang]]]];
        }
        return $s;
    }
}
