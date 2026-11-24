# Présentation et architecture

DeepFind remplace la recherche d'origine du site Magento par une recherche instantanée (résultats pendant la frappe, tolérance aux fautes, filtres), et ajoute des outils de mise en avant (bannières, popins, carrousels) et de suivi (statistiques, audience, temps réel).

## Fonctionnement général

```
 Magento 2 ──flux XML produits (complet + delta)──┐
 Blog (Magefan) ──flux XML articles───────────────┤
                                                  ▼
                       bin/console import:run (planificateur / bouton extranet)
                                                  │  lecture en flux, regroupement parents/enfants,
                                                  │  calcul prix « dès », stock, promo, score métier
                                                  ▼
                                          Meilisearch (127.0.0.1:7700, jamais exposé)
                                                  ▲
 Navigateur ─ widget JS (clé publique) ─► /api/v1/search (PHP) ─┘   + MySQL : configuration, règles, journaux
                                           • contrôle clé + domaine
                                           • anti-robots (APCu/Redis)
                                           • règles (résultats personnalisés, boosts, redirections)
                                           • ne renvoie QUE les champs d'affichage (jamais la marge)
```

- **Meilisearch** fait la recherche elle-même (index en mémoire, réponses en quelques millisecondes).
- **PHP / Apache / MySQL** servent l'API, l'extranet, les imports et les journaux.
- **Aucune dépendance Composer** : le code n'utilise que PHP et ses extensions standard.
- Un **import complet** construit un index temporaire, vérifie qu'il n'a pas perdu trop de produits, puis bascule instantanément. Les visiteurs ne voient aucune coupure, et un flux vide ou cassé ne vide jamais la recherche.

## Les briques du code

| Dossier / fichier | Rôle |
|---|---|
| `public/index.php` | Point d'entrée unique : `/api/*` (widget), `/admin/*` (extranet), `/wiki` (cette aide) |
| `public/widget/deepfind.js`, `deepfind.css` | Le **widget** chargé par le site : fenêtre de recherche, popins, carrousels, mesures |
| `src/Http/ApiController.php` | API publique appelée par le widget |
| `src/Search/` | Recherche : validation des paramètres, appel à Meilisearch, pertinence, filtres |
| `src/Import/` | Lecture des flux XML, mapping, construction des produits, imports complet / delta |
| `src/Rules/` | Règles : synonymes, boosts, résultats personnalisés, redirections, bannières, règles selon la page, popins, carrousels |
| `src/Logging/` | Journal des recherches, audience, supervision (temps réel) |
| `src/Widget/` | Réglages d'affichage du widget (layers, filtres, tris), textes et variables |
| `src/Admin/`, `templates/admin/`, `public/assets/` | Extranet : contrôleurs, gabarits, scripts et styles |
| `src/Wiki/`, `docs/wiki/` | Cette aide |
| `database/schema.sql`, `src/Migrations.php` | Structure de la base (nouvelle installation, mises à jour) |
| `tests/` | Tests automatiques (serveur et navigateur) |

## Adresses de l'API publique

Toutes demandent la **clé publique** (`key=pk_…`) et ne répondent qu'aux domaines autorisés pour cette clé (voir [Intégrer le widget](integrer-le-widget)).

| Adresse | Rôle |
|---|---|
| `GET /api/v1/config` | Configuration du widget : projet actif, layer, filtres, tris, textes et variables, popins en cours, présence de carrousels |
| `GET /api/v1/search` | Recherche (produits, filtres, articles, bannière, redirection) |
| `POST /api/v1/click` | Clic sur un résultat (statistiques, score métier) |
| `POST /api/v1/visit` | Page vue (mesure d'audience anonyme) |
| `POST /api/v1/popin` | Popin affichée, cliquée ou fermée |
| `GET /api/v1/carousels` | Carrousels de la page, avec leurs produits |
| `POST /api/v1/carousel` | Carrousel vu, produits vus, clic |
| `GET /api/v1/health` | État du moteur (supervision externe) |

La **marge** et le **score interne** ne sortent jamais de l'API publique (liste blanche des champs, vérifiée par les tests).
