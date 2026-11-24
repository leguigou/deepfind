# Créer un carrousel

**Recommandations › Carrousels** insère un bandeau de produits qui défile dans une page du site (accueil, catégorie, fiche produit…). Le widget, déjà présent sur toutes les pages, s'en charge.

Un carrousel comprend :
1. **Statut**, **durée** (en continu ou période) et **pages concernées** : comme les popins (toutes, accueil et/ou adresses, pages exclues).
2. **Emplacement** : le **sélecteur CSS** du bloc de la page — identifiant (`#accueil-selection`) ou classe (`.category-products`) — et la position : dans le bloc (au début, à la fin), juste avant, juste après, ou à la place de son contenu ; appareils (tous, ordinateur, mobile). Le plus fiable : faire ajouter dans le thème un bloc vide dédié, ex. `<div id="deepfind-carrousel"></div>`. Si le bloc n'existe pas sur une page, rien n'y est affiché (le widget l'attend 10 secondes).
3. **Titre** (avec, au besoin, des **classes CSS** du thème, ex. `text-2xl font-bold` : elles remplacent alors le style du titre DeepFind) et lien **« Voir tout »** (facultatifs).
4. **Produits** :
   - **Produits épinglés en tête** : recherchés par nom, SKU ou marque, rangés par glisser-déposer ;
   - **Source** : *Sélection manuelle* (uniquement les produits épinglés), *Automatique* (conditions comme les règles selon la page : marque, catégorie, type, prix, promotion, nouveauté… + tri), *Produits similaires* (sur une fiche produit : même catégorie la plus précise et/ou même marque que le produit de la page), *Personnalisé* (« Vus récemment » ou « Vous aimerez aussi », d'après l'historique du visiteur) ;
   - **Tri** : score métier (ventes, clics, stock, marge, nouveauté), meilleures ventes 30 j, plus cliqués dans la recherche 30 j, nouveautés, plus grosses remises, mieux notés, prix ;
   - **Apprentissage** (facultatif) : les produits sont reclassés selon leur **taux de clic dans ce carrousel** (30 jours, taux lissé : un produit encore peu montré garde le taux moyen et sa chance, un produit souvent montré et peu cliqué descend) ;
   - nombre de produits (2 à 48), en stock uniquement.
   Les conditions limitent aussi les sources « similaires » et « personnalisé ». Sans produit dans la page ou sans historique, ces sources affichent la sélection automatique.
5. **Affichage** : produits visibles à la fois (ordinateur 1 à 8, mobile 1 à 4, le bord du suivant dépasse), défilement automatique (en pause au survol, au toucher, hors écran ; jamais si le visiteur limite les animations), **modèle de carte produit** propre au carrousel (onglets ordinateur / mobile, mêmes variables `{{…}}` et règles que le modèle de la recherche ; bouton « Revenir au modèle par défaut » = celui de la fenêtre de recherche) et **lien de la carte** propre au carrousel (ex. `{{url}}?utm_campaign=carrousel-accueil` ; vide = celui de la recherche), **CSS personnalisé** (`.df-carousel-N` pour ce carrousel, `.df-carousel` pour tous ; variables `--df-accent`, `--df-text`, `--df-gap`).

Les cartes produit sont celles de la fenêtre de recherche (modèle de carte et couleurs du layer actif). Les visiteurs font défiler au doigt, à la souris (glisser) ou avec les flèches (chevrons fins, même tracé que les sliders du thème ; couleur réglable avec la variable `--df-car-arrow`, taille avec `.df-car-chevron`). La **liste des produits sélectionnés (contrôle)** de la fiche montre quels produits sortent et dans quel ordre (position, SKU) pour une page, un SKU de produit et un historique de test, sans enregistrer ; ce n'est pas le rendu du site, visible avec « Voir le rendu réel (ordinateur / mobile) ».

**Statistiques** (liste et fiche, 7 / 30 / 90 jours) : **vues** (carrousel visible au moins à moitié à l'écran), **clics** sur un produit, **CTR** ; en bas de la fiche (sous la liste de contrôle, bloc dépliant), les produits les plus vus avec leurs clics et CTR. Totaux par jour, sans donnée personnelle ; l'aperçu (favori « Tester DeepFind ») et la page Démo ne comptent pas. Les statistiques par produit sont purgées avec les journaux (`logs:purge`, au moins 30 jours).

**Produit de la page (similaires)** : reconnu par l'adresse de la page (champ `url_path`, indexé depuis l'URL du flux). Le site peut l'indiquer directement : `<meta name="deepfind:sku" content="SKU-DU-PRODUIT">` (ou un attribut `data-df-sku`). **Après la mise à jour, lancez un import complet** pour indexer l'adresse des produits et le tri par clics.

**Personnalisé et RGPD** : l'historique (20 derniers produits consultés ou cliqués, 30 jours) est gardé **dans le navigateur du visiteur** (`localStorage`), sans identifiant ; il est envoyé avec la demande de la page uniquement pour choisir les produits et n'est jamais enregistré par DeepFind. Réglage du projet, en bas de la liste des carrousels :
- **Après consentement** (par défaut) : rien n'est gardé tant que le site n'a pas appelé `DeepFind.consent(true)` (bandeau cookies, finalité « personnalisation ») ; `DeepFind.consent(false)` efface l'historique. Avant le chargement du widget : `window.deepfindConsent = true` ;
- **Toujours** : seulement si votre analyse RGPD le permet ;
- **Jamais** : les carrousels personnalisés affichent leur sélection automatique.
À faire valider par votre DPO.

Délai de prise en compte : quelques minutes (configuration du widget gardée en cache par les navigateurs, produits mis en cache 5 minutes). Nécessite le widget `?v=28`.

**Ordre de classement :** le nombre de mots trouvés passe en premier ; les résultats personnalisés passent avant le reste. Vérifiez l'effet de vos réglages dans l'**aperçu interne**.

## Choisir l'emplacement (sélecteur CSS)

Le carrousel s'insère par rapport à un bloc de la page désigné par un **sélecteur CSS** :

| Sélecteur | Désigne |
|---|---|
| `#deepfind-carrousel` | l'élément d'identifiant `deepfind-carrousel` (le plus fiable : bloc vide ajouté dans le thème) |
| `.category-products` | le premier élément de classe `category-products` |
| `main .columns` | un élément `.columns` à l'intérieur de `main` |
| `[x-data^="productSliderWithTabs"]` | un élément dont l'attribut `x-data` **commence par** ce texte (composant Alpine.js d'un thème Hyvä) |

Avec un composant géré par le thème (Alpine.js, slider existant), préférez la position **juste avant** ou **juste après** le bloc : « à la place du contenu » effacerait le composant du thème, et « dans le bloc » peut être effacé si le thème redessine son contenu.

## Titre, flèches et style

- **Classe(s) CSS du titre** : classes du thème (syntaxe Tailwind acceptée, ex. `text-2xl font-bold md:text-3xl`) ; elles remplacent alors le style du titre DeepFind.
- **Flèches** : chevrons fins ; couleur `--df-car-arrow`, taille `.df-car-chevron` dans le CSS personnalisé.
- **Modèle de carte et lien de la carte** propres au carrousel : voir [Modèle de carte produit](../../affichage-du-widget/modele-de-carte).
