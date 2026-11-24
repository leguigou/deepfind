# Aide de DeepFind

Bienvenue dans l'aide de **DeepFind**, le moteur de recherche instantanée, de recommandations (bannières, popins, carrousels) et de supervision du site.
Choisissez une rubrique à gauche, ou tapez un mot dans la recherche en haut (raccourci : touche `/`).

## Par où commencer

| Je veux… | Rubrique |
|---|---|
| Comprendre comment DeepFind fonctionne | [Présentation et architecture](demarrage/presentation-et-architecture) |
| Installer ou mettre à jour DeepFind | [Installation](demarrage/installation), [Mettre à jour](demarrage/mettre-a-jour) |
| Brancher le widget sur le site Magento | [Intégrer le widget sur le site](demarrage/integrer-le-widget) |
| Importer le catalogue | [Formats des flux](catalogue/formats-des-flux), [Imports](catalogue/imports) |
| Régler les résultats de recherche | [Recherche et pertinence](recherche-et-pertinence) |
| Mettre en avant des produits ou des messages | [Recommandations](recommandations) |
| Changer l'apparence du widget | [Affichage du widget](affichage-du-widget) |
| Suivre l'activité du site | [Analyse](analyse) |
| Résoudre un problème | [Dépannage](exploitation/depannage) |

## Contribuer à l'aide

Cette aide est faite de fichiers texte (format **Markdown**) rangés dans le dépôt de DeepFind, dossier `docs/wiki/`. Elle est mise à jour avec le code : chaque évolution du site doit compléter la page concernée dans la même modification.

- **Un dossier = une catégorie** (ou sous-catégorie), **un fichier `.md` = une page**. Le préfixe `01-`, `02-`… fixe l'ordre et n'apparaît pas dans l'adresse : `docs/wiki/02-catalogue/03-imports.md` → `/admin/wiki/catalogue/imports` (l'adresse courte `/wiki/…` y mène aussi).
- Le **titre** d'une page est sa première ligne `# Titre`. Le fichier `_index.md` d'un dossier donne le titre et l'introduction de la catégorie.
- Mise en forme : titres `##` et `###` (ils forment le sommaire « Sur cette page »), listes, tableaux, blocs de code entre trois accents graves, `code`, **gras**, *italique*, liens `[texte](../autre-categorie/page)`.
- Encadrés : une citation qui commence par ⚠️ devient un avertissement, par 💡 un conseil.
- Le HTML n'est pas interprété (il s'affiche tel quel) : la page reste sûre quel que soit son contenu.

> 💡 La page est visible dès que le fichier est déployé : aucune commande à lancer.
