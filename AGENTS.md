# AGENTS.md — guide pour les développeurs et les agents IA

Ce fichier décrit comment le projet est construit et comment y contribuer sans casser l'existant.
Pour l'installation et l'usage métier, voir `README.md`.

## Objectif du projet

Recherche instantanée (type Doofinder) pour un site Magento 2.4.7 : flux XML produits et blog,
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
src/Rules/RulesRepository.php  synonymes, produits désactivés (query_rules « * »), redirections, import CSV, version des règles (invalide les caches)
src/Rules/Boosts.php           clics 30 j (et anciens product_boosts, convertis en règles de boost) → ProductBuilder::applySignals()
src/Rules/BoostRules.php       règles de boost (boost_rules) : termes exacts/larges + conditions → coefficient appliqué par SearchService::rerank()
src/Rules/CustomResults.php    résultats personnalisés (custom_results) : termes, période, inclus/exclus (SKU ou règles → filtres Meili),
                               modes first/only appliqués par SearchService ; suivi : search_logs.custom_ids, click_logs.custom_result_id
public/assets/conditions.js    sélecteur de conditions partagé (règles de boost, résultats personnalisés)
src/Rules/Banners.php          bannières (banners) : par termes ou par défaut, image ordi/mobile ou HTML ; suivi search_logs.banner_id + click_logs (doc_type banner)
src/Rules/Popins.php           popins (popins) : pages (toutes / accueil / adresses PageRules::URL_OPS, adresses exclues), période, appareil,
                               délai, fréquence ; liste envoyée avec /api/v1/config (Popins::forWidget), choix de la page DANS le widget ;
                               fermeture mémorisée dans le navigateur (clé df-popin:<clé>:<langue>:<id>:<rev>) ; suivi popin_stats (POST /api/v1/popin)
src/Rules/Carousels.php        carrousels (carousels) : pages (comme les popins), emplacement (sélecteur CSS + position), produits épinglés
                               puis source auto (conditions PageRules::filter + tri) / manual / similar (produit de la page par url_path ou SKU) /
                               personal (historique envoyé par le widget, jamais stocké ; réglage carousel_personal off|consent|always) ;
                               apprentissage = reclassement par CTR lissé (carousel_product_stats) ; GET /api/v1/carousels, POST /api/v1/carousel
src/Rules/Redirections.php     redirections (redirections) : termes, période, automatique ou lien proposé ; ancienne table redirects convertie
src/Security/HtmlSanitizer.php HTML des bannières : balises / attributs autorisés, pas de script ni javascript: (le widget refiltre aussi)
public/assets/editor-tools.js  termes + période (calendrier) des pages d'édition ; gabarit templates/admin/_terms_period.php ;
                               DFImageSlots : images téléversées des bannières et popins (gabarit templates/admin/_media_fields.php)
public/assets/live-preview.js  aperçu direct (popins, carrousels) : cadre /admin/live-preview (seule page de l'extranet autorisée en cadre,
                               même origine) où le widget en mode data-render="1" dessine l'élément reçu par postMessage (DeepFind.render)
                               DFPages : pages concernées / exclues (gabarit templates/admin/_pages_fields.php) des popins et carrousels
                               DFWysiwyg : éditeur visuel (contenteditable + execCommand) du contenu HTML, relié au champ html ;
                               refiltre le HTML chargé ou collé (mêmes balises que HtmlSanitizer, qui reste la référence)
src/Search/Relevance.php       réglages de pertinence : ordre des champs, mode de correspondance (all puis repli « approchant »),
                               seuil rankingScoreThreshold, position de biz_score et de « attribute » dans rankingRules
