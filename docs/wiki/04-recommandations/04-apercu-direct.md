# Aperçu direct (ordinateur / mobile)

Dans la fiche d'une popin ou d'un carrousel, **« Aperçu ordinateur / mobile »** ouvre une fenêtre où le **vrai widget** dessine le contenu sur une page fictive, avec les réglages en cours (même non enregistrés) : bascule **Ordinateur** (1280 × 800) / **Mobile** (390 × 844), affichage réduit pour tenir à l'écran, bouton « Rejouer » après une modification. La popin s'ouvre sans délai ; le carrousel utilise les produits de la page indiquée dans « Liste des produits sélectionnés » ; modèle de carte et couleurs du layer actif. Rien n'est compté ni mémorisé. Seule cette page d'aperçu peut s'afficher dans un cadre, et uniquement à l'intérieur de l'extranet.

## Détails techniques

- La fenêtre d'aperçu charge `/admin/live-preview` dans un cadre : page fictive qui charge le **vrai widget** en mode `data-render="1"` (aucun appel à l'API, aucune mesure, rien de mémorisé).
- La fiche envoie l'élément à dessiner au cadre par `postMessage` (même origine uniquement) ; le widget le dessine avec `DeepFind.render({ config, type, item })`, avec le modèle de carte, les couleurs, les textes et variables de la recherche.
- `/admin/live-preview` est la **seule** page de l'extranet autorisée en cadre (`X-Frame-Options: SAMEORIGIN`, `frame-ancestors 'self'`) ; toutes les autres refusent d'être affichées dans un cadre.
- Les données viennent de `/admin/popins/preview` et `/admin/carousels/preview` (réglages en cours, nettoyés comme à l'enregistrement).
