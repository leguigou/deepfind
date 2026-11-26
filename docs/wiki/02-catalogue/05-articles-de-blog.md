# Articles de blog

*Catalogues › Articles de blog* liste les articles importés depuis le flux du blog (voir [Formats des flux](formats-des-flux), partie Blog), pour la langue choisie.

## La liste

- **Image** (vignette ; « — » si le flux n'en donne pas), **titre**, adresse de l'article (lien), résumé et auteur s'ils sont dans le flux.
- **Catégories** et étiquettes, **date de publication**, bouton **Ouvrir** (l'article sur le site, nouvel onglet).
- Sans recherche : du plus récent au plus ancien, 50 par page.
- **Rechercher** : même moteur que la barre de recherche du site (titre, catégories, étiquettes, résumé, contenu) ; les articles sont alors dans l'ordre où le site les propose. Pratique pour vérifier qu'un mot fait bien sortir un article.
- **Catégorie** : liste des catégories du flux avec leur nombre d'articles.
- Aucun article : aucun flux « Blog » n'a encore été importé pour cette langue (*Flux & mapping*, puis import complet).

## Sur le site

Quand le texte tapé correspond à un article (titre, catégorie, étiquettes, résumé ou contenu), la fenêtre de résultats affiche **« Articles de blog »** avec l'image et le titre ; un clic ouvre l'article.

- **Ordinateur** : dans la colonne de gauche, au-dessus des filtres.
- **Mobile** (widget version 39 ou plus récente) : dans la colonne des résultats, **sous les 6 premiers produits** (ou sous « Aucun résultat »), car la colonne de gauche est alors le volet « Filtres », souvent fermé.
- **Nombre** : 4 par défaut, réglable dans [Paramètres](../exploitation/parametres) (« Nombre d'articles de blog affichés », 0 = aucun).
- Seulement sur la première page de résultats ; les clics sur les articles sont comptés dans le journal des recherches.
- Pour vérifier sans aller sur le site : *Aperçu interne*, liste « Articles ».

## Détails techniques

- Index du moteur `<préfixe du projet>posts_<langue>` (un document par article : `id`, `title`, `url`, `image`, `excerpt`, `content`, `author`, `published_ts`, `categories`, `tags`, `related_skus`).
- Code : `src/Admin/PostsController.php`, gabarit `templates/admin/posts.php` ; widget : `postsMain` et `placePostsMain()` dans `public/widget/deepfind.js`, styles `.df-posts-main` dans `deepfind.css`.
