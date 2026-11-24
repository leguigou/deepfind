# Pertinence et classement

![Pertinence & classement](docs/screenshots/16-pertinence.png)

1. **Correspondance des mots**, en trois modes :
   - *Exacts, sinon approchants signalés* (par défaut) : tous les mots doivent être trouvés. Sinon, des produits approchants sont proposés avec la mention « Aucun résultat exact », et la recherche compte comme « sans résultat exact » dans les statistiques.
   - *Exacts uniquement* : tous les mots, sinon aucun résultat.
   - *Le plus de résultats possible* : le moteur retire des mots, au risque de résultats sans rapport.

   Un seuil **« Pertinence minimum »** (0 à 1) peut écarter les produits trop peu pertinents. Réglez-le en regardant la colonne « Pertinence » de l'aperçu interne.
2. **Importance des champs dans la recherche texte** : numérotez les champs (nom, marque, SKU, mots-clés, catégories, attributs, descriptions). Un mot trouvé dans un champ bien classé fait remonter le produit. Un champ peut être rendu non cherchable. *Appliqué immédiatement au moteur.*
   - **Bonus « mot recherché au début du nom »** (0,5 par défaut) : DeepFind réordonne les premiers résultats. Un mot de la recherche en 1re position du nom vaut 1, en 2e 1/2, en 3e 1/3… La pagination reste cohérente.
   - Meilisearch classe par *ordre* des champs, pas par poids. Pour que cet ordre compte plus, activez **« Priorité au champ où le mot est trouvé »**.
3. **Score métier** : un poids par critère (0 pour l'ignorer) : en stock, rentabilité, popularité (ventes 30 jours du flux), **clics dans la recherche sur 30 jours**, nouveauté, boosts.
   - **Recalcul** : à chaque import, **chaque nuit à 4 h 30** (interrupteur), ou tout de suite avec **« Enregistrer et recalculer les scores »**. Ce recalcul relit les produits indexés, sans retélécharger les flux, et se suit dans **Imports**.
4. **Importance du score métier face à la pertinence** : *faible* (il ne départage que des produits aussi pertinents, recommandé), *moyenne* ou *forte*. Interrupteur **Ruptures en fin de liste**.

## Aperçu interne

![Aperçu interne](docs/screenshots/05-apercu-interne.png)

Réservé à l'équipe : la marge, le score métier et les règles appliquées n'y sont visibles que dans l'extranet, **jamais envoyés au site public**.
- Tri par rentabilité, filtre « marge minimum », filtres du catalogue cliquables.
- Sur chaque produit : **boost rapide** (crée une règle de boost « Produit SKU »), **épingler** à une position pour la recherche en cours, **désactiver** (retire le produit de toutes les recherches), **détails** (fiche du produit et champs où les mots ont été trouvés).
- L'interrupteur **« Désactivés »** affiche aussi les produits désactivés qui correspondraient à la recherche, avec leur type :
  - **« désactivé manu »** : désactivé avec le bouton « Désactiver ». Seul « Activer » le fait revenir, même s'il est dans le flux.
  - **« désactivé auto »** : absent du dernier flux complet (voir ci-dessous). Il revient de lui-même quand il réapparaît dans le flux.
