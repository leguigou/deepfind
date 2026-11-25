# Parcours

*Analyse › Parcours* montre la **chronologie datée** de la journée des visiteurs qui ont **cherché** dans la barre de recherche ou **cliqué sur un carrousel** : pages vues, barre ouverte, recherches, clics, popins, carrousels, ajouts au panier, commande.

> ⚠️ Désactivé par défaut. Une vue individuelle, même pseudonyme et limitée à une journée, n'entre pas forcément dans l'exemption de consentement de la mesure d'audience : à faire valider par le DPO avant d'activer (*Analyse › Audience › Réglages › Parcours*). Ce document n'est pas un avis juridique.

## La liste

- Un parcours par visiteur et par journée de mesure : « Visiteur A66F91 » (repère tiré de l'empreinte du jour, sans lien avec la personne), début et page d'entrée, durée, appareil, premières recherches, pages vues, clics, panier, commande ou « abandon ».
- Filtres : avec commande, panier sans commande, recherche sans résultat, clic dans un carrousel, recherche sans clic ; journée ; texte contenu (recherche, produit, page).

## La chronologie

Chaque ligne : heure (à la seconde), type, détail.

| Événement | Détail affiché |
|---|---|
| Page vue | titre et chemin de la page (chemin seul sur les pages compte, commande, paiement) |
| Barre de recherche ouverte | — |
| Recherche | **dernière saisie** (« pa », « pal », « palme » = une ligne « palme ») et nombre de résultats, filtres |
| Clic sur un résultat | produit, position, recherche d'origine |
| Fenêtre fermée sans clic | dernière recherche |
| Clic dans un carrousel | carrousel, produit |
| Popin | cliquée ou fermée |
| Ajout au panier | produit, quantité, montant |
| Commande | montant, nombre d'articles |

## Ce qui est gardé, et combien de temps

- **Déclencheur** : un visiteur n'est enregistré qu'à sa première recherche ou à son premier clic dans un carrousel. Avant, ses pages vues restent en mémoire (25 au plus, 6 heures) puis disparaissent sans être écrites ; s'il cherche ensuite, le parcours commence par ces pages.
- **Identification** : l'empreinte du jour de l'[audience](audience) (HMAC de l'IP et du navigateur avec une clé détruite à la fin de la journée de mesure). Aucun cookie, rien sur l'appareil, aucune IP enregistrée ; deux journées ne peuvent pas être reliées.
- **Masquage** des recherches et des titres : e-mails, téléphones, numéros de carte, IBAN.
- **Conservation** : 24 heures après le dernier événement du parcours, puis suppression (tâche `schedule:run`, chaque minute).

## Limites

- Deux personnes avec la même box et le même modèle de téléphone peuvent être confondues ; un téléphone qui change de réseau (Wi-Fi → 4G) compte comme deux visiteurs.
- La navigation hors du widget n'est connue que par les pages vues (la catégorie choisie apparaît comme l'adresse de la page).
- Paniers et commandes : seulement si le site les envoie (voir [Parcours d'achat](parcours-d-achat)).

## Détails techniques

- Code : `src/Logging/Journeys.php` (enregistrement, masquage, liste, purge), `src/Logging/Tracker.php` (événements reçus par l'API), `src/Admin/JourneysController.php`, gabarits `templates/admin/journeys.php` et `journey.php`.
- Tables : `journeys` (un parcours : empreinte du jour, appareil, compteurs) et `journey_events` (`type`, `at` à la milliseconde, `data` en JSON).
- **Durée de conservation** : constante `Journeys::RETENTION_HOURS` (24) ; mémoire avant déclencheur : `TRIGGER_BUFFER` et `BUFFER_TTL`.
- Parcours activés : le widget envoie une page vue sur **toutes** les pages (`a=0` quand la page n'est pas mesurée par l'audience : elle sert seulement au parcours). Le serveur ne garde rien pour les visiteurs qui ne cherchent pas.
