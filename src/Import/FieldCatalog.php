<?php

declare(strict_types=1);

namespace DeepFind\Import;

/**
 * Description des champs internes pour le mapping visuel : libellé, aide, caractère obligatoire,
 * et noms usuels dans les flux (Magento, Google Shopping, exports maison) pour la pré-sélection automatique.
 */
final class FieldCatalog
{
    /** @var array<string, array{label:string, help:string, synonyms:list<string>}> */
    private const PRODUCT = [
        'sku' => ['label' => 'SKU (référence)', 'help' => 'Référence unique du produit parent ou simple', 'synonyms' => ['sku', 'reference', 'ref', 'mpn', 'product_sku', 'code']],
        'name' => ['label' => 'Nom du produit', 'help' => 'Recherche (poids fort) et affichage', 'synonyms' => ['name', 'title', 'nom', 'product_name', 'libelle']],
        'url' => ['label' => 'URL de la fiche produit', 'help' => 'Adresse complète en http(s)://', 'synonyms' => ['url', 'link', 'product_url', 'url_key', 'url_path']],
        'price' => ['label' => 'Prix normal', 'help' => 'Prix de référence (barré en cas de promotion)', 'synonyms' => ['price', 'regular_price', 'prix', 'base_price']],
        'parent_id' => ['label' => 'Identifiant du parent', 'help' => 'Flux « à plat » : rattache un enfant à son parent (vide pour un parent)', 'synonyms' => ['parent_id', 'parent', 'parent_sku', 'item_group_id', 'configurable_id']],
        'id' => ['label' => 'Identifiant', 'help' => 'entity_id Magento (conseillé, sinon le SKU est utilisé)', 'synonyms' => ['id', 'entity_id', 'product_id']],
        'final_price' => ['label' => 'Prix final (promotion incluse)', 'help' => 'Prix réellement payé ; exportez le prix final Magento', 'synonyms' => ['final_price', 'special_price', 'sale_price', 'prix_promo', 'promo_price']],
        'image' => ['label' => 'Image', 'help' => 'Vignette (idéalement l\'image cache redimensionnée)', 'synonyms' => ['image', 'image_link', 'image_url', 'small_image', 'thumbnail', 'picture']],
        'brand' => ['label' => 'Marque', 'help' => 'Filtre « Marques » à gauche du panneau', 'synonyms' => ['brand', 'manufacturer', 'marque', 'fabricant']],
        'in_stock' => ['label' => 'En stock (oui/non)', 'help' => '1/0, oui/non, « En stock »… sont reconnus', 'synonyms' => ['in_stock', 'is_in_stock', 'stock_status', 'availability', 'disponibilite', 'available']],
        'salable_qty' => ['label' => 'Quantité vendable', 'help' => 'Quantité vendable MSI', 'synonyms' => ['salable_qty', 'qty', 'quantity', 'stock_qty', 'stock', 'quantite']],
        'type' => ['label' => 'Type de produit', 'help' => 'simple, configurable, bundle…', 'synonyms' => ['type', 'type_id', 'product_type']],
        'ean' => ['label' => 'EAN / GTIN', 'help' => 'Recherche exacte par code-barres', 'synonyms' => ['ean', 'gtin', 'ean13', 'barcode', 'upc']],
        'image_hover' => ['label' => 'Image au survol', 'help' => 'Deuxième image', 'synonyms' => ['image_hover', 'additional_image_link', 'additional_image', 'image_2', 'image2']],
        'short_description' => ['label' => 'Description courte', 'help' => 'Recherche (poids faible)', 'synonyms' => ['short_description', 'resume', 'summary', 'accroche']],
        'description' => ['label' => 'Description', 'help' => 'Recherche (poids très faible), HTML retiré', 'synonyms' => ['description', 'long_description', 'desc']],
        'margin_rate' => ['label' => 'Taux de marge (0 à 1)', 'help' => 'Confidentiel : classement uniquement. Si le flux est en %, ajoutez « to_float, divide:100 »', 'synonyms' => ['margin_rate', 'margin', 'marge', 'marge_pct', 'taux_marge']],
        'sales_30d' => ['label' => 'Ventes sur 30 jours', 'help' => 'Popularité (classement)', 'synonyms' => ['sales_30d', 'ventes_30j', 'sales', 'ventes', 'qty_ordered']],
        'created_at' => ['label' => 'Date de création', 'help' => 'Badge « Nouveau » et classement', 'synonyms' => ['created_at', 'date_creation', 'creation_date', 'news_from_date']],
        'rating' => ['label' => 'Note moyenne', 'help' => 'Affichage', 'synonyms' => ['rating', 'note', 'rating_summary', 'average_rating']],
        'reviews_count' => ['label' => 'Nombre d\'avis', 'help' => 'Affichage', 'synonyms' => ['reviews_count', 'nb_avis', 'review_count', 'reviews']],
        'search_keywords' => ['label' => 'Mots-clés de recherche', 'help' => 'Mots supplémentaires recherchables', 'synonyms' => ['search_keywords', 'meta_keyword', 'keywords', 'mots_cles', 'tags']],
        'manual_boost' => ['label' => 'Boost manuel (-10 à +10)', 'help' => 'Mise en avant éditoriale permanente', 'synonyms' => ['manual_boost', 'boost', 'priority', 'priorite']],
        'enabled' => ['label' => 'Activé (oui/non)', 'help' => '0 / non = produit ignoré', 'synonyms' => ['enabled', 'status', 'active', 'is_active', 'actif']],
    ];

