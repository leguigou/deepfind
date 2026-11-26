# Imports

## Imports complet et delta

![Historique des imports](docs/screenshots/14-imports.png)

**Depuis l'extranet :** **Catalogues › Flux & mapping** › bouton **« Import complet »** (ou **« Delta »**) sur la ligne du flux.
- L'import est mis en file d'attente puis traité **en arrière-plan** par le worker (Supervisor), ou par la tâche cron `schedule:run --work` sans Supervisor. Vous arrivez directement sur sa **page de suivi**, qui se met à jour toute seule toutes les 2 secondes. Vous pouvez la quitter : l'import continue.
- **Étapes affichées** : téléchargement (Mo reçus / Mo total), comptage des éléments du flux, lecture, regroupement parents/enfants (flux à plat), indexation dans Meilisearch, bascule. Avec le pourcentage global, le temps écoulé et le **temps restant estimé**.
- **Résumé sous la progression** : **statut** et date ; **source** et type (import complet, mise à jour delta, scores) ; **produits du flux** (lus, indexés, rejetés) ; **durée** et mémoire ; **index en ligne** : documents au total, dont **vendables** (comparés à l'import précédent) et **désactivés auto** (produits absents du flux, gardés 90 jours sans être affichés, voir [Produits désactivés](produits-desactives)). C'est pourquoi l'index peut compter plus de documents que de produits indexés. Puis les attributs filtrables détectés et, s'il y en a, les motifs de rejet.
- **Historique** (*Catalogues › Imports*, 50 imports par page, du plus récent au plus ancien, avec « Plus récents », « Précédents », « Suivants », « Plus anciens ») : début, fin, **durée**, éléments lus, indexés, rejetés, et **écart** avec l'import complet précédent : en vert les produits nouveaux ou revenus dans le flux, en rouge les produits désactivés auto (détail au survol).
- **Résultats immédiats** : dès la bascule, la recherche renvoie les nouveaux résultats. Tous les caches sont invalidés automatiquement.

**En ligne de commande** (affiche la progression par tranche de 10 %) :

```bash
php bin/console import:run --source=produits_fr
```

**Flux volumineux :** le fichier est téléchargé sur disque, puis lu au fil de l'eau. La mémoire reste constante quelle que soit sa taille.
- Mesure en test (environ 15 000 produits avec variantes) : **20 Mo de mémoire** pour un flux de **40 Mo**, comptage préalable en moins d'une seconde.
- La durée totale dépend surtout de l'indexation par Meilisearch, à mesurer sur votre serveur. L'import complet relit aussi l'index en ligne pour calculer l'écart.

## Planification

Dans la fiche de chaque flux (**Flux & mapping › nom du flux**), section « Planification des imports » :
- choisissez un préréglage (« Chaque nuit à 3 h 15 », « Toutes les 15 min de 6 h à 23 h »…) ou saisissez une expression cron standard (`minute heure jour mois jour-semaine`, ex. `30 2 * * 1-6`) ;
- les **3 prochaines exécutions** s'affichent après l'enregistrement, et la liste des flux montre la prochaine ;
- les horaires sont ceux du serveur (`APP_TIMEZONE`, Europe/Paris par défaut) ;
- un import n'est lancé que si la source est **active** et si l'import précédent de cette source est terminé (sinon le créneau est sauté) ;
- l'import delta planifié n'a lieu que si une URL de flux delta est renseignée ;
- le tableau de bord signale un planificateur arrêté (aucun passage depuis 5 minutes alors que des imports sont planifiés).

## Produits absents du flux : désactivation automatique

À chaque **import complet**, DeepFind compare le flux avec l'index en ligne, par SKU :
- un produit **absent du flux** (ou rejeté, par exemple `enabled = 0` ou prix nul) devient **non vendable** : il est gardé **90 jours** dans l'index, sans jamais être proposé aux visiteurs, puis retiré ;
- s'il **revient dans le flux complet**, il est **réactivé automatiquement** ;
- une **désactivation manuelle** reste indépendante du flux.

Le contrôle de sécurité de l'import (« Import bloqué si le catalogue baisse de plus de N % », 20 % par défaut) porte sur les produits vendables. Le tableau de bord compte les produits désactivés auto à part.

> Après une mise à jour qui apporte cette fonction, lancez un **import complet** : le filtre « vendable » ne s'active qu'une fois l'index reconstruit.

## Premier import

1. Connectez-vous à `https://search.example.com/admin` (votre `APP_URL` suivi de `/admin`) avec le jeton `ADMIN_TOKEN`.
2. **Catalogues › Flux & mapping** : remplacez l'URL d'exemple de `produits_fr` par l'URL de votre flux Magento, puis faites de même pour `blog_fr`. Si le flux est protégé par mot de passe (.htaccess), renseignez l'utilisateur et le mot de passe dans **Accès au flux**, puis cliquez sur **Tester la connexion**.
3. **Mapping** (mapping visuel) : DeepFind analyse le flux. Pour chaque champ (obligatoires en premier), choisissez à droite le champ du flux correspondant, puis enregistrez. Voir la section 5.
4. **Tester** : vérifiez que le mapping produit le bon document, sans rien indexer.
5. **Planification des imports** (dans la fiche du flux) : choisissez par exemple « Chaque nuit à 3 h 15 » pour l'import complet et « Toutes les 15 min de 6 h à 23 h » pour le delta.
6. **Import complet** pour un premier chargement immédiat, puis suivez l'avancement dans **Catalogues › Imports**.
7. **Paramètres › Clés** : créez une clé pour le domaine de votre site (ex. `example.com`), puis testez avec **Recommandations › Démo du widget**.
8. **Paramètres › Intégration** : copiez le code à ajouter sur le site ([Intégrer le widget](../demarrage/integrer-le-widget)) et réglez l'affichage ([Layers, filtres et tris](../affichage-du-widget/layers-filtres-et-tris)).

## Sécurité de l'import complet

Un import complet construit un index temporaire (`<index>_tmp`), vérifie que le catalogue n'a pas baissé de plus du seuil réglé (*Paramètres*, « Import bloqué si le catalogue baisse de plus de N % »), puis bascule d'un coup. Un flux vide, tronqué ou illisible lève une erreur **avant** la bascule : la recherche du site n'est jamais vidée.
