# Popins

**Recommandations › Popins** ouvre une fenêtre (« popin ») sur les **pages du site**, même sans recherche : opération commerciale, nouveauté, information de livraison… Le widget, déjà présent sur toutes les pages, l'affiche : rien à ajouter sur le site.

Une popin comprend :
- **Statut** et **Durée** : en continu ou sur une période (dates de début et de fin incluses).
- **Pages concernées** : **toutes les pages**, ou seulement **la page d'accueil** (« / » ou la racine d'une langue, ex. `/fr`) et/ou des **adresses**, avec les mêmes comparaisons que les règles selon la page (*contient*, *commence par*, *finit par*, *est exactement*). Ex. contient `/nautisme/` pour une catégorie et ses produits.
  **Pages exclues** (facultatif) : jamais de popin sur ces pages, même si elles correspondent. Conseillé : contient `/checkout` (panier, paiement), contient `/customer/` (compte client).
- **Affichage** : appareils (tous, ordinateur ou mobile, d'après la largeur de l'écran : mobile jusqu'à 900 px), position (au centre avec fond assombri, en bas à droite ou à gauche), **délai avant l'affichage** en secondes à partir de l'ouverture de la page (0 à 600), largeur maximale, **hauteur minimale** (0 = selon le contenu ; le contenu est alors centré verticalement ; jamais plus haute que l'écran). La croix de fermeture est placée au-dessus de la popin, à l'extérieur, à droite. Si la fenêtre de recherche est ouverte au bout du délai, la popin attend qu'elle se referme.
- **Fréquence** : **une seule fois par visite** (même si le visiteur ne la ferme pas), **à chaque page concernée** tant qu'il ne l'a pas fermée, ou **toujours** : à chaque page concernée, même s'il l'a fermée (rien n'est mémorisé ; à réserver aux messages importants, car elle revient à chaque page). Une fois **fermée ou cliquée**, elle ne lui est plus proposée pendant **N jours** (0 = jusqu'à la fin de sa visite). Après un changement de contenu, cochez « reproposer aux visiteurs qui l'ont déjà fermée ».
- **Contenu** : comme les bannières, **image** ordinateur + image mobile (facultative) + texte alternatif + lien, ou **contenu HTML** rédigé dans l'**éditeur visuel** ou en code (voir « Contenu HTML : éditeur visuel » ci-dessous). Images stockées dans `public/uploads/banners/`.

**Fonctionnement**
- **Une seule popin par page** : la première de la liste (ordre par glisser-déposer) qui correspond à la page, à l'appareil et que le visiteur n'a pas déjà fermée (ou déjà vue pendant sa visite).
- **Statistiques** (7, 30 ou 90 jours, dans la liste et la fiche) : **affichages**, **clics** sur un lien de la popin, **CTR** (clics / affichages) et **fermetures** (croix, touche Échap, clic sur le fond). Ce sont des totaux par jour, sans donnée personnelle, comptés même si le journal des recherches est désactivé. Les robots et IP bloquées ne sont pas comptés.
- **Délai de prise en compte** : la liste des popins arrive avec la configuration du widget, gardée quelques minutes par les navigateurs. Une création, une modification ou une désactivation est visible sur le site d'ici **6 minutes** au plus.
- **Mémorisation** : dans le navigateur du visiteur (`localStorage` pour les N jours, `sessionStorage` pour la visite), sans cookie ni identifiant, et rien n'est envoyé au serveur. Un autre navigateur, une navigation privée ou un effacement des données du site la font réapparaître.
- **Tester** : le favori « Tester DeepFind » (Paramètres › Intégration) affiche les popins à chaque page, sans les compter ni mémoriser leur fermeture. La page Démo de l'extranet n'affiche pas de popin.
- Projet désactivé : aucune popin. Nécessite le widget `?v=25` ou plus récent (`?v=27` pour la hauteur minimale et la croix à l'extérieur).

## Détails techniques

- **Données** : table `popins` (réglages), `popin_stats` (affichages, clics, fermetures par jour, sans donnée personnelle). Code : `src/Rules/Popins.php`, fiche `templates/admin/popin_edit.php` et `public/assets/popins.js`.
- **Diffusion** : les popins en cours arrivent avec `/api/v1/config` ; le choix de la page (adresses, accueil, appareil) se fait **dans le widget**, sans requête supplémentaire par page vue. Configuration gardée 5 minutes dans la session du visiteur et 1 minute par le navigateur : une modification apparaît en 6 minutes au plus.
- **Mémorisation** (navigateur du visiteur, sans cookie) : `localStorage` clé `df-popin:<clé>:<langue>:<id>:<version>` (fermée pour N jours), `sessionStorage` pour la visite. « Reproposer aux visiteurs » change la version. Fréquence **toujours** : rien n'est mémorisé.
- **Mesures** : `POST /api/v1/popin` avec `e=v` (affichée), `c` (clic), `x` (fermée) ; jamais en aperçu ni sur la page Démo.
- **Accueil** reconnu : `/`, `/fr`, `/fr_fr` (racine d'une langue ou d'une boutique). Un code de boutique plus long (ex. `/default/`) n'est pas reconnu : utilisez alors une adresse « est exactement ».
