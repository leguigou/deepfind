# Sources, tri et apprentissage

## Ordre de construction de la liste

1. **Produits épinglés**, dans l'ordre choisi (introuvables, désactivés ou en rupture avec « en stock uniquement » : ignorés).
2. Puis la **source** jusqu'au nombre de produits demandé, sans doublon ni produit de la page :
   - *Automatique* : filtre des conditions + tri ;
   - *Manuelle* : rien de plus (uniquement les épinglés) ;
   - *Similaires* : même **catégorie la plus précise** (chemin de catégorie le plus long) et/ou même **marque** que le produit de la page ;
   - *Personnalisé* : « vus récemment » (ordre de l'historique) ou « vous aimerez aussi » (catégories et marques de l'historique, produits déjà vus exclus).
3. Sans contexte (pas de produit dans la page, pas d'historique ou pas de consentement) : **sélection automatique** à la place.

## Tendances, achetés ensemble, aussi regardés, page panier

| Source | Produits proposés |
|---|---|
| **Tendances** | produits dont l'intérêt monte : clics (recherche, carrousels) + 3 × paniers + 5 × commandes des 7 derniers jours, rapportés à la moyenne hebdomadaire des 4 semaines d'avant (3 points minimum) |
| **Souvent achetés ensemble** | produits commandés en même temps que le produit de la page (fiche produit) ou ceux du panier, sur 90 jours ; complétés par « aussi regardé » tant que les commandes sont peu nombreuses |
| **Les visiteurs ont aussi regardé** | produits ouverts par les mêmes visiteurs dans la même journée : clic dans la recherche ou un carrousel, fiche produit (si le site indique `<meta name="deepfind:sku">`), ajout au panier |
| **Page panier** | compléments des produits du panier (achetés ensemble, puis aussi regardés), sans les produits déjà dans le panier |

Sans produit de référence (pas de produit dans la page, panier vide) ou sans assez d'activité : **sélection automatique** (conditions et tri du carrousel).

### Panier du visiteur

Le widget lit le panier dans les données que **Magento** garde déjà dans le navigateur (`mage-cache-storage`, section `cart` : SKU, quantités, sous-total), ou le site le donne :

```js
DeepFind.cart({ items: [{ sku: 'C4-PREDATOR', qty: 1 }], total: 38.9, freeShipping: 50 });
```

Seuls les SKU, le total et le seuil sont envoyés, avec la demande des carrousels (`ci`, `ct`, `fs`), et seulement si un carrousel actif en a besoin.

### Livraison offerte

- **Seuil** : *Paramètres › Widget sur le site › Livraison offerte à partir de* (€ TTC), ou donné par le site (`freeShipping` de `DeepFind.cart`, ou attribut `data-free-shipping="50"` sur la balise du widget), qui est prioritaire.
- Sur un carrousel *Page panier*, quand il manque un montant : message **« Plus que 11,10 € pour profiter de la livraison offerte »** avec une barre de progression, et les produits qui suffisent à atteindre le seuil passent en tête (dans leur ordre de pertinence). Seuil atteint : « La livraison vous est offerte ! ». Textes modifiables dans *Paramètres › Variables*.
- Vérification : liste de contrôle de la fiche, champs *Panier de test* (SKU) et *Total du panier*.

### Données

Table `reco_pairs` : paires de produits (`b` achetés ensemble, `v` regardés ensemble) par jour, gardées 90 jours (`Recos::WINDOW_DAYS`), sans visiteur. La liste des produits regardés par un visiteur n'existe qu'en mémoire, le temps de sa journée. Code : `src/Rules/Recos.php`.

## Adapter les produits selon la page

Section *4. Produits › Adapter les produits selon la page* : liste de lignes `{adresse → conditions}` (30 au plus, colonne `page_filters` de la table `carousels`, JSON `[{url: {match, text}, match_all, conditions}]`).

- Au moment de calculer le carrousel pour une page (`Carousels::forPath`), la **première** ligne dont l'adresse correspond ajoute ses conditions aux filtres (après les conditions générales). Elles s'appliquent à toutes les sources sauf les produits épinglés.
- La liste en cache est distincte par ligne appliquée (clé `carprod:…:p<n>`) : une page « pêche » et une page « nautisme » n'ont pas la même liste.
- Aucune ligne ne correspond : carrousel inchangé. Aucun produit ne remplit les conditions : carrousel absent de la page.
- Catégorie « contient » : traduit en « est » pour toutes les catégories du catalogue qui contiennent le texte (sans tenir compte des majuscules ni des accents), comme les règles selon la page.
- Vérification : liste de contrôle de la fiche, champ « Page du site » (message « Adaptation selon la page appliquée »).

Pour **ne pas afficher** un carrousel sur certaines pages : *Pages exclues* (section 1 de la fiche), elles l'emportent sur tout le reste.

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
