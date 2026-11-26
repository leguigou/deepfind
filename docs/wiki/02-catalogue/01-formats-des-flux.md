# Formats des flux

Deux options pour les flux :

- **Le format DeepFind (recommandé)** : le mapping par défaut fonctionne sans modification. Exemples complets : `samples/products_fr.xml`, `samples/products_fr_delta.xml`, `samples/blog_fr.xml`.
- **Votre propre format** : vous adaptez le mapping de la source (mapping visuel ou JSON). Exemple : `samples/mapping_flat_example.json` pour `samples/products_fr_flat.xml`.

**Règles communes :**
- Encodage UTF-8.
- Textes entre `<![CDATA[ ]]>`.
- Prix avec un point ou une virgule (les deux sont acceptés).
- Dates au format ISO 8601.
- Fichier `.xml` ou `.xml.gz`.
- Flux lu au fil de l'eau : taille illimitée.

## Produits : champs internes

| Champ | Type | Obligatoire | Usage |
|---|---|---|---|
| `id` | texte | conseillé | identifiant (entity_id) |
| `sku` | texte | ✅ | recherche exacte prioritaire |
| `type` | simple/configurable… | | info |
| `parent_id` | texte | flux à plat | rattachement d'un enfant à son parent |
| `enabled` | 0/1 | | 0 = produit non vendable (voir « Produits désactivés ») |
| `name` | texte | ✅ | recherche (poids fort), affichage |
| `brand` | texte | conseillé | filtre « Marques » |
| `url`, `image`, `image_hover` | URL http(s) | url ✅ | affichage (utilisez l'image *cache* redimensionnée de Magento) |
| `short_description`, `description` | texte/HTML | | recherche (poids faible), le HTML est retiré |
| `ean` | texte | | recherche exacte |
| `child_skus` | liste | | SKU des enfants listés dans le parent (`SU-1,SU-2`, séparés par virgule, point-virgule ou `\|`) : chercher l'un d'eux affiche le parent (voir ci-dessous) |
| `price` | décimal | ✅ (ou final_price) | prix barré si promo |
| `final_price` | décimal | conseillé | prix affiché (exportez le **prix final** Magento, règles de prix catalogue incluses) |
| `in_stock`, `salable_qty` | oui/non, entier | conseillé | exportez la **quantité vendable MSI**. Pour `in_stock`, sont reconnus comme « en stock » : 1, un nombre > 0, oui/yes/true, « En stock », `in_stock`, `available`, `preorder`, `backorder` (Google Shopping) et `https://schema.org/InStock`. Tout le reste = rupture |
| `margin_rate` | 0 à 1 | | **classement uniquement, jamais envoyé au site** |
| `sales_30d` | entier | | popularité |
| `created_at` | date | | badge « Nouveau » et classement |
| `rating`, `reviews_count` | | | affichage |
| `search_keywords` | texte | | mots-clés en plus |
| `manual_boost` | -10 à +10 | | boost éditorial permanent |

**Blocs particuliers :**
- `categories` : une ou plusieurs catégories, avec leur chemin « A > B > C ».
- `attributes` : attributs génériques (code, libellé, `filterable="1"`). Les attributs filtrables peuvent devenir des filtres du widget ([Layers, filtres et tris](../affichage-du-widget/layers-filtres-et-tris)).
- `variants` : les enfants, avec les champs `id, sku, ean, url, image, price, final_price, in_stock, salable_qty`, plus leurs propres attributs (taille…).

## Parents et enfants Magento

- **`"variant_mode": "nested"`** (recommandé) : les enfants sont dans `<variants>` sous le parent.
- **`"variant_mode": "flat"`** : chaque produit est sur sa propre ligne et les enfants ont un `parent_id`. Le regroupement se fait pendant l'import, dans une table de travail MySQL. L'ordre des lignes n'a pas d'importance. Mettez `"parent_match": "sku"` si `parent_id` contient le SKU du parent.

- **Liste des enfants dans le parent** (flux de type Google Shopping, ex. `<skus_enfants>SU-1,SU-2</skus_enfants>`, sans ligne propre pour chaque enfant) : mappez ce champ sur **SKU des enfants** (proposé automatiquement). Chercher un de ces SKU affiche le parent ; son prix est présenté « dès » (le flux ne donne ni prix ni stock par enfant). Restez en mode imbriqué.

**Résultat dans tous les cas :**
- Un seul document par parent.
- Prix « dès » = celui de l'enfant en stock le moins cher.
- Le parent est « en stock » si au moins un enfant l'est.
- Les SKU et EAN des enfants sont recherchables : chercher le SKU d'un enfant affiche le parent avec la variante.
- Les attributs des enfants (taille…) deviennent des filtres du parent.

## Flux delta (stock, prix, suppressions)

Même structure que le flux complet, avec seulement le `sku` et les champs modifiés, plus un bloc `<deleted><sku>…</sku></deleted>`.

- Un SKU d'enfant met à jour l'enfant et recalcule le parent.
- Un SKU inconnu est signalé dans le rapport d'import et sera créé au prochain import complet.
- Les catégories et attributs ne changent qu'à l'import complet.

## Blog (Magefan)

Champs : `title`, `url`, `published_at` (obligatoires), `id` (conseillé), plus `image`, `excerpt`, `content`, `author`, `updated_at`, `categories/category`, `tags/tag`, `related_products/sku`, `enabled`. Seul l'import complet est disponible (le volume est faible).

- **Sans identifiant** (« non mappé ») : chaque article reçoit un identifiant calculé à partir de son **adresse** (chemin, sans domaine ni paramètres). Il reste le même d'un import à l'autre tant que l'adresse ne change pas ; un article dont l'adresse change est vu comme un nouvel article.
- **Sitemap du blog** (`<urlset><url><loc>…</loc><lastmod>…</lastmod>…</url></urlset>`) : élément répété `url` ; `loc` et `lastmod` sont reconnus d'office pour l'URL et la date de publication. Les balises à préfixe (`cabesto:title`, `cabesto:category`, `image:image/image:loc`) se choisissent dans la liste ; l'image (`image:image/image:loc`) est à choisir à la main. Un article sans image ou sans catégorie est accepté.

```xml
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:cabesto="https://www.cabesto.com/schemas/sitemap-blog/1.0">
  <url>
    <loc>https://www.example.com/fr/conseils-guides/piscine/traitement-au-sel</loc>
    <lastmod>2026-07-02</lastmod>
    <cabesto:title>Traitement au sel de piscine</cabesto:title>
    <cabesto:category>Piscine</cabesto:category>
    <image:image><image:loc>https://www.example.com/media/piscine-au-sel.jpg</image:loc></image:image>
  </url>
</urlset>
```

Mapping correspondant : Titre ← `cabesto:title`, URL ← `loc`, Date de publication ← `lastmod`, Catégories ← `cabesto:category`, Image ← `image:image/image:loc`, Identifiant : non mappé.

## Champs calculés à l'import

En plus des champs du flux, l'import calcule (`src/Import/ProductBuilder.php`) : le prix « dès » et `has_price_range` (prix différents selon les déclinaisons), la remise, le stock agrégé, la nouveauté (`is_new`), le **score métier** `biz_score` (ventes, clics, stock, marge, nouveauté, boosts) et `url_path`, le chemin de la page produit (sert à reconnaître le produit d'une page pour les carrousels « similaires »).
