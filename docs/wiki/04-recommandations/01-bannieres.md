# Bannières

Image ordinateur + image mobile + lien (téléversement ou adresse), ou contenu HTML (éditeur visuel ou code ; scripts, événements et liens `javascript:` retirés), en haut des résultats pour des termes de recherche ou **par défaut**, en continu ou sur une période. Suivi des **affichages**, **clics** et **CTR**. Les images sont stockées dans `public/uploads/banners/` (à sauvegarder, et accessible en écriture par PHP).

## Contenu HTML : éditeur visuel

Pour une bannière ou une popin, le mode **Contenu HTML** remplace l'image par un contenu rédigé comme dans un traitement de texte (onglet **Éditeur visuel**) :
- paragraphe, **titre**, sous-titre ; gras, italique, souligné ; **couleur du texte** ; alignement à gauche, au centre, à droite ; listes à puces ou numérotées ;
- **lien** (sur le texte sélectionné, ou nouveau lien ; option « nouvel onglet » ; adresse vide = retirer le lien) ;
- **bouton** : lien mis en avant, centré, de la couleur choisie pour le texte ;
- **image** : adresse ou téléversement (mêmes formats et poids maximal que les images des bannières) ;
- « Effacer » retire la mise en forme ; annuler / rétablir.

L'onglet **Code HTML** montre et modifie le même contenu (pour les cas que l'éditeur ne couvre pas). Un texte collé depuis Word ou une page web garde sa structure (titres, listes, gras, liens) mais pas sa mise en forme d'origine. L'aperçu de l'éditeur a la largeur maximale de la popin ; le rendu final dépend aussi des styles du site. Le contenu est nettoyé à l'enregistrement, puis par le widget : seules les balises et attributs autorisés sont gardés. Nécessite le widget `?v=26` pour la mise en page des popins au contenu HTML.
