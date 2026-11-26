# Campagnes de produits à pousser

*Recommandations › Campagnes* regroupe les produits que vous voulez mettre en avant sur une période (fin de série, nouveauté, opération commerciale) et suit leurs résultats.

> 💡 Une campagne **mesure** ; elle ne change pas l'affichage. Pour pousser les produits, utilisez ensuite un [carrousel](carrousels/creer-un-carrousel) (produits épinglés), un [résultat personnalisé](../recherche-et-pertinence/resultats-personnalises) ou une [règle de boost](../recherche-et-pertinence/regles-de-boost), puis suivez l'effet ici.

## Créer une campagne

- **Nom**, **objectif** (texte libre, ex. « écouler le stock de palmes 2025 »), **début** (vide : date de création) et **fin** (vide : sans fin), **active**.
- **Produits, un par un** : recherche par nom, SKU ou marque, ou **liste de SKU collée** (un par ligne, ou séparés par des virgules). 100 produits au plus. Un SKU de déclinaison retrouve son produit.
- **Produits par règles** (facultatif, en plus ou à la place) : mêmes conditions que les [règles de boost](../recherche-et-pertinence/regles-de-boost) et les carrousels — marque, catégorie, type, promotion, nouveauté, stock, prix, attributs… avec *est*, *n'est pas*, *contient*, *ne contient pas*, *≥*, *≤* ; **toutes** les conditions ou **au moins une**.
  - Exemple : *Marque est « Ecoflow »* et *Catégorie contient « panneau solaire »* : tous les panneaux Ecoflow du catalogue sont suivis.
  - La liste suit le **catalogue du jour** : un nouveau produit qui remplit les règles entre dans la campagne, un produit retiré du flux en sort (ses ventes passées ne sont plus comptées).
  - Après l'enregistrement, le message indique combien de produits correspondent aujourd'hui. Au-delà de 1 000, seuls les 1 000 premiers sont suivis : précisez les règles.
- Au moins un produit ou une règle est nécessaire.

## Le tableau de bord

Période suivie : du début à la fin (ou à aujourd'hui), comparée à la **période précédente de même durée**.

| Indicateur | Source |
|---|---|
| Chiffre d'affaires, commandes, ajouts au panier | événements envoyés par le site ([Parcours d'achat](../analyse/parcours-d-achat)) |
| Clics | recherche et carrousels |
| Vus dans les carrousels | produit visible à l'écran dans un carrousel |
| Position (recherche) | position moyenne du produit quand il est cliqué dans la recherche, et nombre de ces clics |
| vs avant | évolution du chiffre d'affaires par rapport à la période précédente |
| Badges | **rupture** (produit en rupture : à retirer des mises en avant) ; **introuvable dans le catalogue** (SKU absent du flux) ; **règle** (produit ajouté par les règles) |

Le tableau montre d'abord les produits choisis un par un, puis ceux des règles **les plus actifs** (chiffre d'affaires, commandes, paniers, clics, affichages), 100 lignes en tout. Les autres produits des règles ne sont pas listés mais **comptent dans les totaux** (une note l'indique sous le tableau). La liste des campagnes indique le nombre de produits, « dont N par règles ».

**Où les ventes ont été faites** : chiffre d'affaires par **emplacement**, c'est-à-dire l'endroit où le produit a été cliqué en dernier par le visiteur le même jour avant d'être mis au panier ou acheté : *Recherche*, *Carrousel « nom »*, ou *Sans clic DeepFind* (navigation du site, lien externe…).

## Détails techniques

- Table `campaigns` (nom, période, objectif, SKU en JSON, règles : `match_all` et `conditions` en JSON, même format que les carrousels) ; code `src/Rules/Campaigns.php`, `src/Admin/CampaignsController.php`, gabarits `campaigns.php` et `campaign_edit.php`, script `public/assets/campaigns.js`.
- Produits des règles : `Campaigns::ruleProducts()` — filtre du moteur construit par `PageRules::filter()` (« contient » transformé en liste de valeurs du catalogue, en cache jusqu'au prochain import), 1 000 produits au plus (`Campaigns::RULE_LIMIT`), recalculé à chaque affichage. Validation des conditions : `CustomResults::sanitizeRules()`, éditeur `public/assets/conditions.js`.
- Résultats : `audience_products` (clics, paniers, commandes, CA par produit et par jour), `carousel_product_stats` (affichages), `click_logs` (position), `audience_attrib` (par emplacement). Aucune donnée personnelle.
- **Attribution par emplacement** : à chaque clic sur un produit (recherche, carrousel), le serveur retient pour la journée, sous l'empreinte du jour du visiteur, l'emplacement de ce clic (en mémoire seulement). Un ajout au panier ou une commande de ce produit lui est attribué ; sans clic, `direct`.
- Les campagnes sont copiées avec les autres réglages entre projets (*Paramètres › Projets*).
