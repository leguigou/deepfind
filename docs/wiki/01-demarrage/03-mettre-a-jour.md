# Mettre à jour DeepFind

Une mise à jour suit toujours les mêmes étapes, d'abord sur la **recette**, puis en production.

## Étapes

1. **Récupérer le code** de la version voulue (`git pull` sur la branche de production, ou déploiement habituel).
2. **Mettre à jour la base** : `php bin/console install`. Commande rejouable : elle crée les tables manquantes, ajoute les colonnes et élargit les listes de valeurs, sans rien effacer.
3. **Import complet** si la mise à jour le demande (nouveau champ indexé, ex. adresse des produits pour les carrousels « similaires ») : bouton dans *Catalogues › Imports* ou `php bin/console import:run --source=produits_fr`.
4. **Numéro du widget** : remplacer `?v=…` dans la balise du site par le numéro affiché dans *Paramètres › Intégration* (voir plus bas).
5. **Vérifier** : recherche sur le site, *Analyse › Temps réel & charge*, popins et carrousels avec l'aperçu.

## Le numéro `?v=` du widget

Le serveur renvoie **toujours le dernier fichier** du widget, quel que soit le numéro. Le numéro ne sert qu'à forcer les navigateurs à ne pas réutiliser leur copie en mémoire (gardée **24 heures**, réglage Apache de `/widget/`).

- Sans changer le numéro : les nouveaux visiteurs et la navigation privée reçoivent tout de suite la nouvelle version ; les autres jusqu'à 24 h plus tard. Un cache placé devant DeepFind (CDN, Varnish) peut aussi garder l'ancienne version jusqu'à son expiration.
- En changeant le numéro : tout le monde reçoit la nouvelle version immédiatement. **Recommandé à chaque mise à jour.**
- Le style (`deepfind.css`) suit automatiquement : c'est le script qui le charge, avec son propre numéro.

## Versions du widget

| Version | Nouveautés principales |
|---|---|
| 25 | Popins |
| 26 | Popins au contenu HTML (éditeur visuel) |
| 27 | Hauteur minimale des popins, croix à l'extérieur |
| 28 | Carrousels de produits |
| 29 | Aperçu direct, modèle de carte par carrousel |
| 30 | Variable `{{url}}` dans le modèle de carte |
| 31 | Lien de la carte modifiable |
| 32 | Fréquence « toujours » des popins |
| 33 | Flèches en chevron fin, classes du titre des carrousels |
| 34 | Textes du widget et variables personnalisées |
| 35 | Parcours d'achat : barre ouverte, fermeture sans clic, panier et commandes (DeepFind.track, dataLayer GA4), pages vues pour les parcours |
| 36 | Ciblage selon la provenance, panier du visiteur pour les recommandations (DeepFind.cart, panier Magento), message de livraison offerte, produit de la page pour « aussi regardé » |
| 37 | Notifications : invitation à s'abonner (bandeau, popin, cloche, bloc), abonnement Web Push |
| 38 | Notifications : croix de fermeture en haut à droite (invitation et fenêtre de la cloche), compteurs par appareil |
| 39 | Articles de blog aussi dans la colonne des résultats sur mobile (sous les premiers produits, ou sous « Aucun résultat ») ; feuille de style `?v=33` |
| 40 | Notifications : cloche cachée une fois le visiteur abonné (réglage) ; croix de fermeture dessinée (parfaitement centrée) ; abonnement vérifié auprès de DeepFind (message « Inscription impossible… » en cas d'échec, au lieu d'un échec silencieux) ; abonnement fait avec d'autres clés (autre serveur DeepFind) refait automatiquement ; feuille de style `?v=34` |

## Nouveautés récentes (extranet)

À installer avec `php bin/console install` (nouvelles colonnes), puis widget `?v=40`.

| Où | Nouveauté |
|---|---|
| Recommandations › Campagnes | Produits choisis **par règles** (comme les boosts) ; **mise en avant pendant la campagne** dans la recherche et les carrousels ; résultats et formulaire sur deux pages, bouton « Modifier » ([Campagnes](../recommandations/campagnes)) |
| Recommandations › Envoi de notifications | **Groupe de test**, liste des abonnés, **Voir** et **Dupliquer** un message, **Importer une image** ([Notifications](../recommandations/notifications)) |
| Catalogues › Articles de blog | Nouvelle page : articles importés, recherche, catégories ([Articles de blog](../catalogue/articles-de-blog)) |
| Catalogues › Aperçu interne | Déplacé depuis Recommandations |
| Catalogues › Flux & mapping | Blog en **sitemap** (identifiant facultatif, `loc`, `lastmod`, balises à préfixe), page **Tester** plus parlante ([Mapping](../catalogue/mapping), [Formats des flux](../catalogue/formats-des-flux)) |
| Catalogues › Imports | Historique **paginé** ; page d'un import : résumé en 5 cartes, produits vendables et désactivés auto ([Imports](../catalogue/imports)) |
| Optimisations › Règles de boost | Produit affiché sous chaque SKU, **fiche complète** au clic ([Règles de boost](../recherche-et-pertinence/regles-de-boost)) |
| Paramètres › Intégration › Filtres | **Valeurs masquées** (ex. catégories « Destockage, Soldes ») ([Layers, filtres et tris](../affichage-du-widget/layers-filtres-et-tris)) |
| Widget (v39, v40) | Articles de blog sous les premiers produits sur mobile ; croix des notifications centrée |
