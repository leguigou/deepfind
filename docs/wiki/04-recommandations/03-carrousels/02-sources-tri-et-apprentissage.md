# Sources, tri et apprentissage

## Ordre de construction de la liste

1. **Produits épinglés**, dans l'ordre choisi (introuvables, désactivés ou en rupture avec « en stock uniquement » : ignorés).
2. Puis la **source** jusqu'au nombre de produits demandé, sans doublon ni produit de la page :
   - *Automatique* : filtre des conditions + tri ;
   - *Manuelle* : rien de plus (uniquement les épinglés) ;
   - *Similaires* : même **catégorie la plus précise** (chemin de catégorie le plus long) et/ou même **marque** que le produit de la page ;
   - *Personnalisé* : « vus récemment » (ordre de l'historique) ou « vous aimerez aussi » (catégories et marques de l'historique, produits déjà vus exclus).
3. Sans contexte (pas de produit dans la page, pas d'historique ou pas de consentement) : **sélection automatique** à la place.

Toujours exclus : produits désactivés (manuellement ou absents du dernier flux), et en rupture si l'option est cochée. Les conditions limitent aussi les sources « similaires » et « personnalisé ».

## Tris disponibles

| Tri | Ordre utilisé par le moteur |
|---|---|
| Score métier | `biz_score` décroissant |
| Meilleures ventes | ventes 30 jours, puis score métier |
| Les plus cliqués | clics dans la recherche sur 30 jours (`clicks_30d`), puis score métier |
| Nouveautés | date de création, puis score métier |
| Plus grosses remises | % de remise, puis score métier |
| Mieux notés | note, puis nombre d'avis |
| Prix croissant / décroissant | prix final |

## Apprentissage

Option par carrousel : la liste (jusqu'à 3 fois plus large que le nombre affiché) est **reclassée selon le taux de clic de chaque produit dans ce carrousel** sur 30 jours, avec un lissage :

`CTR lissé = (clics + 20 × CTR moyen du carrousel) / (affichages + 20)`

Un produit encore peu montré garde le CTR moyen (il n'est pas écarté) ; un produit souvent montré et peu cliqué descend ; à égalité, l'ordre de la source est gardé. Les chiffres viennent de `carousel_product_stats` (affichage = produit visible dans le bandeau).

## Reconnaître le produit d'une page

Pour « similaires », le produit est cherché par le SKU indiqué par le site (`<meta name="deepfind:sku" content="…">` ou attribut `data-df-sku`), sinon par l'**adresse de la page** comparée au champ `url_path` des produits (indexé depuis l'URL du flux ; un import complet est nécessaire après la mise à jour qui l'a introduit).
