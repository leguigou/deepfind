# Règles selon la page

**Recommandations › Règles** adapte les résultats à la **page du site** où se trouve le visiteur, sans mot-clé ni période. Exemple : sur les pages dont l'adresse contient `/nautisme/`, ne proposer que la catégorie Nautisme.

Une règle comprend :
1. **Pages concernées** : une ou plusieurs adresses (chemin sans le nom de domaine, majuscules ignorées) qui **contiennent**, **commencent par**, **finissent par** ou **sont exactement** un texte ; il suffit qu'une adresse corresponde. Une adresse complète collée (`https://www.example.com/fr/nautisme.html`) est réduite à son chemin.
2. **Action**, au choix :
   - **Filtre pré-coché** : seuls ces produits sortent au départ, avec un bandeau « Résultats limités à **Nautisme ✕** » ; un clic sur ✕ élargit à tout le catalogue (« Revenir à Nautisme » le remet). Le texte du bandeau se règle dans la règle ;
   - **Afficher uniquement** ces produits (filtre imposé, non retirable) ;
   - **Exclure** ces produits.
3. **Produits concernés** : conditions comme pour les résultats personnalisés (marque, catégorie, type, SKU, attributs : *est* / *n'est pas* / *contient* / *ne contient pas* ; disponibilité, promotion, nouveauté ; prix ou marge supérieur / inférieur à), toutes ou au moins une.
   - **contient** (plusieurs textes possibles, il suffit d'un) : toutes les valeurs du catalogue qui contiennent le texte, sans majuscules ni accents. Ex. Catégorie *contient* « alimentation » couvre « Alimentation », « Alimentation chien »… **ne contient pas** : aucune de ces valeurs. DeepFind recherche ces valeurs dans le catalogue (jusqu'à 100 par texte, les plus fréquentes en premier) et les recalcule après chaque import. Si aucune valeur ne contient le texte, *contient* n'affiche aucun produit.

**Fonctionnement**
- Un interrupteur active ou désactive chaque règle. **Seule la première règle active de la liste dont une adresse correspond s'applique** : l'ordre se change par glisser-déposer (enregistré au lâcher).
- La règle devient un filtre du moteur : nombre de résultats, pagination et filtres du widget (marques, catégories…) restent justes. Elle s'ajoute aux autres réglages (résultats personnalisés, boosts, filtres choisis par le visiteur). Les articles de blog ne sont pas concernés.
- Rien ne change tant que le visiteur n'a rien tapé.
- **Vérifier une règle** : **Aperçu interne**, champ « Page du site » (ex. `/fr/nautisme/kayak.html`) : la règle appliquée est indiquée au-dessus des résultats.
- Nécessite le widget `?v=23` ou plus récent : il envoie le chemin de la page (sans paramètres) avec chaque recherche.
