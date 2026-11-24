# Produits désactivés

Un produit désactivé n'apparaît plus nulle part sur le site : recherche, carrousels, règles. Il y a deux cas.

| Type | Origine | Retour |
|---|---|---|
| **désactivé manu** | bouton « Désactiver » de l'aperçu interne | uniquement avec « Activer », même s'il est dans le flux |
| **désactivé auto** | absent du dernier flux complet | de lui-même dès qu'il réapparaît dans le flux |

## Page « Produits désactivés »

*Catalogues › Produits désactivés* liste les produits désactivés à la main, avec un filtre, « Activer » par produit et « Tout réactiver ».

## Depuis l'aperçu interne

![Aperçu interne](docs/screenshots/05-apercu-interne.png)

Réservé à l'équipe : la marge, le score métier et les règles appliquées n'y sont visibles que dans l'extranet, **jamais envoyés au site public**.
- Tri par rentabilité, filtre « marge minimum », filtres du catalogue cliquables.
- Sur chaque produit : **boost rapide** (crée une règle de boost « Produit SKU »), **épingler** à une position pour la recherche en cours, **désactiver** (retire le produit de toutes les recherches), **détails** (fiche du produit et champs où les mots ont été trouvés).
- L'interrupteur **« Désactivés »** affiche aussi les produits désactivés qui correspondraient à la recherche, avec leur type :
  - **« désactivé manu »** : désactivé avec le bouton « Désactiver ». Seul « Activer » le fait revenir, même s'il est dans le flux.
  - **« désactivé auto »** : absent du dernier flux complet (voir ci-dessous). Il revient de lui-même quand il réapparaît dans le flux.

## Désactivation automatique

À chaque **import complet**, DeepFind compare le flux avec l'index en ligne, par SKU :
- un produit **absent du flux** (ou rejeté, par exemple `enabled = 0` ou prix nul) devient **non vendable** : il est gardé **90 jours** dans l'index, sans jamais être proposé aux visiteurs, puis retiré ;
- s'il **revient dans le flux complet**, il est **réactivé automatiquement** ;
- une **désactivation manuelle** reste indépendante du flux.

Le contrôle de sécurité de l'import (« Import bloqué si le catalogue baisse de plus de N % », 20 % par défaut) porte sur les produits vendables. Le tableau de bord compte les produits désactivés auto à part.

> Après une mise à jour qui apporte cette fonction, lancez un **import complet** : le filtre « vendable » ne s'active qu'une fois l'index reconstruit.
