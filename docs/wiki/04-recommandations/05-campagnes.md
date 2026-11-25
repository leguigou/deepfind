# Campagnes de produits à pousser

*Recommandations › Campagnes* regroupe les produits que vous voulez mettre en avant sur une période (fin de série, nouveauté, opération commerciale) et suit leurs résultats.

> 💡 Une campagne **mesure** ; elle ne change pas l'affichage. Pour pousser les produits, utilisez ensuite un [carrousel](carrousels/creer-un-carrousel) (produits épinglés), un [résultat personnalisé](../recherche-et-pertinence/resultats-personnalises) ou une [règle de boost](../recherche-et-pertinence/regles-de-boost), puis suivez l'effet ici.

## Créer une campagne

- **Nom**, **objectif** (texte libre, ex. « écouler le stock de palmes 2025 »), **début** (vide : date de création) et **fin** (vide : sans fin), **active**.
- **Produits** : recherche par nom, SKU ou marque, ou **liste de SKU collée** (un par ligne, ou séparés par des virgules). 100 produits au plus. Un SKU de déclinaison retrouve son produit.

## Le tableau de bord

Période suivie : du début à la fin (ou à aujourd'hui), comparée à la **période précédente de même durée**.

| Indicateur | Source |
|---|---|
| Chiffre d'affaires, commandes, ajouts au panier | événements envoyés par le site ([Parcours d'achat](../analyse/parcours-d-achat)) |
| Clics | recherche et carrousels |
| Vus dans les carrousels | produit visible à l'écran dans un carrousel |
| Position (recherche) | position moyenne du produit quand il est cliqué dans la recherche, et nombre de ces clics |
| vs avant | évolution du chiffre d'affaires par rapport à la période précédente |
| Badges | **rupture** (produit en rupture : à retirer des mises en avant) ; **introuvable dans le catalogue** (SKU absent du flux) |

**Où les ventes ont été faites** : chiffre d'affaires par **emplacement**, c'est-à-dire l'endroit où le produit a été cliqué en dernier par le visiteur le même jour avant d'être mis au panier ou acheté : *Recherche*, *Carrousel « nom »*, ou *Sans clic DeepFind* (navigation du site, lien externe…).

## Détails techniques

- Table `campaigns` (nom, période, objectif, SKU en JSON) ; code `src/Rules/Campaigns.php`, `src/Admin/CampaignsController.php`, gabarits `campaigns.php` et `campaign_edit.php`, script `public/assets/campaigns.js`.
- Résultats : `audience_products` (clics, paniers, commandes, CA par produit et par jour), `carousel_product_stats` (affichages), `click_logs` (position), `audience_attrib` (par emplacement). Aucune donnée personnelle.
- **Attribution par emplacement** : à chaque clic sur un produit (recherche, carrousel), le serveur retient pour la journée, sous l'empreinte du jour du visiteur, l'emplacement de ce clic (en mémoire seulement). Un ajout au panier ou une commande de ce produit lui est attribué ; sans clic, `direct`.
- Les campagnes sont copiées avec les autres réglages entre projets (*Paramètres › Projets*).
