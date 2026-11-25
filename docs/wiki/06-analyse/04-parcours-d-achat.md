# Parcours d'achat

En bas de *Analyse › Audience*, la section **Parcours d'achat** relie la recherche aux ventes, sans cookie ni donnée personnelle : chaque visiteur est reconnu pendant **une journée de mesure** par l'empreinte du jour (voir [Audience](audience)), puis il ne reste que des totaux.

## Indicateurs

| Indicateur | Calcul (visiteurs de la journée) |
|---|---|
| Conversion des visiteurs qui cherchent | ont cherché **et** commandé ÷ ont cherché ; comparé aux visiteurs qui n'ont pas cherché |
| Chiffre d'affaires mesuré | total des commandes reçues ; part des visiteurs qui ont cherché |
| Panier moyen après recherche | chiffre d'affaires ÷ commandes, visiteurs qui ont cherché / les autres |
| Paniers sans commande | ont mis au panier sans commander ÷ ont mis au panier ; dont après une recherche |
| Partis après « aucun résultat » | recherche sans résultat, puis ni clic, ni carrousel, ni panier, ni commande |
| Ferment la recherche sans cliquer | ont fermé la fenêtre de recherche après une recherche sans cliquer sur un résultat |
| Entonnoir | visiteurs → ont cherché → ont cliqué → ont cherché puis mis au panier → ont cherché puis commandé |
| Recherches qui rapportent | par terme : recherches, paniers, commandes, chiffre d'affaires (attribués à la **dernière** recherche du visiteur avant le panier ou la commande) |
| Produits cliqués jamais mis au panier | au moins 3 clics (recherche et carrousels) sur la période, aucun panier ni commande : prix, photos ou fiche à revoir |

> 💡 Un achat le lendemain d'une recherche n'est pas relié : le visiteur n'est reconnu que pendant une journée de mesure.

## Brancher le site (paniers et commandes)

**1. Automatique (dataLayer GA4)** : si le site envoie déjà les événements e-commerce GA4 à Google Tag Manager ou gtag, rien à faire. Le widget lit `add_to_cart` et `purchase` (réglage « Lire les événements GA4 du dataLayer », activé par défaut) :

```js
dataLayer.push({ event: 'add_to_cart', ecommerce: { items: [{ item_id: 'C4-PREDATOR', quantity: 1, price: 129.9 }] } });
dataLayer.push({ event: 'purchase', ecommerce: { transaction_id: '100045', value: 89.9, items: [ /* … */ ] } });
```

Une commande annoncée avant le chargement du widget (page de confirmation) est aussi lue. L'événement Magento `ajax:addToCart` (thème Luma, jQuery) est écouté ; un même ajout signalé deux fois en 3 secondes ne compte qu'une fois.

**2. Avec le widget** (autres thèmes, Hyvä sans dataLayer) :

```js
// bouton « Ajouter au panier »
window.DeepFind && DeepFind.track('cart', { sku: 'C4-PREDATOR', qty: 1, price: 129.9, name: 'Palmes Predator' });
// page de confirmation de commande
window.DeepFind && DeepFind.track('order', { id: '100045', total: 89.9, items: [{ sku: 'C4-PREDATOR', qty: 1, price: 89.9 }] });
```

- Prix absent : prix du catalogue DeepFind (`final_price`) pour le SKU.
- Le numéro de commande sert **uniquement** à ne pas compter deux fois une commande (page rechargée) : une empreinte est gardée 2 jours en mémoire, le numéro n'est jamais enregistré.
- Rien n'est envoyé depuis la page Démo ni en aperçu (favori « Tester DeepFind »).

## Réglages (en bas de la page Audience)

- **Panier et commandes** : interrupteur de la mesure.
- **Lire les événements GA4 du dataLayer** : lecture automatique décrite plus haut.
- **Heure de changement de la clé du jour** : début de la journée de mesure (4 h par défaut).

## Chiffre d'affaires par emplacement

Chaque panier et commande d'un produit est attribué à l'**endroit où le visiteur l'a cliqué en dernier** le même jour : la recherche, un carrousel précis, ou aucun (navigation du site). Visible dans les [campagnes](../recommandations/campagnes) (table `audience_attrib`, purgée avec les journaux).

## Détails techniques

- API : `POST /api/v1/event` avec `e` = `bar` (barre ouverte), `close` (fermée sans clic, `q`), `cart` (`sku`, `qty`, `price`, `name`), `order` (`tid`, `value`, `items` en JSON). Réponse immédiate, traitement après l'envoi (`src/Logging/Tracker.php`).
- Compteurs par visiteur et par jour : colonnes de `audience_visitors` (`bar_opens`, `closes`, `zero_searches`, `carousel_clicks`, `carts`, `cart_value`, `orders`, `revenue`, montants en centimes), agrégées chaque nuit dans `audience_daily`.
- Recherches qui rapportent : `audience_terms` (terme masqué, en minuscules ; pendant la frappe, « ma », « mas », « masque » comptent pour « masque »). Produits : `audience_products`. Les deux sont purgés avec les journaux (`logs:purge`, durée des journaux).
- Masquage des termes (`Journeys::mask`) : e-mails, téléphones, numéros de carte en groupes de 4 chiffres, IBAN. Les SKU et EAN restent lisibles.
