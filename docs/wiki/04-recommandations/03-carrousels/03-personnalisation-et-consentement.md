# Personnalisation et consentement

Les carrousels **personnalisés** (« vus récemment », « vous aimerez aussi ») utilisent l'historique du visiteur.

## Où est l'historique ?

- Dans le **navigateur du visiteur** (`localStorage`, clé `df-hist:<clé>:<langue>`) : 20 produits au plus, 30 jours au plus, sans identifiant.
- Alimenté par les produits cliqués dans la recherche et les carrousels, et par les fiches produit qui affichent un carrousel (produit reconnu par l'adresse ou le SKU).
- Envoyé avec la demande des carrousels de la page uniquement pour choisir les produits (réponse non mise en cache) ; **jamais enregistré** par DeepFind.

## Réglage du consentement

En bas de *Recommandations › Carrousels* :

| Réglage | Effet |
|---|---|
| Après consentement (par défaut) | Rien n'est gardé tant que le site n'a pas transmis l'accord du visiteur |
| Toujours | Historique gardé sans demander (seulement si votre analyse RGPD le permet) |
| Jamais | Les carrousels personnalisés affichent leur sélection automatique |

## Brancher le bandeau cookies

```js
// le visiteur accepte la finalité « personnalisation »
window.DeepFind && DeepFind.consent(true);
// il refuse ou retire son accord : l'historique est effacé
window.DeepFind && DeepFind.consent(false);
// avant le chargement du widget (bandeau chargé en premier)
window.deepfindConsent = true;
```

> ⚠️ À faire valider par le DPO (finalité « personnalisation » dans le bandeau cookies). Ce document n'est pas un avis juridique.
