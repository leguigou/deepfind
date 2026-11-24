# Modèle de carte produit

Chaque produit (recherche, carrousels) est affiché par un **modèle HTML** modifiable dans *Paramètres › Intégration › layer › Modèle de carte produit* (onglets ordinateur et mobile ; mobile vide = ordinateur). Un carrousel peut avoir son propre modèle (vide = celui de la recherche).

## Modèle d'origine

```html
<div class="df-media {{media_class}}">
  <img class="df-img df-if-image" src="{{image}}" alt="">
  <span class="df-badge df-badge-sale df-if-discount">{{discount}}</span>
  <span class="df-badge df-badge-new df-if-new">{{new}}</span>
  <span class="df-badge df-badge-oos df-if-out_of_stock">{{out_of_stock}}</span>
</div>
<div class="df-brand df-if-brand">{{brand}}</div>
<div class="df-name">{{name}}</div>
<div class="df-variant df-if-variant">{{variant}}</div>
<div class="df-price {{price_class}}">
  <span class="df-from df-if-from">{{from}}</span>
  <span class="df-final">{{price}}</span>
  <span class="df-old df-if-old_price">{{old_price}}</span>
</div>
```

## Règles

- **Variables** `{{…}}` : voir la liste dans *Paramètres › Variables* ([Variables et textes](variables-et-textes)). Valeurs échappées : un nom de produit ne peut pas injecter de HTML.
- **Affichage conditionnel** : un élément de classe `df-if-<variable>` n'est affiché que si la variable a une valeur (ex. `df-if-discount` : seulement en promotion).
- **Remplacer une variable par un texte fixe** en gardant la classe : `<span class="df-from df-if-from">À partir de :</span>` n'apparaît que pour les produits à prix variable. Pour changer le texte partout sans toucher au modèle : *Paramètres › Variables › Textes du widget*.
- HTML simple (div, span, p, strong, img…), pas de script ni d'événement (`onclick`…), pas de lien : **la carte entière est un lien**. Le code est nettoyé à l'enregistrement, puis par le widget.
- `img.df-img` affiche la deuxième photo au survol (réglage *Paramètres › Widget sur le site*).

## Lien de la carte

Le lien `<a class="df-card" href="…">` est posé autour du modèle ; sa valeur est modifiable (ligne du haut de l'éditeur) :

| Lien saisi | Résultat |
|---|---|
| `{{url}}` (par défaut) | adresse du produit (ou de la déclinaison trouvée par la recherche) |
| `{{url}}?utm_source=deepfind&utm_medium=recherche` | paramètres ajoutés proprement, même si l'adresse contient déjà `?` ou `#` |
| `{{url_path}}` | chemin seul (ex. `/c4-predator.html`) sur le domaine de la page |
| `/produit/{{sku}}`, `https://…/{{id}}` | adresse construite avec le SKU ou l'identifiant (valeurs encodées) |

Le lien doit commencer par `{{url}}`, `{{url_path}}`, `https://` ou `/` ; sinon il est refusé (message dans l'extranet) et le lien par défaut s'applique.

## Exemple : « À partir de : » sur sa propre ligne

```html
<div class="df-price-block">
  <div class="df-from-line df-if-from">À partir de :</div>
  <div class="df-price {{price_class}}">
    <span class="df-final">{{price}}</span>
    <span class="df-old df-if-old_price">{{old_price}}</span>
  </div>
</div>
```

```css
.df-price-block { margin-top: auto; padding-top: 12px; }
.df-price-block .df-price { margin-top: 0; padding-top: 0; }
.df-from-line { font-size: 14px; color: var(--df-muted); margin-bottom: 2px; }
```
