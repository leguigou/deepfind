# Utiliser l'extranet

L'extranet s'utilise depuis `/admin`, avec le jeton `ADMIN_TOKEN`. La connexion est mémorisée pendant 10 jours. Le bouton « Se déconnecter » révoque le cookie de ce navigateur. Il n'y a pas de comptes utilisateurs. Le menu de gauche se replie, et le bouton en bas du menu bascule entre les thèmes sombre et clair ; ces deux choix sont mémorisés.

## Les menus

| Rubrique | Page | À quoi ça sert |
| --- | --- | --- |
|  | Tableau de bord | État du moteur, recherches et taux sans résultat sur 24 h, nombre de produits (et de produits désactivés auto), derniers imports, alertes de configuration |
| **Analyse** | [Temps réel & charge](../analyse/temps-reel-et-charge) | Supervision en direct : visiteurs actifs, recherches par minute, temps de réponse (moyenne, 95e percentile), pages vues, erreurs, charge du processeur et mémoire ; courbes et carte heure par heure sur 5 jours |
|  | [Parcours](../analyse/parcours) | Chronologie datée de la journée des visiteurs qui ont cherché ou cliqué sur un carrousel : pages, recherches, clics, panier, commande. Sans cookie, conservée 24 h, désactivée par défaut |
|  | [Audience](../analyse/audience) | Statistiques quotidiennes **anonymes** : visiteurs, part qui cherche, recherches par visiteur, clics, mobile / tablette / ordinateur, évolution jour par jour, [parcours d'achat](../analyse/parcours-d-achat), export CSV |
|  | [Statistiques](../analyse/statistiques-et-journal) | Top requêtes, **recherches sans résultat exact** (aucun résultat ou seulement des approchants) avec ajout direct d'un **synonyme**, d'une redirection ou d'un résultat personnalisé, **produits les plus cliqués** (clics, requêtes d'origine, position moyenne), requêtes avec résultats mais sans clic, export CSV |
|  | [Journal des recherches](../analyse/statistiques-et-journal) | Détail filtrable et exportable |
| **Catalogues** | [Flux & mapping](../catalogue/mapping) | Sources (URL complet/delta, authentification), **mapping visuel**, mapping JSON avancé, **test du mapping sans indexer**, **planification des imports**, imports manuels |
|  | [Imports](../catalogue/imports) | Historique paginé (50 par page, du plus récent au plus ancien ; durée, **écart avec l'import précédent**), rapports (motifs de rejet, exemples de SKU rejetés), suivi en direct |
|  | [Produits désactivés](../catalogue/produits-desactives) | Produits retirés des recherches avec le bouton « Désactiver » de l'aperçu interne, avec filtre, « Activer » et « Tout réactiver » |
|  | [Articles de blog](../catalogue/articles-de-blog) | Articles importés depuis le flux du blog : image, titre, catégories, date, lien ; recherche comme sur le site, filtre par catégorie |
|  | [Aperçu interne](../recherche-et-pertinence/pertinence-et-classement) | Résultats avec **marge, score métier, ventes, règles appliquées**, boost rapide, épinglage, désactivation |
|  | [Robots & blocages](../exploitation/anti-robots) | IP les plus actives, requêtes répétées, alertes, blocage et déblocage |
| **Optimisations** | [Pertinence & classement](../recherche-et-pertinence/pertinence-et-classement) | Correspondance des mots, importance des champs, score métier |
|  | [Règles de boost](../recherche-et-pertinence/regles-de-boost) | Coefficients appliqués aux produits qui remplissent des conditions |
|  | [Synonymes & alias](../recherche-et-pertinence/synonymes) | Une ligne = des mots qui donnent les mêmes résultats, séparés par des virgules (ex. `ecoflow, ecoflau, ecauflo`) ; option « sens unique » (le 1er mot mène aux suivants) ; recherche et modification dans le tableau ; import CSV (`samples/synonymes.csv`) ; l'Aperçu interne indique les synonymes appliqués à une recherche |
|  | [Redirections](../recherche-et-pertinence/redirections) | Envoyer certaines recherches vers une page |
| **Recommandations** | [Résultats personnalisés](../recherche-et-pertinence/resultats-personnalises) | Produits affichés en premier, seuls ou exclus pour certaines recherches |
|  | [Règles](../recherche-et-pertinence/regles-selon-la-page) | Résultats selon la **page du site** (adresse), sans mot-clé : uniquement certains produits, filtre pré-coché retirable, ou exclusion |
|  | [Bannières](../recommandations/bannieres) | Image ou code HTML en haut des résultats |
|  | [Popins](../recommandations/popins) | Fenêtre affichée sur les pages du site choisies (accueil, adresses), avec période, délai, fréquence et statistiques ; ciblage selon la provenance du visiteur |
|  | [Carrousels](../recommandations/carrousels) | Bandeaux de produits qui défilent : produits manuels, automatiques, similaires, personnalisés, tendances, achetés ensemble, aussi regardés, page panier (livraison offerte) ; ciblage selon la provenance ; apprentissage par les clics |
|  | [Campagnes](../recommandations/campagnes) | Produits à pousser sur une période (choisis un par un ou par règles) : clics, affichages, paniers, commandes et chiffre d'affaires par produit et par emplacement, comparés à la période précédente |
|  | [Envoi de notifications](../recommandations/notifications) | Messages envoyés aux abonnés (téléphone, ordinateur) : rédaction, destinataires, programmation, affichages et clics, liste des abonnés, groupe de test |
|  | [Démo du widget](../affichage-du-widget/tester-sur-le-site) | Le widget sur une page de démonstration, avec le **layer de votre choix** |
| **Paramètres** | [Projets](../exploitation/projets) | Production, Test… |
|  | [Clés](integrer-le-widget) | Clés publiques et domaines autorisés |
|  | [Intégration](../affichage-du-widget/layers-filtres-et-tris) | Layers, filtres, options de tri, code du site, favori de test |
|  | [Variables](../affichage-du-widget/variables-et-textes) | Variables système (lecture seule), textes du widget modifiables par langue, variables personnalisées `{{code}}` |
|  | [Notifications](../recommandations/notifications) | Invitation à recevoir les notifications : forme (bandeau, popin, cloche, bloc), appareils (téléphones seulement…), exclusion des iPhone, contenu, style, installation du sw.js |
|  | [Cache](../exploitation/cache) | État et purge des caches |
|  | [Paramètres](../exploitation/parametres) | Seuils anti-robots, recherche, widget sur le site, journaux, sécurité des imports ; seuil de livraison offerte |
| **Aide** | Aide | Documentation complète (wiki), s'ouvre dans un nouvel onglet : rubriques à gauche ; à droite, toutes les pages de la rubrique à la suite, la page lue étant surlignée à gauche pendant le défilement ; recherche. Adresse `/admin/wiki` (raccourci `/wiki`), connexion à l'extranet requise. Contenu : fichiers Markdown de `docs/wiki/` (voir « Contribuer à l'aide » sur sa page d'accueil) |

> 💡 Le projet affiché en haut du menu (Production, Test…) s'applique à toutes les pages : réglages, règles et statistiques sont propres à chaque projet. Voir [Projets](../exploitation/projets).
