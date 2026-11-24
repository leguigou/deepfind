# Mapping des flux

## Mapping visuel (recommandé)

![Mapping visuel](docs/screenshots/13-mapping-visuel.png)

**Catalogues › Flux & mapping › Mapping** : DeepFind télécharge le flux (ou réutilise le téléchargement de moins d'une heure), détaille ses 300 premiers éléments (exemples de valeurs, groupes répétés) puis parcourt **tout le fichier** pour lister **toutes les balises**, même celles qui n'apparaissent que plus loin, ou qui sont absentes quand elles sont vides. Le taux de présence est calculé sur tout le flux ; une balise toujours vide est signalée « (toujours vide) » et n'est jamais pré-sélectionnée. Parcours limité à 60 secondes (environ 1,5 s pour 40 Mo), résultat gardé en cache tant que le fichier ne change pas.

**Ce qui est détecté :**
- L'élément qui représente un produit (`<product>`, `<item>`…), modifiable.
- Le mode des variantes : imbriqué, ou « à plat » si un champ parent est trouvé et qu'il n'y a pas de groupe répété.
- Le groupe des variantes (ex. `variants/variant`).
- **Tous les champs** : éléments, attributs XML (`@id`), et attributs identifiés par un code (`attributes/attribute[@code='couleur']`).

**Présentation de l'écran :**
- **À gauche**, les champs DeepFind : d'abord les **obligatoires** (marqués d'un `*`, surlignés tant qu'ils ne sont pas mappés), puis les **secondaires**.
- **À droite**, une liste déroulante des champs détectés, avec un **exemple de valeur** et le **taux de présence** (ex. `url_key (22 %)` : présent seulement sur les parents d'un flux à plat).
- Les champs sont **pré-sélectionnés automatiquement** d'après les noms usuels (badge « auto ») : `url_key` pour l'URL, `special_price` pour le prix final, `manufacturer` pour la marque, `is_in_stock`, `qty`… Vérifiez toujours ces suggestions.
- Choisir **« — non mappé — »** pour un champ est mémorisé : il n'est plus re-proposé automatiquement.
- Pour les champs oui/non (stock, activé), l'écran montre comment chaque valeur d'exemple sera comprise (`in_stock = oui, out_of_stock = non`).
- Une colonne facultative permet d'ajouter des **transformations** (ex. `to_float, divide:100` pour une marge en %).
- **Sections suivantes** : variantes, catégories (chemin et identifiant), **attributs filtrables** (liste « par code » détectée, ou champs simples comme `color` et `size`, proposés automatiquement).

Le résultat est enregistré dans le mapping JSON de la source, qui reste modifiable en **mode avancé**.

## Syntaxe du mapping (JSON, mode avancé)

```json
"fields": {
  "brand":       {"path": "manufacturer", "transform": ["trim", "uppercase"]},
  "final_price": {"path": "special_price", "transform": ["default:0"]},
  "margin_rate": {"path": "marge_pct", "transform": ["to_float", "divide:100"]},
  "id":          {"path": "@id"},
  "type":        {"value": "simple"}
}
```

- **Chemins** :
  - `nom` : élément enfant ;
  - `a/b` : sous-élément ;
  - `@attr` : attribut ;
  - `a/@attr` : attribut d'un enfant ;
  - `.` : texte de l'élément ;
  - toute autre expression est lue comme du XPath (`g:price`, `attribute[@code='color']`).
- **Transformations** : `trim`, `lowercase`, `uppercase`, `ucfirst`, `strip_html`, `to_float`, `to_int`, `to_bool` (accepte 1/0, oui/non, « En stock »…), `divide:N`, `multiply:N`, `default:X`, `split:SEP`, `prefix:X`, `suffix:X`, `replace:A|B`, `truncate:N`, `date_iso`.
- **Attributs simples** d'un flux à plat : `"extra_attributes": {"couleur": {"path": "color", "label": "Couleur", "filterable": true}}`.

> 🔒 Le flux produits contient la marge : protégez son URL côté Magento (mot de passe .htaccess / .htpasswd, jeton, filtrage IP, HTTPS).

**Accès au flux** (fiche de la source, Catalogues › Flux & mapping) :
- **Utilisateur et mot de passe** d'un flux protégé par le serveur web (.htaccess / .htpasswd, authentification Basic ou Digest). Le mot de passe est **chiffré** dans la base (clé dérivée de `APP_SECRET`), n'est jamais réaffiché (laisser le champ vide le conserve, une case permet de le supprimer) et n'est envoyé qu'au serveur du flux, jamais à un autre site en cas de redirection. Si `APP_SECRET` change, ressaisissez-le.
- **En-têtes HTTP supplémentaires** (jeton…), un par ligne.
- **Tester la connexion** : vérifie le flux complet et le flux delta avec les valeurs saisies, sans enregistrer ni télécharger tout le fichier (premiers Ko seulement). Résultat : code HTTP (200 = fichier trouvé ; 401 = protégé, ou identifiants refusés ; 403 = accès refusé ; 404 = adresse introuvable ; serveur injoignable), protection détectée ou non, taille, date de modification, redirection, et nature du contenu (XML, fichier .gz, ou page HTML par erreur). Fonctionne aussi pour un flux sans protection et pour un chemin local.
- Le téléchargement JSON de la configuration (Projets) ne contient ni mot de passe ni en-têtes d'accès ; la copie entre projets les reprend avec les adresses des flux.
