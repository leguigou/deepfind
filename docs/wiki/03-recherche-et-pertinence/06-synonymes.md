# Synonymes et alias

*Optimisations › Synonymes & alias* : une ligne = des mots qui donnent les mêmes résultats, séparés par des virgules (ex. `ecoflow, ecoflau, ecauflo`).

- **Équivalents** (par défaut) : chaque mot mène à tous les autres (`aviron, rame, pagaie`).
- **Sens unique** : le premier mot mène aux suivants, pas l'inverse. `canoë → kayak` : taper « canoë » trouve aussi les kayaks ; taper « kayak » ne trouve pas les canoës.
- Recherche et modification directement dans le tableau, import CSV (`samples/synonymes.csv`).

## Dans quel ordre sortent les résultats ?

Un synonyme **ajoute** des produits, il ne remplace pas le mot tapé. Le moteur classe d'abord les produits qui contiennent **le mot tapé lui-même**, puis ceux trouvés **grâce au synonyme**.

Exemple : avec `canoë → kayak`, une recherche « canoë » montre d'abord les produits dont le nom, la catégorie ou la description contient « canoë », puis les kayaks. S'il y a beaucoup de produits « canoë », les kayaks sont plus bas dans la liste (ou sur les pages suivantes).

Pour faire passer certains produits devant, utilisez un [résultat personnalisé](resultats-personnalises) sur la recherche « canoë » ou une [règle de boost](regles-de-boost). Pour envoyer directement vers une page, utilisez une [redirection](redirections).

## Vérifier un synonyme

Dans l'**Aperçu interne**, cherchez le mot : un encadré indique les **synonymes appliqués** à cette recherche (ex. « canoë » → kayak).

- Message bleu : le synonyme est actif dans le moteur ; descendez dans la liste pour voir les produits trouvés grâce à lui.
- Message orange « pas encore pris en compte par le moteur » : après chaque changement, le moteur réindexe le catalogue (de quelques secondes à quelques minutes selon sa taille). Rechargez la page. Si le message reste, cliquez sur **« Synchroniser avec Meilisearch »** en bas de la page Synonymes & alias, ou lancez `php bin/console rules:sync --lang=fr`.

## Détails techniques

- Stockage : table `synonyms` (par projet et par langue) ; `type` = `equivalent` ou `alias` (sens unique), `term` = premier mot, `synonyms` = autres mots séparés par `|`.
- Envoi au moteur : `RulesRepository::meiliSynonyms()` construit le réglage `synonyms` de Meilisearch (index produits et articles), à chaque ajout, modification ou suppression, et à chaque import complet.
- **Accents** : chaque mot est aussi envoyé sans accent (`canoë` et `canoe` → `kayak`) : le synonyme marche que le visiteur tape l'accent ou non, quelle que soit la version de Meilisearch installée.
- Majuscules ignorées ; tolérance aux fautes de frappe identique au reste de la recherche.
- Un changement de synonymes est une mise à jour des réglages de l'index : Meilisearch la traite en tâche de fond. L'Aperçu interne compare les synonymes de la recherche avec ceux que le moteur a réellement (`GET /indexes/<index>/settings`).
