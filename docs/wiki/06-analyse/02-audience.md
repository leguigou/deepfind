# Audience

> 💡 Indicateurs de vente (conversion, chiffre d'affaires, paniers abandonnés, recherches qui rapportent) : voir [Parcours d'achat](parcours-d-achat). Chronologie par visiteur : [Parcours](parcours).

**Analyse › Audience** suit l'évolution, jour après jour, de l'usage du site et du moteur de recherche, sans aucune information personnelle :
- **Visiteurs par jour**, **part des visiteurs qui cherchent** (les autres naviguent sans chercher), **recherches par visiteur** (1, 2 ou 3, 4 et plus), **part qui clique sur un résultat**, **pages vues par visiteur**.
- Répartition **mobile / tablette / ordinateur** (d'après la taille de l'écran indiquée par le widget, sinon le navigateur).
- Courbe quotidienne (journées complètes ; survol ou flèches du clavier pour le détail), tableau jour par jour, export CSV ; périodes 7 jours, 30 jours, 90 jours, 12 mois, ou **Personnalisé** (dates de début et de fin, 2 ans au plus).

**Comment c'est mesuré**
- Le widget envoie un petit signal « page vue » (`POST /api/v1/visit`) sur les pages choisies. Les recherches et les clics sont comptés pendant la recherche, sans requête en plus. Pendant la frappe, « pal », « palm », « palme » comptent pour une seule recherche.
- Un visiteur est reconnu **pendant une journée seulement**, par une empreinte calculée sur le serveur (adresse IP et navigateur, mélangés à une clé secrète **changée chaque jour**). Rien n'est stocké sur l'appareil du visiteur (ni cookie, ni stockage local), l'adresse IP n'est jamais enregistrée.
- Chaque nuit (planificateur `schedule:run`), la journée écoulée est transformée en **totaux** ; les empreintes et la clé du jour sont alors **supprimées** : il devient impossible de suivre quelqu'un d'un jour à l'autre. Seuls les totaux quotidiens sont conservés.
- Rien n'est envoyé depuis la page **Démo du widget** ni pendant un aperçu (favori « Tester DeepFind »).

**Réglages** (en bas de la page Audience), pour limiter la sollicitation du serveur si besoin :
- interrupteur général, puis un interrupteur par mesure : **pages vues** (la seule qui ajoute des requêtes : une par page mesurée), **recherches**, **clics** ;
- **pages qui envoient le signal** : toutes, la page d'accueil uniquement (`/` ou la racine d'une langue, ex. `/fr`), ou des **pages choisies** : adresses qui commencent par l'un des chemins indiqués (`/fr/plongee` couvre `/fr/plongee.html` et `/fr/plongee/palmes.html`), avec ou sans la page d'accueil.
- Moins de pages mesurées = moins de requêtes, mais les visiteurs qui ne passent pas par ces pages ne sont comptés que s'ils cherchent : la part de visiteurs qui cherchent est alors surestimée (la page le signale).
- Nécessite le widget `?v=22` ou plus récent et la tâche planifiée `schedule:run`.

## Journée de mesure

Une journée de mesure va d'une heure réglable (**4 h** par défaut, heure de Paris) à la même heure le lendemain, et porte la date de son début : une visite à 1 h du matin compte avec la soirée de la veille. La clé du jour change à cette heure, puis la journée écoulée est transformée en totaux et sa clé détruite.

- Réglage : *Réglages › Heure de changement de la clé du jour*. Commun à tous les projets.
- Un changement s'applique à la **bascule suivante** (jamais en cours de journée) : la journée concernée est plus courte ou plus longue, mais aucun visiteur n'est compté deux fois. La page indique le changement programmé.
- Technique : `Audience::measureDay()` (date de l'instant moins l'heure de changement, fuseau `APP_TIMEZONE`), réglages globaux `audience_day_hour`, `audience_day_hour_next`, `audience_day_hour_at`.
