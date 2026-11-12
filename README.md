# DeepFind

Moteur de recherche instantanée pour le site Cabesto, dans l'esprit de Doofinder :

- **Recherche** : tolérance aux fautes de frappe, synonymes et alias, recherche par SKU (parent ou enfant) et EAN.
- **Règles de résultats** : produits épinglés, remontés ou masqués, redirections.
- **Filtres** : marques, catégories, attributs, stock, promotion.
- **Panneau de résultats** : chargement au défilement, articles de blog dans la colonne de gauche.
- **Extranet de gestion** : flux, mapping, imports, règles, statistiques, journal des recherches, robots et blocages.
- **Protection anti-robots** : seuils d'appels par IP et blocage automatique.

![Tableau de bord (thème sombre)](docs/screenshots/01-dashboard-sombre.png)

| Widget sur le site | Aperçu interne (marge, score) | Thème clair, menu replié |
|---|---|---|
| ![](docs/screenshots/06-widget.png) | ![](docs/screenshots/02-apercu-interne.png) | ![](docs/screenshots/04-stats-clair-menu-replie.png) |

> ⚠️ Les flux fournis dans `samples/` sont **fictifs** : produits, prix, stocks et marges sont inventés. Les noms de marques ne servent qu'à illustrer.
> Testez toute installation ou mise à jour sur un serveur de **recette** avant la production.

---

## Sommaire

