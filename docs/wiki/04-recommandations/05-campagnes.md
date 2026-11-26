# Campagnes de produits à pousser

*Recommandations › Campagnes* regroupe les produits que vous voulez mettre en avant sur une période (fin de série, nouveauté, opération commerciale) et suit leurs résultats.

> 💡 Une campagne **mesure** les résultats de ses produits. Avec **Mettre en avant pendant la campagne**, elle les pousse aussi dans la recherche et les carrousels, **seulement pendant ses dates** (voir ci-dessous). Vous pouvez aussi utiliser un [carrousel](carrousels/creer-un-carrousel) (produits épinglés), un [résultat personnalisé](../recherche-et-pertinence/resultats-personnalises) ou une [règle de boost](../recherche-et-pertinence/regles-de-boost), puis suivre l'effet ici.

## Créer ou modifier une campagne

- **+ Nouvelle campagne** ouvre le formulaire. Après l'enregistrement, la page des **résultats** s'affiche.
- Dans la liste : cliquer sur le **nom** ouvre le tableau de bord ; **Modifier** ouvre directement le formulaire, seul. Depuis les résultats : **Modifier la campagne**.
- **Supprimer cette campagne** : en bas du formulaire.

### Le formulaire

- **Nom**, **objectif** (texte libre, ex. « écouler le stock de palmes 2025 »), **début** (vide : date de création) et **fin** (vide : sans fin), **active**.
- **Produits, un par un** : recherche par nom, SKU ou marque, ou **liste de SKU collée** (un par ligne, ou séparés par des virgules). 100 produits au plus. Un SKU de déclinaison retrouve son produit.
- **Produits par règles** (facultatif, en plus ou à la place) : mêmes conditions que les [règles de boost](../recherche-et-pertinence/regles-de-boost) et les carrousels — marque, catégorie, type, promotion, nouveauté, stock, prix, attributs… avec *est*, *n'est pas*, *contient*, *ne contient pas*, *≥*, *≤* ; **toutes** les conditions ou **au moins une**.
  - Exemple : *Marque est « Ecoflow »* et *Catégorie contient « panneau solaire »* : tous les panneaux Ecoflow du catalogue sont suivis.
  - La liste suit le **catalogue du jour** : un nouveau produit qui remplit les règles entre dans la campagne, un produit retiré du flux en sort (ses ventes passées ne sont plus comptées).
  - Après l'enregistrement, le message indique combien de produits correspondent aujourd'hui. Au-delà de 1 000, seuls les 1 000 premiers sont suivis : précisez les règles.
- Au moins un produit ou une règle est nécessaire.

## Mettre en avant pendant la campagne

En bas du formulaire, deux interrupteurs. La mise en avant commence le **jour de début à 0 h** et s'arrête le **jour de fin à 23 h 59** (heure de Paris), puis tout redevient normal sans rien à défaire. Sous les dates, un message rappelle la période exacte. Pas d'heure précise pour l'instant (ex. « à partir de 20 h »). Seuls les produits **en stock** sont mis en avant.

- **Dans la recherche** : les produits de la campagne remontent **quand ils correspondent à la recherche**, avec une force à choisir : légère (×1,5), moyenne (×2, par défaut) ou forte (×3). C'est le même mécanisme qu'une [règle de boost](../recherche-et-pertinence/regles-de-boost) sur toutes les recherches : « gilet » fait remonter les gilets de la campagne, « tente » ne les fait pas apparaître. Dans l'aperçu interne, la fenêtre « Détails » d'un produit indique « Campagne « nom » » dans les règles de boost appliquées.
- **Dans les carrousels** : les **premières places** (2 par défaut, de 1 à 6) vont aux produits de la campagne **les plus susceptibles d'être vendus** : plus de ventes sur 30 jours, puis plus de clics, puis meilleur score métier.
  - **Seulement là où ils ont leur place** : le produit doit remplir les conditions du carrousel et de l'adaptation selon la page (un carrousel « Pêche » ne montre pas un produit de nautisme) et, pour un carrousel « produits similaires », ressembler au produit de la page (même catégorie, même marque… selon le carrousel).
  - Carrousels concernés : choix **automatique**, **produits similaires** et **tendances**. Les carrousels de produits choisis à la main, du panier (achetés ensemble, aussi regardés, page panier) et de l'historique du visiteur ne changent pas.
  - Les produits épinglés du carrousel restent en premier ; viennent ensuite ceux de la campagne, puis le reste.
  - Plusieurs campagnes en même temps : chacune prend ses places, dans l'ordre des campagnes, sans dépasser la taille du carrousel.
- Dans la liste, le badge **mis en avant** signale une campagne dont la mise en avant est en cours ; sur la page des résultats, l'état (en cours, à venir, arrêtée), la force et les places sont rappelés.
- **Pour mesurer l'effet** : comparez les résultats de la campagne à la période précédente (tableau de bord) et regardez « Où les ventes ont été faites » (recherche, carrousel).

## Le tableau de bord (résultats)

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

- Table `campaigns` (nom, période, objectif, SKU en JSON, règles : `match_all` et `conditions` en JSON, même format que les carrousels) ; code `src/Rules/Campaigns.php`, `src/Admin/CampaignsController.php`, gabarits `campaigns.php` et `campaign_edit.php` (formulaire) et `campaign_results.php` (résultats), script `public/assets/campaigns.js`.
- Mise en avant : colonnes `highlight_search`, `search_boost`, `highlight_carousels`, `carousel_slots`. `Campaigns::live()` : campagnes en cours ce jour (jour calendaire de Paris) et identifiants de leurs produits (liste + règles), en cache jusqu'au changement de version des données. Recherche : `Campaigns::searchRules()`, règles de boost « virtuelles » ajoutées par `BoostRules::forQuery()` (condition sur l'identifiant du produit et le stock). Carrousels : `Carousels::campaignPicks()` (filtres du carrousel, de la page, de similarité, en stock ; tri `sales_30d`, `clicks_30d`, `biz_score`). Début et fin : `Campaigns::syncLive()`, appelé chaque minute par `schedule:run`, change la version des données du projet quand l'ensemble des campagnes en cours change (enregistrer ou supprimer une campagne la change aussi).
- Produits des règles : `Campaigns::ruleProducts()` — filtre du moteur construit par `PageRules::filter()` (« contient » transformé en liste de valeurs du catalogue, en cache jusqu'au prochain import), 1 000 produits au plus (`Campaigns::RULE_LIMIT`), recalculé à chaque affichage. Validation des conditions : `CustomResults::sanitizeRules()`, éditeur `public/assets/conditions.js`.
- Résultats : `audience_products` (clics, paniers, commandes, CA par produit et par jour), `carousel_product_stats` (affichages), `click_logs` (position), `audience_attrib` (par emplacement). Aucune donnée personnelle.
- **Attribution par emplacement** : à chaque clic sur un produit (recherche, carrousel), le serveur retient pour la journée, sous l'empreinte du jour du visiteur, l'emplacement de ce clic (en mémoire seulement). Un ajout au panier ou une commande de ce produit lui est attribué ; sans clic, `direct`.
- Les campagnes sont copiées avec les autres réglages entre projets (*Paramètres › Projets*).
