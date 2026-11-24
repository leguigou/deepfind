# Layers, filtres et tris

**Paramètres › Intégration** règle tout ce que voient les visiteurs, sans toucher au code du site. Les changements arrivent sur le site en 5 minutes au plus.

## Layers

![Layers](docs/screenshots/06-integration-layers.png)

Un **layer** est une façon d'afficher la fenêtre de recherche. Un seul est actif à la fois ; **sans layer actif**, le widget s'affiche sous la barre de recherche avec les réglages du code du site.

| Mise en page | Description |
|---|---|
| **Sous la barre** | S'ouvre juste sous la barre de recherche du site, le reste de la page est assombri |
| **Plein écran** | Occupe toute la page, avec sa propre barre de recherche |
| **Flottant** | Panneau centré au-dessus de la page assombrie |
| **Intégré** | Les résultats prennent la place du contenu d'un bloc du site (ex. `main#maincontent`) |

![Créer un nouveau layer](docs/screenshots/07-nouveau-layer.png)

- **« Créer un nouveau layer »** : choix de la mise en page et du nom. Le premier layer créé est activé.
- **« Activer / Désactiver le layer »**, **« Configuration »**, corbeille (layer inactif seulement).
- **« Tester DeepFind sur le site »** : voir plus bas.
- **« Code à ajouter sur le site »** : le code de la section 4, avec votre clé et le bon numéro de version.

## Paramètres d'un layer

![Paramètres d'un layer](docs/screenshots/08-parametres-layer.png)

**Options du layer**
- **Mise en page** : le format actuel, et « Modifier » pour en changer (mêmes illustrations qu'à la création).
- **Caractères pour afficher les résultats** (1 à 10).
- **Conservation de la requête de recherche** : la recherche, les filtres et le tri sont gardés dans l'adresse de la page (`#df=…`), et la fenêtre se rouvre à l'identique si la page est rechargée. Laissez-le désactivé si une fonctionnalité du site utilise les ancres d'URL.
- **Sélecteur CSS du champ de recherche** (vide : celui du code du site).
- **Bloc d'affichage** (Intégré), **écart sous la barre** (Sous la barre).
- **Largeur maximale de la fenêtre** (sauf Plein écran) : en **% de l'écran** (par défaut, de 10 à 100) ou en **pixels** (320 à 3 000). Vide : largeur par défaut. Sur mobile, la fenêtre prend toute la largeur.

**Identité de marque** : logo (adresse https, affiché à gauche de la barre de recherche en Plein écran et Flottant, en haut des résultats sinon) et couleurs **primaire**, **contraste primaire** (texte sur la couleur primaire) et **texte**.

**Personnalisation avancée**
- **CSS** : ajouté à la page du site, en version **Ordinateur** et **Mobile** (900 px de large ou moins). Mobile vide : la version ordinateur s'applique partout.
- **Modèle de carte produit** (Ordinateur et Mobile) : le code HTML **réellement utilisé** est affiché ; modifiez-le puis enregistrez. « Réinitialiser » remet le modèle d'origine.
- **Nombre de produits** : produits **par ligne** (ordinateur : automatique ou 1 à 8 ; mobile : 1 à 3) et produits **chargés à la fois** (4 à 48), avec un aperçu de la grille.

![Modèle de carte produit](docs/screenshots/09-modele-carte.png)

**Modèle de carte produit** :
- HTML simple (div, span, p, strong, img…). Les scripts, événements (`onclick`…) et liens `javascript:` sont retirés à l'enregistrement, puis de nouveau par le widget. Pas de lien : la carte entière mène déjà au produit.
- Le lien de la carte, `<a class="df-card" href="…">`, est posé automatiquement autour du modèle (toute la carte est cliquable). L'éditeur l'affiche en haut du code et la valeur de `href` est **modifiable** : `{{url}}` par défaut ; `{{url}}?utm_source=deepfind&utm_medium=recherche` ajoute des paramètres à l'adresse du produit (proprement, même si elle contient déjà `?` ou `#…`) ; `{{url_path}}` = chemin seul (ex. `/c4-predator.html`) sur le domaine de la page ; variables `{{sku}}`, `{{id}}`. Il doit commencer par `{{url}}`, `{{url_path}}`, `https://` ou `/` (sinon il est refusé et le lien par défaut s'applique).
- Variables (valeurs échappées) : `{{name}}`, `{{brand}}`, `{{image}}`, `{{url}}` (adresse de la page produit, ou de la déclinaison trouvée), `{{price}}`, `{{old_price}}`, `{{discount}}`, `{{from}}`, `{{sku}}`, `{{variant}}`, `{{stock}}`, `{{out_of_stock}}`, `{{new}}`, `{{rating}}`, `{{price_class}}`, `{{media_class}}`.
- Un élément de classe **`df-if-`*variable*** (ex. `df-if-discount`) n'est affiché que si la variable n'est pas vide. Une image de classe `df-img` change au survol si le produit a une seconde image (désactivable : Paramètres › Widget sur le site).
- Un modèle identique à l'origine n'est pas enregistré : le layer suit alors le modèle d'origine.

## Filtres

![Filtres](docs/screenshots/10-filtres.png)

- **« + Ajouter un filtre »** : champ (principaux : en stock, en promotion, marques, catégories, prix ; puis les attributs marqués « filtrables » dans le mapping), nom affiché, valeurs affichées, ordre des valeurs, déplié par défaut, statut. La corbeille retire un filtre.
- **Ordre** des filtres par glisser-déposer.
- **Valeurs affichées** : nombre de valeurs visibles dans un filtre de type liste, les suivantes derrière « + N ».
- **Ordre des valeurs** : **par nombre** de produits (du plus grand au plus petit) ou **A à Z**. Les valeurs proposées sont toujours celles qui ont le plus de produits (plus celles déjà choisies par le visiteur).
- **Déplié** : bloc ouvert à l'affichage des résultats ; le visiteur peut toujours le replier ou le déplier.
- **Prix** : deux champs (min / max) et un curseur à deux poignées, avec « Réinitialiser » et « Valider ».
- Tant que la liste n'a jamais été enregistrée, tous les filtres disponibles sont proposés. Ensuite, seuls les filtres ajoutés le sont : un nouvel attribut importé apparaît dans « Ajouter un filtre ».

## Options de tri

![Options de tri](docs/screenshots/11-options-de-tri.png)

- « Pertinence » est toujours proposé en premier (nom modifiable).
- Pour chaque tri : le **champ** (principaux : prix, nom, date d'ajout, remise, marque, ventes 30 jours, note des avis ; puis les autres), l'**ordre** selon son type (A à Z / Z à A, du plus bas au plus élevé, du plus récent au plus ancien) et le **nom affiché** (vide : nom par défaut grisé).
- Ordre des tris par glisser-déposer. La marge et le score interne ne sont jamais proposés.
- Un nouveau champ de tri peut demander une mise à jour du moteur, lancée à l'enregistrement (quelques minutes sur un gros catalogue).