1. [Comment ça marche](#1-comment-ça-marche)
2. [Logiciels à installer](#2-logiciels-à-installer)
3. [Installation pas à pas](#3-installation-pas-à-pas)
4. [Intégrer le widget sur le site Magento](#4-intégrer-le-widget-sur-le-site-magento)
5. [Formats des flux et mapping](#5-formats-des-flux-et-mapping)
6. [Utiliser l'extranet](#6-utiliser-lextranet)
7. [Anti-robots](#7-anti-robots)
8. [Journaux de recherche et RGPD](#8-journaux-de-recherche-et-rgpd)
9. [Commandes](#9-commandes)
10. [Tests](#10-tests)
11. [Dépannage](#11-dépannage)
12. [Limites connues de la V1](#12-limites-connues-de-la-v1)

---

## 1. Comment ça marche

```
 Magento 2.4.7 ──flux XML produits (complet + delta)──┐
 Magefan Blog ──flux XML articles─────────────────────┤
                                                      ▼
                           bin/console import:run (cron / bouton extranet)
                                                      │  lecture en flux, regroupement parents/enfants,
                                                      │  calcul prix « dès », stock, promo, score métier
                                                      ▼
                                              Meilisearch (127.0.0.1:7700, jamais exposé)
                                                      ▲
 Navigateur ─ widget JS (clé publique) ─► /api/v1/search (PHP) ─┘   + MySQL : config, règles, journaux
                                           • contrôle clé + domaine
                                           • anti-robots (APCu/Redis)
                                           • règles (épingler/masquer/redirection)
                                           • ne renvoie QUE les champs d'affichage (jamais la marge)
```

- **Meilisearch** fait la recherche elle-même (index en mémoire, réponses en quelques millisecondes).
- **PHP / Apache / MySQL**, que vous avez déjà, servent l'API, l'extranet, les imports et les journaux.
- **Aucune dépendance Composer** : le code n'utilise que PHP et ses extensions standard.
- Un **import complet** construit un index temporaire, vérifie qu'il n'a pas perdu trop de produits, puis bascule instantanément. Les visiteurs ne voient aucune coupure, et un flux vide ou cassé ne vide jamais la recherche.

## 2. Logiciels à installer

Sur la VM. Commandes pour Debian/Ubuntu ; adaptez-les à votre distribution.

| Logiciel | Pourquoi | Obligatoire |
|---|---|---|
| PHP **8.2 minimum** (8.5 visé) en **PHP-FPM** | Application | ✅ |
| Extensions PHP : `pdo_mysql`, `curl`, `xml` (xmlreader, dom, simplexml), `mbstring`, `zlib` | Application | ✅ |
| Extension PHP **`apcu`** | Compteurs anti-robots et cache, en mémoire | Fortement conseillé (sinon Redis ou repli MySQL) |
| OPcache activé | Performances PHP | Fortement conseillé |
| MySQL 8 ou MariaDB 10.6+ | Configuration, règles, journaux | ✅ (déjà présent) |
| Apache 2.4 : `proxy_fcgi`, `headers`, `deflate`, `http2`, `ssl` | Serveur web | ✅ (déjà présent) |
| **Meilisearch** 1.x (**1.10 minimum**) | Moteur de recherche | ✅ **à installer** |
| Supervisor | Traiter les imports lancés depuis l'extranet | Conseillé (sinon une ligne cron) |
| Redis + `php-redis` | Uniquement si plusieurs serveurs web | Facultatif |

**Mémoire vive pour environ 70 000 produits :** comptez 8 Go sur la VM, dont 4 Go pour Meilisearch. Prévoyez 40 Go de disque SSD.

> PHP 8.5 : sur Debian/Ubuntu, les paquets viennent en général du dépôt de Ondřej Surý (`packages.sury.org` / PPA `ondrej/php`).
> Vérifiez la disponibilité de `php8.5-apcu` au moment de l'installation. S'il n'existe pas encore, mettez `RATE_STORE=redis` (avec `php8.5-redis`) ou `RATE_STORE=mysql` dans `.env`.
> Le code a été testé sous **PHP 8.3** (pas d'environnement 8.5 disponible pour les tests). Il n'utilise aucune fonction dépréciée en 8.5 à notre connaissance. Lancez `php bin/console check`, puis la suite de tests, sur votre VM.

## 3. Installation pas à pas

Toutes les commandes sont à lancer en root (ou avec `sudo`) **sur le serveur de recette d'abord**.

### 3.1 PHP et extensions

```bash
apt install php8.5-fpm php8.5-cli php8.5-mysql php8.5-curl php8.5-xml php8.5-mbstring php8.5-apcu supervisor
```

Dans `/etc/php/8.5/fpm/conf.d/` (fichier `99-deepfind.ini`), ajoutez :

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.validate_timestamps=1
apc.enabled=1
apc.shm_size=64M
memory_limit=512M
```

### 3.2 Meilisearch

1. Téléchargez le binaire Linux de la **dernière version stable 1.x** depuis <https://github.com/meilisearch/meilisearch/releases>. Notez le numéro de version pour pouvoir le reproduire.
2. Installez-le :

```bash
install -m 755 meilisearch-linux-amd64 /usr/local/bin/meilisearch
```

```bash
useradd --system --home /var/lib/meilisearch --shell /usr/sbin/nologin meilisearch
```

```bash
mkdir -p /var/lib/meilisearch && chown meilisearch: /var/lib/meilisearch
```

3. Créez `/etc/meilisearch.env` avec `MEILI_MASTER_KEY=…`. Utilisez une valeur longue et aléatoire (`openssl rand -hex 32`), puis :

```bash
chmod 600 /etc/meilisearch.env && chown meilisearch: /etc/meilisearch.env
```

4. Copiez `deploy/meilisearch.service` dans `/etc/systemd/system/`, puis :

```bash
systemctl daemon-reload && systemctl enable --now meilisearch
```

5. Vérifiez : `curl http://127.0.0.1:7700/health` doit répondre `{"status":"available"}`.

> Meilisearch n'écoute que sur `127.0.0.1` : il n'est **jamais** accessible depuis Internet. Ne le changez pas.

### 3.3 Base de données

```sql
CREATE DATABASE deepfind CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'deepfind'@'localhost' IDENTIFIED BY 'MOT_DE_PASSE_FORT';
GRANT ALL ON deepfind.* TO 'deepfind'@'localhost';
```

### 3.4 Application

```bash
git clone <URL_DU_DEPOT> /var/www/deepfind
```

```bash
cd /var/www/deepfind && cp .env.example .env && chmod 640 .env
```

```bash
php bin/console admin:token
```

Copiez les valeurs `ADMIN_TOKEN` et `APP_SECRET` affichées dans `.env`, puis complétez `DB_*`, `MEILI_MASTER_KEY` (la même que l'étape 3.2) et `APP_URL`.

```bash
chown -R www-data: var && chgrp www-data .env
```

```bash
php bin/console check
```

```bash
php bin/console install
```

### 3.5 Apache

Copiez `deploy/apache-vhost.conf` dans `/etc/apache2/sites-available/deepfind.conf` et adaptez le nom de domaine et les certificats. Ensuite :

```bash
a2enmod proxy_fcgi setenvif headers deflate http2 ssl && a2enconf php8.5-fpm
```

```bash
a2ensite deepfind && apachectl configtest && systemctl reload apache2
```

Conseillé : limitez `/admin` aux IP du siège ou du VPN (bloc commenté dans le fichier).

### 3.6 Worker et tâches planifiées

```bash
cp deploy/supervisor-worker.conf /etc/supervisor/conf.d/deepfind-worker.conf && supervisorctl reread && supervisorctl update
```

Ajoutez ensuite les lignes de `deploy/crontab` dans la crontab de `www-data` (`crontab -u www-data -e`). Adaptez les heures à celles de génération des flux Magento.

### 3.7 Premier import

1. Connectez-vous à `https://search.cabesto.com/admin` avec le jeton `ADMIN_TOKEN`.
2. **Flux & mapping** : remplacez l'URL d'exemple de `produits_fr` par l'URL de votre flux Magento, puis faites de même pour `blog_fr`.
3. **Tester** : vérifiez que le mapping produit le bon document, sans rien indexer.
4. **Import complet**, puis suivez l'avancement dans **Imports**.
5. **Clés & intégration** : créez une clé pour `cabesto.com`, puis testez avec **Démo du widget**.

## 4. Intégrer le widget sur le site Magento

Ajoutez ce code avant `</body>`, par exemple dans *Contenu › Design › Configuration › HTML Head › Scripts et feuilles de style* ou dans un bloc du thème :

```html
<script src="https://search.cabesto.com/widget/deepfind.js?v=1" defer
  data-key="pk_VOTRE_CLE_PUBLIQUE"
  data-lang="fr"
  data-input="#search, input[name=q]"
  data-anchor="header.page-header"
  data-results-url="https://www.cabesto.com/catalogsearch/result/?q="></script>
```

- Le widget s'accroche au champ de recherche existant et ouvre le panneau sous l'en-tête.
- Il charge ses styles tout seul (`deepfind.css`). Les couleurs se changent via les variables `--df-*` du fichier CSS.
- **Fonctions** : attente de 180 ms après la frappe, annulation des requêtes dépassées, cache local, chargement au défilement, filtres marques/catégories/attributs, case « En stock », case « En promotion », tri par prix ou nouveauté, articles de blog, touche Entrée (redirection ou page « Tout voir »), touche Échap, version mobile avec bouton « Filtres ».
- **Statistiques de clics** : chaque clic est mesuré (`sendBeacon`), sans cookie. Un identifiant aléatoire est créé à chaque chargement de page.
- **Multilingue** : `data-lang="en"` et une clé autorisée pour `en`. Chaque langue a son propre index et sa propre source de flux.
- Après une mise à jour du widget, changez `?v=1` en `?v=2` pour forcer le rechargement par les navigateurs.

## 5. Formats des flux et mapping

Deux options pour les flux :

- **Le format DeepFind (recommandé)** : le mapping par défaut fonctionne sans modification. Exemples complets : `samples/products_fr.xml`, `samples/products_fr_delta.xml`, `samples/blog_fr.xml`.
- **Votre propre format** : vous adaptez le mapping JSON de la source. Exemple : `samples/mapping_flat_example.json` pour `samples/products_fr_flat.xml`.

**Règles communes :**
- Encodage UTF-8.
- Textes entre `<![CDATA[ ]]>`.
- Prix avec un point ou une virgule (les deux sont acceptés).
- Dates au format ISO 8601.
- Fichier `.xml` ou `.xml.gz`.
- Flux lu au fil de l'eau : taille illimitée.

### Produits : champs internes

| Champ | Type | Obligatoire | Usage |
|---|---|---|---|
| `id` | texte | conseillé | identifiant (entity_id) |
| `sku` | texte | ✅ | recherche exacte prioritaire |
| `type` | simple/configurable… | | info |
| `parent_id` | texte | flux à plat | rattachement d'un enfant à son parent |
| `enabled` | 0/1 | | 0 = ignoré |
| `name` | texte | ✅ | recherche (poids fort), affichage |
| `brand` | texte | conseillé | filtre « Marques » |
| `url`, `image`, `image_hover` | URL http(s) | url ✅ | affichage (utilisez l'image *cache* redimensionnée de Magento) |
| `short_description`, `description` | texte/HTML | | recherche (poids faible), le HTML est retiré |
| `ean` | texte | | recherche exacte |
| `price` | décimal | ✅ (ou final_price) | prix barré si promo |
| `final_price` | décimal | conseillé | prix affiché (exportez le **prix final** Magento, règles de prix catalogue incluses) |
| `in_stock`, `salable_qty` | 0/1, entier | conseillé | exportez la **quantité vendable MSI** |
| `margin_rate` | 0 à 1 | | **classement uniquement, jamais envoyé au site** |
| `sales_30d` | entier | | popularité |
| `created_at` | date | | badge « Nouveau » et classement |
| `rating`, `reviews_count` | | | affichage |
| `search_keywords` | texte | | mots-clés en plus |
| `manual_boost` | -10 à +10 | | boost éditorial permanent |

**Blocs particuliers :**
- `categories` : une ou plusieurs catégories, avec leur chemin « A > B > C ».
- `attributes` : attributs génériques (code, libellé, `filterable="1"`). Les attributs filtrables deviennent des filtres du widget.
- `variants` : les enfants, avec les champs `id, sku, ean, url, image, price, final_price, in_stock, salable_qty`, plus leurs propres attributs (taille…).

### Parents et enfants Magento

- **`"variant_mode": "nested"`** (recommandé) : les enfants sont dans `<variants>` sous le parent.
- **`"variant_mode": "flat"`** : chaque produit est sur sa propre ligne et les enfants ont un `parent_id`. Le regroupement se fait pendant l'import, dans une table de travail MySQL. L'ordre des lignes n'a pas d'importance. Mettez `"parent_match": "sku"` si `parent_id` contient le SKU du parent.

**Résultat dans tous les cas :**
- Un seul document par parent.
- Prix « dès » = celui de l'enfant en stock le moins cher.
- Le parent est « en stock » si au moins un enfant l'est.
- Les SKU et EAN des enfants sont recherchables : chercher `C4-PREDATOR-40-41` affiche le parent avec la variante.
- Les attributs des enfants (taille…) deviennent des filtres du parent.

### Flux delta (stock, prix, suppressions)

Même structure que le flux complet, avec seulement le `sku` et les champs modifiés, plus un bloc `<deleted><sku>…</sku></deleted>`.

- Un SKU d'enfant met à jour l'enfant et recalcule le parent.
- Un SKU inconnu est signalé dans le rapport d'import et sera créé au prochain import complet.
- Les catégories et attributs ne changent qu'à l'import complet.

### Blog (Magefan)

Champs : `id`, `title`, `url`, `published_at` (obligatoires), plus `image`, `excerpt`, `content`, `author`, `updated_at`, `categories/category`, `tags/tag`, `related_products/sku`, `enabled`. Seul l'import complet est disponible (le volume est faible).

### Syntaxe du mapping (JSON, modifiable dans l'extranet)

```json
"fields": {
  "brand":       {"path": "manufacturer", "transform": ["trim", "uppercase"]},
  "final_price": {"path": "special_price", "transform": ["default:0"]},
  "margin_rate": {"path": "marge_pct", "transform": ["to_float", "divide:100"]},
  "id":          {"path": "@id"},
  "type":        {"value": "simple"}
}
```

- **Chemins** :
  - `nom` : élément enfant ;
  - `a/b` : sous-élément ;
  - `@attr` : attribut ;
  - `a/@attr` : attribut d'un enfant ;
  - `.` : texte de l'élément ;
  - toute autre expression est lue comme du XPath (`g:price`, `attribute[@code='color']`).
- **Transformations** : `trim`, `lowercase`, `uppercase`, `ucfirst`, `strip_html`, `to_float`, `to_int`, `to_bool` (accepte 1/0, oui/non, « En stock »…), `divide:N`, `multiply:N`, `default:X`, `split:SEP`, `prefix:X`, `suffix:X`, `replace:A|B`, `truncate:N`, `date_iso`.
- **Attributs simples** d'un flux à plat : `"extra_attributes": {"couleur": {"path": "color", "label": "Couleur", "filterable": true}}`.

> 🔒 Le flux produits contient la marge : protégez son URL côté Magento (jeton dans l'URL ou en-tête, filtrage IP, HTTPS). L'extranet permet d'envoyer des en-têtes d'authentification lors du téléchargement.

## 6. Utiliser l'extranet

L'extranet s'utilise depuis `/admin`, avec le jeton `ADMIN_TOKEN`. Il n'y a pas de comptes utilisateurs en V1. Le menu de gauche se replie, et le bouton en bas du menu bascule entre les thèmes sombre et clair ; ces deux choix sont mémorisés.

| Rubrique | À quoi ça sert |
|---|---|
| Tableau de bord | État du moteur, nombre de documents, derniers imports, alertes de configuration |
| Flux & mapping | Sources (URL complet/delta, authentification, mapping JSON), **test du mapping sans indexer**, imports manuels |
| Imports | Historique, rapports (motifs de rejet, exemples de SKU rejetés) |
| Synonymes & alias | Équivalents (palme = palmes = fins) ou alias à sens unique ; import CSV (`samples/synonymes.csv`) |
| Règles de résultats | Épingler un SKU à une position, le remonter, le masquer (requête `*` = masqué partout), avec dates de début et de fin ; import CSV (`samples/boosts.csv`). Ne s'applique qu'au tri « pertinence » |
| Redirections | Requête → page (ex. « retour » → CGV) ; import CSV |
| Aperçu interne | Résultats avec **marge, score métier, ventes, règle appliquée**, tri par rentabilité, filtre « marge minimum » |
| Statistiques | Top requêtes, recherches sans résultat (export CSV), clics, évolution par jour |
| Journal des recherches | Détail filtrable et exportable |
| Robots & blocages | IP les plus actives, requêtes répétées, alertes, blocage et déblocage |
| Clés & intégration | Clés publiques, domaines autorisés, code à copier |
| Paramètres | Seuils anti-robots, poids du classement, cache, conservation des journaux, seuil de sécurité des imports |

**Classement métier (`biz_score`)**
- Calcul : stock × poids + marge × poids + popularité × poids + nouveauté × poids + boost manuel × poids.
- Il sert à départager des résultats aussi pertinents les uns que les autres. La pertinence du texte passe toujours en premier.
- Si vous modifiez les poids, relancez un import complet pour qu'ils s'appliquent.

## 7. Anti-robots

À chaque appel de l'API publique :

1. **IP bloquée** (manuellement ou automatiquement) → réponse 403.
2. **User-agent de robot ou vide** (curl, python, scrapy, navigateur « headless »…) → réponse 403. La liste se modifie dans Paramètres.
3. **Seuils par IP** : 90 requêtes par minute, 1 500 par heure, et 15 fois la même requête par minute (valeurs par défaut) → réponse 429. Le widget affiche alors « patientez ».
4. **Blocage automatique** : 3 minutes en dépassement sur une heure, ou une rafale supérieure à 5 fois la limite par minute → blocage pendant 60 minutes.

**Fonctionnement :**
- Les compteurs sont en mémoire (APCu ou Redis) : un robot ne provoque **aucune écriture en base** au-delà d'une alerte par minute.
- La saisie normale d'un visiteur (une requête toutes les ~180 ms de frappe) reste très en dessous des seuils.
- Si un proxy, un CDN ou un équilibreur de charge est placé devant Apache, renseignez `TRUSTED_PROXIES`. Sinon, tous les visiteurs auront la même IP.
- Les IP du siège ou d'un outil de supervision peuvent être exemptées : Paramètres › « IP jamais limitées ».
- Un déblocage fait directement en base de données n'est pris en compte qu'au bout de 60 secondes (cache). Le bouton « Débloquer » de l'extranet agit immédiatement.

## 8. Journaux de recherche et RGPD

- **Contenu enregistré** : chaque recherche « finale » (pas chaque lettre tapée : « pa », « pal », « palm » sont marquées comme intermédiaires), avec les filtres, le nombre de résultats, le temps de réponse, le user-agent et les clics.
- **Adresse IP** : jamais stockée en clair. On conserve une **empreinte** (HMAC avec `APP_SECRET`) pour compter et bloquer, et une **IP tronquée** (`203.0.113.x`) pour l'affichage. Aucun cookie.
- **Conservation** : 90 jours par défaut (Paramètres), purge automatique chaque nuit (`logs:purge`).
- **À faire valider** par votre référent RGPD / DPO : durée de conservation, mention dans la politique de confidentialité du site. Ce README ne constitue pas un avis juridique.

## 9. Commandes

```bash
php bin/console help
```

| Commande | Rôle |
|---|---|
| `check` | Vérifie PHP, extensions, base, Meilisearch, droits |
| `install` | Crée ou met à jour les tables (sans risque si relancée) et les sources par défaut |
| `admin:token` | Génère `ADMIN_TOKEN` et `APP_SECRET` |
| `key:create --name=… --origins=cabesto.com --langs=fr` | Crée une clé publique |
| `import:run --all` ou `--source=CODE`, `--type=full\|delta` | Import (en ligne de commande ou par cron) |
| `queue:work [--once]` | Traite les imports demandés depuis l'extranet |
| `rules:sync` | Renvoie les synonymes au moteur |
| `rules:import --lang=fr --synonyms=… --rules=… --redirects=… [--replace]` | Import CSV en ligne de commande |
| `logs:purge` | Purge des journaux |

## 10. Tests

Les tests utilisent une **base MySQL de test dédiée**, dont toutes les tables sont supprimées à chaque lancement : n'utilisez **jamais** la base de production. Ils utilisent aussi un **faux Meilisearch** (`tests/fake-meili.php`), qui imite l'API avec une recherche simplifiée.

```sql
CREATE DATABASE deepfind_test CHARACTER SET utf8mb4;
CREATE USER 'df_test'@'localhost' IDENTIFIED BY 'df_test_pw';
GRANT ALL ON deepfind_test.* TO 'df_test'@'localhost';
```

```bash
bash tests/run.sh
```

Ce script lance environ 60 tests unitaires et d'intégration : mapping, regroupement parents/enfants, XML tronqué ou malveillant, API, filtres, règles, SKU, origine, robots.

**Test dans le navigateur (facultatif, nécessite Node.js et Playwright)**

Lancez d'abord `tests/run.sh`. Ensuite, dans deux terminaux, démarrez le faux Meilisearch et le serveur web de développement :

```bash
FAKE_MEILI_DIR=tests/_tmp/meili php -S 127.0.0.1:7799 tests/fake-meili.php
```

```bash
DEEPFIND_ENV_FILE=$PWD/tests/_tmp/.env php -d apc.enable_cli=1 -S 127.0.0.1:8080 -t public public/index.php
```

Puis lancez le test du navigateur :

```bash
node tests/e2e.cjs http://localhost:8080 tests/_tmp/screens
```

Il vérifie la connexion, toutes les pages, les thèmes, le menu replié, les actions principales, le widget (défilement, filtres, mobile) et prend des captures d'écran.

> Le faux Meilisearch ne reproduit pas exactement la pertinence du vrai. Après installation, validez la qualité des résultats sur la VM de recette avec le vrai Meilisearch et votre catalogue (rubrique **Aperçu interne**).

## 11. Dépannage

| Symptôme | Piste |
|---|---|
| Le widget n'affiche rien | Console du navigateur : `invalid_key` (clé), `origin_not_allowed` (ajoutez le domaine dans la clé), `bad_user_agent`/`ip_blocked` (Robots & blocages) |
| `engine_unavailable` (503) | `systemctl status meilisearch`, `curl 127.0.0.1:7700/health`, `MEILI_MASTER_KEY` identique des deux côtés |
| Import « Bloqué » | Le flux contient beaucoup moins de produits qu'avant (seuil en Paramètres). Vérifiez le flux, ou augmentez temporairement le seuil |
| Import « En attente » qui ne démarre pas | Le worker Supervisor ne tourne pas (`supervisorctl status`), ou la ligne cron `queue:work --once` est absente |
| Produits rejetés | Imports › détail : motifs (SKU manquant, prix nul, enfant sans parent…), puis **Tester** le mapping |
| Tous les visiteurs bloqués ensemble | Un proxy ou CDN est devant Apache : renseignez `TRUSTED_PROXIES` |
| Un paramètre modifié n'agit pas | Les paramètres sont mis en cache 60 s ; le classement métier demande un import complet |

## 12. Limites connues de la V1

- **Accès à l'extranet** : un jeton unique, sans comptes utilisateurs ni journal des actions d'administration.
- **Règles de résultats** : correspondance **exacte** de la requête, sans tenir compte des majuscules ni des accents. Pas de « contient ». Pas d'action « descendre » (seulement épingler, remonter, masquer).
- **Flux delta** : met à jour prix, stock, champs simples et suppressions. Les nouveaux produits, catégories et attributs arrivent à l'import complet.
- **Recherche** : pas de recherche sémantique ou IA, pas de suggestions de requêtes, pas de bannières.
- **Tests** : faits sous PHP 8.3, MariaDB 10.11 et un **faux** Meilisearch. Il faut valider sous PHP 8.5, MySQL et le vrai Meilisearch sur la VM de recette.
- **Module Magento** : aucun module n'est fourni pour générer le flux. Utilisez une extension de flux du marché ou un développement interne qui produit le format décrit ci-dessus.
