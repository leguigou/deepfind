# Règles de boost

![Règles de boost](docs/screenshots/15-regles-de-boost.png)

- Une règle a un nom, un statut, un **coefficient** (1 = neutre, 2 = double, 0,5 = moitié, 0 = en fin de liste), des **termes de recherche** facultatifs (exacte : la recherche est exactement ce terme ; large : elle contient tous ses mots) et des **conditions** (toutes, ou au moins une).
- **Conditions** :
  - champ à valeurs (marque, catégorie, type, SKU, attributs) : **est** (valeurs cochées), **contient**, **ne contient pas**, **commence par**, **finit par**, sans tenir compte des majuscules ni des accents ;
  - pour **contient**, **commence par** et **finit par**, plusieurs textes peuvent être saisis (Entrée entre chaque) : il suffit que l'un d'eux corresponde (OU). Avec **ne contient pas**, le produit ne doit contenir aucun d'eux ;
  - **nom du produit** : contient, ne contient pas, commence par, finit par (sans majuscules ni accents). Exemple : Marque *est* ECOFLOW **et** Nom du produit *contient* « batterie » booste les batteries sans les accessoires ;
  - champ oui/non (disponibilité, en promotion, nouveauté) : valeurs à cocher ;
  - champ chiffré (prix, remise, marge, ventes) : supérieur ou égal, inférieur ou égal.
- La liste des valeurs à cocher montre les 100 premières ; tapez dans « Rechercher… » pour chercher parmi **toutes** les valeurs du catalogue.
- La pertinence des produits concernés est multipliée par le coefficient (plusieurs règles se multiplient), lors du réordonnancement des premiers résultats, en tri « pertinence » uniquement.
- **Boost rapide d'un produit** : dans l'aperçu interne, la liste « Boost » crée une règle « Produit SKU » (toutes les recherches).
- **Produits nommés par SKU** : dans la liste des règles, sous chaque condition « SKU », le produit est affiché (photo, nom, marque, prix, rupture). Un clic ouvre sa **fiche complète** (prix, stock, marge, ventes et clics 30 jours, score métier, catégories, attributs, variantes, descriptions), la même que « Détails » dans l'aperçu interne. Un SKU absent du catalogue est signalé « introuvable dans le catalogue ».
