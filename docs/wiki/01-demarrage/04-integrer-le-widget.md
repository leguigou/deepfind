# Intégrer le widget sur le site

Le code exact (avec votre clé et le bon numéro de version) est donné dans **Paramètres › Intégration › Layers › Code à ajouter sur le site**. Sur Magento, ajoutez-le avant `</body>`, par exemple dans *Contenu › Design › Configuration › HTML Head › Scripts et feuilles de style* ou dans un bloc du thème :

```html
<script src="https://search.example.com/widget/deepfind.js?v=34" defer
  data-key="pk_VOTRE_CLE_PUBLIQUE"
  data-lang="fr"
  data-input="#search"
  data-results-url="https://www.example.com/fr/catalogsearch/result/?q="></script>
```

**Fonctionnement**
- **Activation** : au chargement, le widget demande à l'API (`/api/v1/config`) si le projet de la clé est actif, et récupère l'affichage choisi dans l'extranet (layer actif, filtres, tris). Il ne prend la main que si le projet est actif. **Projet désactivé** ou **API injoignable** : rien n'est modifié, la recherche d'origine du site fonctionne normalement. La réponse est gardée 1 min par le navigateur et 5 min dans la session du visiteur : un changement dans l'extranet arrive sur le site en 5 minutes au plus.
- **Affichage** : selon le layer actif ([Layers, filtres et tris](../affichage-du-widget/layers-filtres-et-tris)) : sous la barre de recherche (par défaut), plein écran, flottant ou intégré dans un bloc de la page.
- **Touche Entrée** : ouvre la page de résultats du site, ou, si ce réglage est désactivé (Paramètres › Paramètres › Widget sur le site), laisse seulement la fenêtre DeepFind. `data-enter="off"` / `"on"` force le comportement sur une page.
- **Autocomplétion d'origine du site** (panneau Magento « Suggestions de recherche », Amasty, Mirasvit, Smile ElasticSuite…) : bloquée par défaut, pour que deux fenêtres ne s'ouvrent pas en même temps. Le réglage « Bloquer l'autocomplétion d'origine » (Paramètres › Paramètres › Widget sur le site) la réactive. Sur le script, `data-native="keep"` (laisser) ou `data-native="block"` (bloquer) est prioritaire sur ce réglage. Si un autre panneau apparaît encore, ajoutez son sélecteur : `data-hide=".mon-panneau"`.
- **Fonctions** : attente de 180 ms après la frappe, annulation des requêtes dépassées, cache local, chargement au défilement, filtres (marques, catégories, attributs, prix avec curseur, en stock, en promotion), blocs de filtres repliables, tris, articles de blog, redirections, bannières, touche Échap, version mobile avec bouton « Filtres ».
- **Clics** : chaque ouverture d'un produit, d'un article ou d'une bannière depuis la fenêtre (clic, Ctrl/Cmd + clic, clic molette) est mesurée (`sendBeacon`), sans cookie, avec un identifiant aléatoire créé à chaque chargement de page. Les clics des 30 derniers jours entrent dans le score métier.
- **Mesure d'audience** : sur les pages choisies dans **Analyse › Audience**, le widget envoie un signal « page vue » anonyme (`/api/v1/visit`), sans cookie ni donnée personnelle ([Audience](../analyse/audience)).
- **Multilingue** : `data-lang="en"` et une clé autorisée pour `en`. Chaque langue a son propre index et sa propre source de flux.

**Attributs du script**

| Attribut | Rôle |
|---|---|
| `data-key` | Clé publique (obligatoire) |
| `data-lang` | Langue des résultats (`fr` par défaut) |
| `data-input` | Sélecteur CSS du ou des champs de recherche du site (si le layer actif n'en indique pas) |
| `data-results-url` | Page « Tout voir » (la recherche est ajoutée à la fin). Vide = bouton masqué |
| `data-gap` | Layer « Sous la barre » : écart en pixels entre la barre de recherche et la fenêtre (12 par défaut) |
| `data-position="anchor"` + `data-anchor` | Layer « Sous la barre » : ouvrir plutôt la fenêtre sous un élément du site (ex. `header.page-header`) |
| `data-enter` | `off` / `on` : comportement de la touche Entrée (sinon : réglage de l'extranet) |
| `data-native` | `keep` / `block` : autocomplétion d'origine du site (sinon : réglage de l'extranet) |
| `data-hide` | Sélecteurs CSS supplémentaires de panneaux d'origine à masquer |
| `data-currency`, `data-locale` | Format des prix (`EUR`, `fr-FR` par défaut) |
| `data-min-chars`, `data-page-size` | Caractères avant recherche, produits chargés à la fois (sinon : réglages du layer) |

> Après une mise à jour du widget, changez le numéro `?v=` (ex. `?v=33` en `?v=34`) pour forcer le rechargement par les navigateurs. Le numéro à jour est toujours celui du code donné dans **Intégration**.

## Clés et domaines autorisés

![Clés](docs/screenshots/18-cles.png)

- **« + Ajouter une clé »** ouvre une fenêtre : nom, domaines autorisés (séparés par des virgules, sous-domaines inclus), langues.
- Une ligne par clé : nom, clé publique **masquée** (début et fin visibles), domaines, langues, active. L'**œil** affiche la clé complète, le bouton **copier** la met dans le presse-papiers.
- La clé publique est visible dans le code des pages du site : c'est normal. Elle est protégée par la liste des domaines autorisés et par les seuils anti-robots.

Chaque appel du widget indique de quel site il vient (en-tête `Origin`, à défaut `Referer`). Si le domaine n'est pas autorisé pour la clé, ou si la provenance est inconnue (adresse de l'API ouverte directement dans un onglet), l'API répond `{"error":"origin_not_allowed"}`. Un domaine autorisé couvre ses sous-domaines (`cabesto.com` couvre `mcstaging2.cabesto.com`). Un champ vide accepte tous les domaines : déconseillé en production (n'importe qui peut utiliser la clé, statistiques faussées) ; préférez une clé de test distincte.

## Points d'attention

- **Attribut `crossorigin` ou `integrity`** sur la balise du script (ajouté par certains thèmes ou modules Magento) : le navigateur exige alors l'en-tête `Access-Control-Allow-Origin` sur `/widget/` (fourni par `deploy/apache-vhost.conf`). Le plus simple : retirer ces attributs, le widget n'en a pas besoin.
- **Politique de sécurité du site (CSP)** : autoriser le domaine de DeepFind en `script-src`, `style-src`, `connect-src` et `img-src` (images téléversées des bannières et popins).
- **Réseau interne** : si le serveur DeepFind a une adresse IP privée (accessible seulement depuis le bureau ou le VPN), les versions récentes de Chrome bloquent son chargement depuis une page publique (« accès au réseau local » ; DevTools affiche « Erreur CORS » et des en-têtes provisoires). En production, DeepFind doit avoir une adresse publique.
- **Cache de pages Magento / Varnish** : après avoir changé le numéro `?v=` dans le thème, videz-le pour que les pages servies contiennent la nouvelle balise.
