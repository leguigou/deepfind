# Synonymes et alias

*Optimisations › Synonymes & alias* : une ligne = des mots qui donnent les mêmes résultats, séparés par des virgules (ex. `ecoflow, ecoflau, ecauflo`).

- Option **sens unique** : le premier mot mène aux suivants, pas l'inverse.
- Recherche et modification directement dans le tableau.
- Import CSV (`samples/synonymes.csv`) et envoi au moteur (bouton de synchronisation, ou `php bin/console rules:sync --lang=fr`).

Les synonymes sont appliqués par Meilisearch : ils valent pour toutes les recherches, avec la même tolérance aux fautes de frappe.