src/Security/                  ClientIp (HMAC, masquage, proxies de confiance), BotGuard (seuils/blocages), ApiKeys (domaines)
src/Logging/Metrics.php        supervision : compteurs par minute dans le KvStore (incr / incrBy, jamais d'écriture en base par requête),
                               versés dans metrics_minute une fois par minute (maybeFlush), server_minute (charge), live_visitors ;
                               Metrics::record() appelé par ApiController::handle (finally), lu par Admin\MonitorController (monitor.js)
src/Logging/SearchLogger.php   journal des recherches (is_final via sid), clics, purge
src/Http/ApiController.php     /api/v1/config (projet actif ? + layer actif, filtres, tris, popins), /api/v1/search, /api/v1/click,
                               /api/v1/visit, /api/v1/popin, /api/v1/carousels, /api/v1/carousel, /api/v1/health
src/Widget/WidgetConfig.php    page Intégration : layers (table layers, un seul actif : below | fullscreen | floating | embedded),
                               filtres (ordre, nom, visibilité : widget_filters_<lang>) et tris (widget_sorts_<lang>) envoyés au widget
src/Widget/WidgetTexts.php     Paramètres › Variables : textes du widget par langue (DEFAULTS = copie de la table T de deepfind.js,
                               un test vérifie qu'elles sont identiques ; ajoutez tout nouveau texte aux deux et à GROUPS),
                               variables personnalisées {{code}} (custom_vars, custom_vars_<langue>) ; envoyés par /api/v1/config
src/Admin/                     extranet : App (routes + CSRF), Auth (jeton unique), *Controller (dont MappingController = mapping visuel), View
src/Project.php                projet courant (session extranet / clé publique API / source importée) ; préfixe d'index par projet ;
                               projet désactivé (projects.active = 0) → le widget ne prend pas la main sur le site
src/ConfigTransfer.php         export, résumé, copie de configuration entre projets, sauvegardes (config_snapshots), restauration
src/Migrations.php             ajout des colonnes manquantes sur une base existante (appelé par `install`) ; crée le projet « prod »
src/CacheVersion.php           version des données (fichier var/cache/data_version) incluse dans TOUTES les clés de cache :
                               CacheVersion::bump() après import / règle / paramètre ⇒ caches obsolètes partout, immédiatement
templates/admin/               gabarits PHP (échapper TOUT avec e())
src/Push/WebPush.php           notifications : chiffrement RFC 8291 (aes128gcm), signature VAPID RFC 8292, envoi par lots (cURL)
src/Push/Push.php              notifications : réglages de l'invitation, abonnés, messages, envoi programmé, statistiques
src/Rules/Recos.php            recommandations : paires achetés / regardés ensemble (reco_pairs), tendances
src/Rules/Segments.php         ciblage selon la provenance (canal, UTM), mémorisée pour la journée sous l'empreinte du jour
src/Rules/Campaigns.php        campagnes de produits à pousser et leurs résultats (audience_products, audience_attrib)
src/Logging/Tracker.php        événements d'un visiteur reçus par l'API (audience, parcours d'achat, parcours), après l'envoi de la réponse
src/Logging/Journeys.php       parcours (Analyse › Parcours) : empreinte du jour, déclencheur, masquage, conservation RETENTION_HOURS
src/Wiki/                      aide (menu Aide, /admin/wiki ; /wiki redirige) : Wiki.php (arborescence de docs/wiki), Markdown.php (Markdown → HTML, tout échappé)
docs/wiki/                     pages de l'aide : un dossier = une catégorie (_index.md = titre + intro), un .md = une page, préfixe 01- = ordre ;
                               captures : docs/screenshots ou docs/wiki/_images, écrites ![texte](nom.png)
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
6. **Widget** : créez les éléments avec `textContent` ; les URL passent par `safeUrl()` (http/https uniquement). Pas de `innerHTML` avec des données du flux. Seule exception : le HTML des bannières et des popins, nettoyé à l'enregistrement (`HtmlSanitizer`) puis refiltré balise par balise par le widget (`cleanNode`, via `fillMedia`).
7. **Les valeurs de filtre Meilisearch** passent toujours par `Search\Filter` ; les codes d'attributs, par `Mapper::code()`.
8. **Les secrets** (`.env`, clés Meilisearch, jeton d'administration) ne vont ni dans le dépôt, ni dans les journaux, ni dans les réponses.

10. **Projets : toute requête sur sources, synonyms, query_rules, redirects, product_boosts, search_logs, click_logs, api_keys, popins, popin_stats, carousels, carousel_stats, carousel_product_stats, metrics_minute, live_visitors
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
- Mettez à jour **l'aide** (`docs/wiki/`) dans la même modification : la page de la fonction touchée (écran, réglages, détails techniques), et le tableau des versions du widget (`01-demarrage/03-mettre-a-jour.md`) si `WIDGET_VERSION` change. Nouveau menu de l'extranet : décrivez-le dans `01-demarrage/05-utiliser-l-extranet.md`. Des tests vérifient que tous les liens internes de l'aide existent, que chaque menu y est décrit et que la version du widget figure dans le tableau des versions.
