# Variables et textes

**Paramètres › Variables**, pour la langue choisie en bas du menu :

- **Variables système** (lecture seule) : remplies par le widget pour chaque produit, dans les modèles de carte : `{{name}}`, `{{brand}}`, `{{price}}`, `{{from}}`… Une variable peut être remplacée par un texte fixe ; gardez alors la classe `df-if-<variable>` pour qu'il ne s'affiche que si la variable a une valeur (ex. `<span class="df-if-from">À partir de :</span>`). Le lien de la carte accepte `{{url}}`, `{{url_path}}`, `{{sku}}`, `{{id}}`.
- **Textes du widget** : tous les libellés affichés sur le site, rangés par thème (cartes produit, fenêtre de recherche, filtres et tri, carrousels et popins) : « dès » (`{{from}}`), « Nouveau », « Rupture », « Tout voir », « Aucun résultat pour », « Filtres »… Champ vide = texte d'origine ; `%s` est remplacé par la recherche ou le nom du filtre. Texte brut, une valeur par langue.
- **Variables personnalisées** : un code (minuscules, chiffres, `_`, ex. `livraison`) et une valeur par langue (ex. « Livraison offerte dès 50 € »), utilisables sous la forme `{{livraison}}` dans les modèles de carte (avec `df-if-livraison` au besoin), le contenu HTML des popins et bannières, le titre et le texte « Voir tout » des carrousels. Les codes sont communs à toutes les langues ; une variable sans valeur dans une langue n'affiche rien. Un code de variable système est refusé ; un code inconnu reste affiché tel quel (`{{inconnue}}`), ce qui aide à repérer une faute de frappe.

Les valeurs sont envoyées au widget avec sa configuration (visibles sur le site d'ici quelques minutes) et insérées comme du texte, jamais comme du HTML. Elles ne sont pas encore copiées par la copie de configuration entre projets.

## Détails techniques

- Code : `src/Widget/WidgetTexts.php` (textes d'origine, groupes de l'écran, variables personnalisées), `templates/admin/variables.php`, `public/assets/variables.js`.
- Stockage (par projet) : `widget_texts_<langue>` (textes modifiés seulement), `custom_vars` (codes et descriptions), `custom_vars_<langue>` (valeurs).
- Les **textes d'origine** sont une copie de la table `T` de `public/widget/deepfind.js` ; un test vérifie qu'elles sont identiques. Pour ajouter un texte au widget : l'ajouter aux deux, et à `WidgetTexts::GROUPS` pour qu'il apparaisse à l'écran.
- Le widget reçoit `texts` et `vars` avec `/api/v1/config` et les applique aussi aux éléments créés avant la configuration (bouton des filtres, champ de recherche…).