    private const VARIANT = [
        'sku' => ['label' => 'SKU de la variante', 'help' => 'Recherchable : affiche le parent avec cette variante', 'synonyms' => ['sku', 'reference', 'ref', 'mpn']],
        'id' => ['label' => 'Identifiant', 'help' => 'entity_id de l\'enfant', 'synonyms' => ['id', 'entity_id', 'product_id']],
        'price' => ['label' => 'Prix normal', 'help' => 'Vide = prix du parent', 'synonyms' => ['price', 'regular_price', 'prix']],
        'final_price' => ['label' => 'Prix final', 'help' => 'Le prix « dès » du parent est le plus bas des variantes en stock', 'synonyms' => ['final_price', 'special_price', 'sale_price', 'prix_promo']],
        'in_stock' => ['label' => 'En stock', 'help' => 'Le parent est en stock si une variante l\'est', 'synonyms' => ['in_stock', 'is_in_stock', 'stock_status', 'availability']],
        'salable_qty' => ['label' => 'Quantité vendable', 'help' => '', 'synonyms' => ['salable_qty', 'qty', 'quantity', 'stock']],
        'ean' => ['label' => 'EAN / GTIN', 'help' => 'Recherche exacte', 'synonyms' => ['ean', 'gtin', 'barcode']],
        'url' => ['label' => 'URL de la variante', 'help' => 'Vide = URL du parent', 'synonyms' => ['url', 'link']],
        'image' => ['label' => 'Image de la variante', 'help' => '', 'synonyms' => ['image', 'image_link', 'small_image']],
    ];

    private const POST = [
        'id' => ['label' => 'Identifiant', 'help' => 'post_id Magefan', 'synonyms' => ['id', 'post_id', 'entity_id']],
        'title' => ['label' => 'Titre', 'help' => 'Recherche (poids fort)', 'synonyms' => ['title', 'titre', 'name']],
        'url' => ['label' => 'URL de l\'article', 'help' => 'Adresse complète', 'synonyms' => ['url', 'link', 'post_url']],
        'published_at' => ['label' => 'Date de publication', 'help' => 'Format ISO 8601 conseillé', 'synonyms' => ['published_at', 'publish_time', 'pubdate', 'date', 'creation_time']],
        'image' => ['label' => 'Image', 'help' => '', 'synonyms' => ['image', 'featured_image', 'post_image', 'thumbnail']],
        'excerpt' => ['label' => 'Résumé', 'help' => '', 'synonyms' => ['excerpt', 'short_content', 'resume', 'summary']],
        'content' => ['label' => 'Contenu', 'help' => 'HTML retiré', 'synonyms' => ['content', 'content_html', 'body', 'contenu']],
        'author' => ['label' => 'Auteur', 'help' => '', 'synonyms' => ['author', 'auteur', 'author_name']],
        'updated_at' => ['label' => 'Date de mise à jour', 'help' => '', 'synonyms' => ['updated_at', 'update_time', 'modified']],
        'categories' => ['label' => 'Catégories', 'help' => 'Plusieurs valeurs possibles', 'synonyms' => ['category', 'categories']],
        'tags' => ['label' => 'Étiquettes', 'help' => 'Plusieurs valeurs possibles', 'synonyms' => ['tag', 'tags']],
        'related_skus' => ['label' => 'SKU des produits liés', 'help' => 'Plusieurs valeurs possibles', 'synonyms' => ['sku', 'related_products', 'related_skus']],
        'manual_boost' => ['label' => 'Boost manuel', 'help' => '', 'synonyms' => ['manual_boost', 'boost']],
        'enabled' => ['label' => 'Publié (oui/non)', 'help' => '0 / non = ignoré', 'synonyms' => ['enabled', 'is_active', 'status', 'active']],
    ];

    /**
     * @return array{required: array<string,array>, secondary: array<string,array>}
     */
    public static function groups(string $kind, string $variantMode = 'nested'): array
    {
        if ($kind === 'posts') {
            $required = ['id', 'title', 'url', 'published_at'];
            $all = self::POST;
        } else {
            $required = ['sku', 'name', 'url', 'price'];
            if ($variantMode === 'flat') {
                $required[] = 'parent_id';
            }
            $all = self::PRODUCT;
            if ($variantMode !== 'flat') {
                // parent_id reste disponible en secondaire (peut servir en mode imbriqué, sans effet)
                $all = ['parent_id' => $all['parent_id']] + $all;
            }
        }
        $req = [];
        foreach ($required as $f) {
            $req[$f] = $all[$f];
        }
        return ['required' => $req, 'secondary' => array_diff_key($all, $req)];
    }

    public static function variants(): array
    {
        return self::VARIANT;
    }

    /** Champs qui acceptent plusieurs valeurs (listes). */
    public static function isList(string $kind, string $field): bool
    {
        return $kind === 'posts' && in_array($field, ['categories', 'tags', 'related_skus'], true);
    }
}
