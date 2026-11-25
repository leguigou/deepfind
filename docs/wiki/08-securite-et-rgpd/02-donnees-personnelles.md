# Données personnelles (RGPD)

| Fonction | Données | Où | Durée |
|---|---|---|---|
| Journal des recherches | recherche, empreinte IP, IP tronquée, navigateur | base DeepFind | réglable (Paramètres) |
| Mesure d'audience | empreinte du jour (sans IP), puis totaux | base DeepFind | 1 jour, puis totaux anonymes |
| Parcours d'achat | paniers et commandes (montants) par empreinte du jour, puis totaux ; termes masqués | base DeepFind | 1 jour, puis totaux (termes et produits : durée des journaux) |
| Provenance (ciblage) | canal et UTM de la page d'arrivée, sous l'empreinte du jour | mémoire du serveur (jamais en base) | 30 h au plus |
| Recommandations, CA par emplacement | paires de produits achetés / regardés ensemble, emplacement du dernier clic (mémoire), totaux par jour | base DeepFind (totaux), mémoire (par visiteur) | 90 jours (paires), journaux (emplacements), 30 h (mémoire) |
| Panier (recommandations) | SKU et total du panier, envoyés avec la demande des carrousels | non enregistré | — |
| Parcours (désactivé par défaut) | chronologie d'une journée par empreinte du jour : pages, recherches masquées, clics, panier, commande | base DeepFind | 24 h après le dernier événement |
| Supervision (temps réel) | compteurs, empreinte du jour des visiteurs actifs | base DeepFind | 15 min (visiteurs), 6 jours (compteurs) |
| Popins | fermeture mémorisée | navigateur du visiteur | N jours (réglage) |
| Carrousels personnalisés | 20 derniers produits vus | navigateur du visiteur (consentement) | 30 jours |
| Statistiques popins / carrousels | totaux par jour | base DeepFind | 6 jours à 90 jours selon la page |

> ⚠️ À faire valider par le DPO : durées de conservation, mention dans la politique de confidentialité, exemption de consentement pour la mesure d'audience et la mémorisation des popins, parcours individuels et attribution du chiffre d'affaires (avant activation), finalité « personnalisation » pour les carrousels. Ce document n'est pas un avis juridique.
