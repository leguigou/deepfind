# Temps réel et charge

**Analyse › Temps réel & charge** suit en direct l'activité du site et la charge du serveur DeepFind.

- **Période** : 1 heure, 6 heures, 24 heures ou 5 jours, ou **depuis un instant précis** (5 jours au plus). La fin est toujours « maintenant » : en mode **En direct** (actif par défaut), la page se met à jour toutes les 15 secondes et la fenêtre glisse avec le temps. Pas des courbes : 1 minute (1 h) à 1 heure (5 jours).
- **Indicateurs** : visiteurs actifs (personnes différentes vues dans les 5 dernières minutes), recherches par minute (moyenne des 5 dernières minutes), temps de réponse moyen des recherches et 95e percentile, recherches (finales, d'après le journal ; à défaut, requêtes à chaque frappe) et part servie par le cache, pages vues et clics, requêtes de l'API, charge du processeur (charge rapportée au nombre de cœurs) et mémoire, erreurs serveur et requêtes refusées (robots, limites), recherches sans résultat. Comparaison avec la période précédente de même durée (sauf 5 jours).
- **Graphiques** : activité (recherches, pages vues, clics), visiteurs actifs, temps de réponse (avec le seuil de 500 ms), charge du serveur, répartition des temps de réponse, **carte heure par heure des 5 derniers jours**. Survolez une courbe pour le détail ; « Voir les données sous forme de tableau » liste toutes les valeurs.

Fonctionnement : chaque appel de l'API publique (le widget) incrémente des compteurs de la minute **en mémoire** (APCu ou Redis, aucune écriture en base par requête). Une fois par minute, ils sont versés dans `metrics_minute` ; la page ajoute la minute en cours, lue en mémoire. La charge de la machine est relevée chaque minute (`schedule:run`). Rien de personnel n'est conservé : les visiteurs actifs utilisent l'empreinte du jour de la mesure d'audience (sans adresse IP), effacée au bout de 15 minutes ; les compteurs sont gardés **6 jours**. Les recherches tapées dans l'extranet (aperçu interne, démo) ne sont pas comptées. Avec plusieurs serveurs web et APCu, chaque serveur verse ses propres compteurs (les totaux restent justes) ; la charge affichée est celle d'un des serveurs.

## Détails techniques

- **Collecte** : `src/Logging/Metrics.php`. `Metrics::record()` est appelé à la fin de chaque appel de l'API publique (`ApiController::handle`), après l'envoi de la réponse ; il incrémente des compteurs `mt:<projet>:<minute>:<compteur>` dans le stockage clé/valeur (APCu, Redis, ou MySQL en repli) : **aucune écriture en base par requête**.
- **Versement** : une fois par minute, le premier appel qui suit verse les minutes écoulées dans `metrics_minute` (verrou pour qu'un seul processus le fasse) ; la page de supervision lit aussi la minute en cours en mémoire.
- **Charge** : `server_minute` (charge moyenne sur 1 minute rapportée au nombre de cœurs, mémoire utilisée), relevée par `schedule:run` chaque minute et à chaque versement.
- **Visiteurs actifs** : `live_visitors` (empreinte du jour de la mesure d'audience, sans IP, une écriture par visiteur et par minute au plus, effacée après 15 minutes).
- **Temps de réponse** : durée jusqu'à l'envoi de la réponse ; répartition par tranches (moins de 50 ms, 50–100, 100–200, 200–500, 0,5–1 s, plus d'1 s) ; le 95e percentile est estimé dans la tranche.
- **Page** : `src/Admin/MonitorController.php`, `templates/admin/monitor.php`, graphiques SVG dessinés par `public/assets/monitor.js` (sans bibliothèque).
