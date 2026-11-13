# AGENTS.md — guide pour les développeurs et les agents IA

Ce fichier décrit comment le projet est construit et comment y contribuer sans casser l'existant.
Pour l'installation et l'usage métier, voir `README.md`.

## Objectif du projet

Recherche instantanée (type Doofinder) pour le site Magento 2.4.7 de Cabesto : flux XML produits et blog,
indexation dans Meilisearch, API PHP appelée par un widget JavaScript, extranet de gestion. Langue prioritaire : FR, multi-langue prévu.

## Pile technique et contraintes

- **PHP ≥ 8.2 (cible 8.5)**, `declare(strict_types=1)` partout, **aucune dépendance Composer**. L'autoload PSR-4 maison se trouve dans `src/bootstrap.php`, avec l'espace de noms `DeepFind\` → `src/`.
- **Extensions PHP** : pdo_mysql, curl, xmlreader/dom/simplexml, mbstring, zlib ; apcu (conseillée) ; redis (facultative).
- **Base** : MySQL 8 / MariaDB 10.6+. Schéma idempotent dans `database/schema.sql` (uniquement `CREATE TABLE IF NOT EXISTS`). Pour toute évolution, ajoutez des instructions rejouables ; `php bin/console install` l'applique.
- **Meilisearch v1.x** (≥ 1.10 pour `localizedAttributes`), appelé via `src/Meili/MeiliClient.php` (cURL, connexion réutilisée). Il n'est jamais exposé au navigateur.
- **Web** : Apache + PHP-FPM, `FallbackResource /index.php`. Contrôleur frontal : `public/index.php`.
- **Compatibilité PHP 8.5** : n'utilisez pas `curl_close()`, `xml_parser_free()`, `$http_response_header`, l'opérateur backtick, ni les casts non canoniques (`(boolean)`, `(integer)`…). Passez toujours un paramètre `escape` explicite à `str_getcsv`/`fputcsv`.

## Carte du code

```
public/index.php               routeur : /api/* → Http\ApiController, /admin/* → Admin\App
public/widget/                 widget JS + CSS (vanilla, sans dépendance, textContent uniquement → pas d'injection HTML)
public/assets/                 CSS/JS de l'extranet (thème sombre/clair via [data-theme], menu repliable .menu-collapsed)
src/Config.php, Db.php         .env et PDO (requêtes préparées)
src/Settings.php               paramètres modifiables dans l'extranet (table settings, cache 60 s côté web)
src/Store/                     clé/valeur à expiration : APCu | Redis | MySQL (repli) — compteurs anti-robots, caches
src/Import/
  FeedFetcher.php              téléchargement (streaming disque) ou fichier local limité à FEED_LOCAL_DIRS
  XmlItemReader.php            XMLReader en flux (mémoire constante), .gz, XXE bloqué, XML tronqué ⇒ exception
  Mapper.php                   mapping JSON → champs internes (chemins simples ou XPath, transformations)
  ProductBuilder.php           document final : agrégation parent/enfants, prix « dès », stock, promo, biz_score
  Importer.php                 complet (index _tmp + contrôle de baisse + swap atomique) / delta (fetch + recalcul)
  FeedInspector.php            analyse d'un flux pour le mapping visuel (chemins, groupes répétés, attributs par code, suggestions)
  FieldCatalog.php             libellés, aides, champs obligatoires et noms usuels des champs internes
  Cron.php, Scheduler.php      expressions cron 5 champs ; planificateur (schedule:run chaque minute → file d'attente)
  Progress.php                 avancement lisible d'un import (étape, %, temps restant) ; Importer::progress() écrit import_runs ≤ 1×/s
                               type d'exécution « scores » = Importer::refreshScores() (relit l'index, recalcule biz_score, sans flux)
src/Search/
  SearchRequest.php            validation des paramètres publics
  SearchService.php            UN appel multi-search : principal + injectés (pin/boost) + SKU exact + facettes disjonctives + blog
  IndexSettings.php            réglages des index (searchable, filterable, rankingRules, typo, synonymes)
  Filter.php                   échappement des valeurs de filtre Meilisearch
src/Rules/RulesRepository.php  synonymes, règles, redirections, import CSV, version des règles (invalide les caches)
src/Rules/Boosts.php           clics 30 j (et anciens product_boosts, convertis en règles de boost) → ProductBuilder::applySignals()
src/Rules/BoostRules.php       règles de boost (boost_rules) : termes exacts/larges + conditions → coefficient appliqué par SearchService::rerank()
src/Search/Relevance.php       réglages de pertinence : ordre des champs, mode de correspondance (all puis repli « approchant »),
                               seuil rankingScoreThreshold, position de biz_score et de « attribute » dans rankingRules
src/Security/                  ClientIp (HMAC, masquage, proxies de confiance), BotGuard (seuils/blocages), ApiKeys (domaines)
src/Logging/SearchLogger.php   journal des recherches (is_final via sid), clics, purge
src/Http/ApiController.php     /api/v1/search, /api/v1/click, /api/v1/health
src/Admin/                     extranet : App (routes + CSRF), Auth (jeton unique), *Controller (dont MappingController = mapping visuel), View
src/Project.php                projet courant (session extranet / clé publique API / source importée) ; préfixe d'index par projet
src/ConfigTransfer.php         export, résumé, copie de configuration entre projets, sauvegardes (config_snapshots), restauration
src/Migrations.php             ajout des colonnes manquantes sur une base existante (appelé par `install`) ; crée le projet « prod »
src/CacheVersion.php           version des données (fichier var/cache/data_version) incluse dans TOUTES les clés de cache :
                               CacheVersion::bump() après import / règle / paramètre ⇒ caches obsolètes partout, immédiatement
templates/admin/               gabarits PHP (échapper TOUT avec e())
bin/console                    commandes CLI (install, check, import:run, queue:work, rules:*, logs:purge, key:create)
deploy/                        vhost Apache, service systemd Meilisearch, Supervisor, crontab
samples/                       flux et CSV FICTIFS + generate.php
tests/                         run.sh (unitaires + intégration), run.php, fake-meili.php (FAUX moteur), e2e.cjs (Playwright)
```

## Règles à ne pas enfreindre

1. **La marge (`margin_rate`) et le score interne ne doivent jamais sortir de l'API publique.** La liste blanche est `SearchService::PUBLIC_FIELDS`, et un test d'intégration le vérifie (`API : marge jamais exposée`). Les champs internes ne sont visibles que dans l'aperçu de l'extranet (`internal = true`).
2. **Un import complet ne doit jamais vider ni dégrader l'index en ligne** : construction dans `<index>_tmp`, contrôle `max_drop_percent`, puis `swap`. Toute erreur XML doit lever une exception avant la bascule.
3. **Pas d'écriture en base pour chaque requête de robot** : `BotGuard` passe par le `KvStore`. Les événements sont limités à une ligne par IP, par motif et par minute.
4. **Une IP n'est jamais stockée en clair** : uniquement `ip_hash` (HMAC avec `APP_SECRET`) et `ip_masked`.
5. **Extranet** : chaque POST vérifie le jeton CSRF (`App::handle`), chaque sortie passe par `e()`, et la CSP interdit le JavaScript inline. N'ajoutez pas d'attribut `onclick` ni de `<script>` inline : passez par `public/assets/admin.js` et des attributs `data-*`.
6. **Widget** : créez les éléments avec `textContent` ; les URL passent par `safeUrl()` (http/https uniquement). Pas de `innerHTML` avec des données du flux.
7. **Les valeurs de filtre Meilisearch** passent toujours par `Search\Filter` ; les codes d'attributs, par `Mapper::code()`.
8. **Les secrets** (`.env`, clés Meilisearch, jeton d'administration) ne vont ni dans le dépôt, ni dans les journaux, ni dans les réponses.

10. **Projets : toute requête sur sources, synonyms, query_rules, redirects, product_boosts, search_logs, click_logs, api_keys
    filtre ou renseigne `project_id = Project::id()`** (les import_runs passent par leur source). Les paramètres propres à un projet
    sont dans `project_settings` (via `Settings`), les communs (anti-robots, `Migrations::GLOBAL_SETTINGS`) dans `settings`.
    Toute clé de cache dépendant des données inclut `Project::id()`. Les tests d'intégration vérifient l'isolation entre projets.
9. **Toute modification de données visibles dans la recherche** (import, règle, synonyme, paramètre) doit appeler `CacheVersion::bump()` (directement, ou via `RulesRepository::bumpVersion()` ou `Settings::set()`). Toute nouvelle clé de cache dont le contenu dépend des données doit inclure `CacheVersion::get()`.

## Conventions

- Textes de l'interface et messages : **français**. Commentaires : français, courts, et seulement là où ils expliquent un choix.
- Classes `final`, méthodes statiques pour les dépôts simples, pas de framework.
- Nouvelle page d'extranet : ajoutez la route dans `Admin\App::ROUTES`, une méthode de contrôleur, un gabarit `templates/admin/x.php` et une entrée dans le menu `_layout.php`. Utilisez les classes CSS existantes (`card`, `table`, `btn`, `badge-*`, `flash-*`, `grid2`) et les variables de thème (`--surface`, `--text`, `--accent`…) : les deux thèmes fonctionnent alors sans travail supplémentaire.
- Évolution du schéma : modifiez `database/schema.sql` (nouvelles installations) **et** ajoutez la colonne dans `Migrations::COLUMNS` (installations existantes).
- Nouveau champ interne : ajoutez aussi son libellé, son aide et ses noms usuels dans `FieldCatalog`, pour le mapping visuel.
- Logo : `logo_svg()` dans `src/Admin/helpers.php` ; favicons dans `public/` (`favicon.svg`, `favicon.ico`, `favicon-32.png`, `apple-touch-icon.png`). Régénérez les PNG et l'ICO si le SVG change.
- Nouveau paramètre : ajoutez-le à `Settings::DEFAULTS` et `Settings::LABELS`, puis à `SettingsController::GROUPS`.
- Nouveau champ produit : ajoutez-le à `Mapper::PRODUCT_FIELDS`, puis à `ProductBuilder::build()`. Pour qu'il soit cherchable ou filtrable, ajoutez-le aussi à `IndexSettings::products()`. Pour l'afficher sur le site, ajoutez-le à `SearchService::PUBLIC_FIELDS`, en vérifiant qu'il n'est pas confidentiel.

## Commandes utiles

Vérifier l'environnement :

```bash
php bin/console check
```

Lancer les tests (base de TEST uniquement, voir README §10) :

```bash
bash tests/run.sh
```

Vérifier la syntaxe de tous les fichiers PHP :

```bash
for f in $(find src bin public tests templates -name '*.php') bin/console; do php -l "$f" | grep -v 'No syntax errors'; done
```

Régénérer les flux d'exemple :

```bash
php samples/generate.php http://localhost:8080/assets/demo
```

Lancer un import d'exemple en local :

```bash
php bin/console import:run --source=produits_fr
```

## Avant de livrer une modification

- `bash tests/run.sh` passe, sans avertissement PHP dans `tests/_tmp/web.log`. Le script échoue s'il en trouve.
- Si l'extranet ou le widget a changé : `node tests/e2e.cjs` (procédure dans le README), et vérifiez les captures dans les deux thèmes.
- Testez sur la VM de **recette** avec le vrai Meilisearch avant la production. Le faux moteur `tests/fake-meili.php` ne reproduit pas la pertinence réelle.
- Mettez à jour `README.md` (installation, formats, paramètres) et ce fichier si l'architecture évolue.
