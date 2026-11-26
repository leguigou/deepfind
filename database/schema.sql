-- DeepFind : schéma MySQL 8 / MariaDB 10.6+ (idempotent : peut être rejoué)
SET NAMES utf8mb4;

-- Projets (ex. Production, Test) : chaque projet a ses flux, index, règles, paramètres, journaux
CREATE TABLE IF NOT EXISTS projects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(100) NOT NULL,
  index_prefix VARCHAR(60) NOT NULL,
  -- 0 : le widget ne s'active pas sur le site (la recherche d'origine du site reste en place)
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uk_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paramètres propres à un projet (pertinence, recherche, journaux…) ; la table settings garde les paramètres communs
CREATE TABLE IF NOT EXISTS project_settings (
  project_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  value MEDIUMTEXT NOT NULL,
  PRIMARY KEY (project_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sauvegardes de configuration (avant chaque copie entre projets, restaurables)
CREATE TABLE IF NOT EXISTS config_snapshots (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  label VARCHAR(190) NOT NULL,
  data MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_project (project_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  name VARCHAR(100) NOT NULL PRIMARY KEY,
  value MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_keys (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL DEFAULT 1,
  name VARCHAR(100) NOT NULL,
  public_key CHAR(35) NOT NULL,
  allowed_origins TEXT NOT NULL,
  langs VARCHAR(100) NOT NULL DEFAULT 'fr',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uk_public_key (public_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sources (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL DEFAULT 1,
  code VARCHAR(50) NOT NULL,
  kind ENUM('products','posts') NOT NULL,
  lang CHAR(2) NOT NULL DEFAULT 'fr',
  url TEXT NOT NULL,
  delta_url TEXT NULL,
  auth_header TEXT NULL,
  -- protection par mot de passe du serveur (.htaccess / .htpasswd) : utilisateur, mot de passe CHIFFRÉ (clé dérivée de APP_SECRET)
  auth_user VARCHAR(190) NULL,
  auth_password TEXT NULL,
  mapping MEDIUMTEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  cron_full VARCHAR(100) NULL,
  cron_delta VARCHAR(100) NULL,
  scheduled_full_at DATETIME NULL,
  scheduled_delta_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uk_project_code (project_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_runs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_id INT UNSIGNED NOT NULL,
  type ENUM('full','delta','scores') NOT NULL,
  status ENUM('queued','running','success','failed','aborted') NOT NULL,
  trigger_by ENUM('cron','manual','cli') NOT NULL,
  created_at DATETIME NOT NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  items_read INT UNSIGNED NOT NULL DEFAULT 0,
  items_indexed INT UNSIGNED NOT NULL DEFAULT 0,
  items_rejected INT UNSIGNED NOT NULL DEFAULT 0,
  phase VARCHAR(30) NULL,
  items_total INT UNSIGNED NOT NULL DEFAULT 0,
  bytes_done BIGINT UNSIGNED NOT NULL DEFAULT 0,
  bytes_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tasks_done INT UNSIGNED NOT NULL DEFAULT 0,
  tasks_total INT UNSIGNED NOT NULL DEFAULT 0,
  progress_at DATETIME NULL,
  message TEXT NULL,
  report MEDIUMTEXT NULL,
  KEY idx_status (status, id),
  KEY idx_source (source_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table de travail pour les flux « à plat » (parents et enfants sur des lignes séparées)
CREATE TABLE IF NOT EXISTS import_staging (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id INT UNSIGNED NOT NULL,
  group_key VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  is_child TINYINT(1) NOT NULL,
  payload MEDIUMBLOB NOT NULL,
  KEY idx_group (run_id, group_key, is_child, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS synonyms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL DEFAULT 1,
  lang CHAR(2) NOT NULL,
  type ENUM('equivalent','alias') NOT NULL DEFAULT 'equivalent',
  term VARCHAR(190) NOT NULL,
  synonyms TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_project_lang (project_id, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS query_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL DEFAULT 1,
  lang CHAR(2) NOT NULL,
  query VARCHAR(190) NOT NULL,
  sku VARCHAR(190) NOT NULL,
  action ENUM('pin','boost','hide') NOT NULL,
  position SMALLINT UNSIGNED NULL,
  starts_at DATE NULL,
  ends_at DATE NULL,
  created_at DATETIME NOT NULL,
  KEY idx_project_lang_query (project_id, lang, query)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Boosts de produits saisis dans l'extranet (-10 à +10), ajoutés au boost du flux dans le score métier
CREATE TABLE IF NOT EXISTS product_boosts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL DEFAULT 1,
  lang CHAR(2) NOT NULL,
  sku VARCHAR(190) NOT NULL,
  boost TINYINT NOT NULL,
  starts_at DATE NULL,
  ends_at DATE NULL,
  note VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  UNIQUE KEY uk_project_lang_sku (project_id, lang, sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Règles de boost : multiplient la pertinence des produits qui remplissent les conditions (toutes ou au moins une),
-- sur toutes les recherches ou seulement sur certains termes (correspondance exacte ou large).
CREATE TABLE IF NOT EXISTS boost_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL DEFAULT 1,
  lang CHAR(2) NOT NULL,
  name VARCHAR(190) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  boost DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  terms TEXT NOT NULL,
  match_all TINYINT(1) NOT NULL DEFAULT 1,
  conditions TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_project_lang (project_id, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Résultats personnalisés : pour certains termes de recherche, produits mis en avant (ou seuls affichés) et produits exclus,
-- choisis un par un ou par règle (conditions), en continu ou sur une période.
CREATE TABLE IF NOT EXISTS custom_results (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL DEFAULT 1,
  lang CHAR(2) NOT NULL,
  name VARCHAR(190) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  starts_at DATE NULL,
  ends_at DATE NULL,
  mode ENUM('first','only') NOT NULL DEFAULT 'first',
  terms TEXT NOT NULL,
  include_items TEXT NOT NULL,
  include_rules TEXT NOT NULL,
  exclude_items TEXT NOT NULL,
  exclude_rules TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_project_lang (project_id, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bannières affichées en haut des résultats : image ordinateur / mobile + lien, ou HTML (nettoyé) ; par termes ou par défaut.
CREATE TABLE IF NOT EXISTS banners (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL DEFAULT 1,
  lang CHAR(2) NOT NULL,
  name VARCHAR(190) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  starts_at DATE NULL,
  ends_at DATE NULL,
  terms TEXT NOT NULL,
  mode ENUM('visual','code') NOT NULL DEFAULT 'visual',
  image_desktop VARCHAR(500) NOT NULL DEFAULT '',
  image_mobile VARCHAR(500) NOT NULL DEFAULT '',
  alt VARCHAR(190) NOT NULL DEFAULT '',
  link_url VARCHAR(500) NOT NULL DEFAULT '',
  new_window TINYINT(1) NOT NULL DEFAULT 0,
  html TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_project_lang (project_id, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Redirections : pour certains termes (exacts ou larges), en continu ou sur une période, un lien vers une page est proposé
-- en haut des résultats ; en mode automatique, la recherche validée (Entrée, loupe) ouvre directement la page.
CREATE TABLE IF NOT EXISTS redirections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL DEFAULT 1,
  lang CHAR(2) NOT NULL,
  name VARCHAR(190) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  auto TINYINT(1) NOT NULL DEFAULT 1,
  new_tab TINYINT(1) NOT NULL DEFAULT 0,
  url VARCHAR(500) NOT NULL,
  terms TEXT NOT NULL,
  starts_at DATE NULL,
  ends_at DATE NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_project_lang (project_id, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ancien format (une requête exacte = une URL), converti dans « redirections » par install / import CSV / restauration
CREATE TABLE IF NOT EXISTS redirects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL DEFAULT 1,
  lang CHAR(2) NOT NULL,
  query VARCHAR(190) NOT NULL,
  url TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uk_project_lang_query (project_id, lang, query)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal des recherches : IP stockée uniquement sous forme d'empreinte (HMAC) + version tronquée
CREATE TABLE IF NOT EXISTS search_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  api_key_id INT UNSIGNED NULL,
  lang CHAR(2) NOT NULL,
  query VARCHAR(255) NOT NULL,
  query_norm VARCHAR(255) NOT NULL,
  filters VARCHAR(500) NULL,
  results INT UNSIGNED NOT NULL DEFAULT 0,
  approx TINYINT(1) NOT NULL DEFAULT 0,
  approx_results INT UNSIGNED NOT NULL DEFAULT 0,
  took_ms SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ip_hash CHAR(16) NOT NULL,
  ip_masked VARCHAR(64) NOT NULL,
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  sid VARCHAR(32) NULL,
  is_final TINYINT(1) NOT NULL DEFAULT 1,
  custom_ids VARCHAR(255) NULL,
  banner_id INT UNSIGNED NULL,
  KEY idx_created (created_at),
  KEY idx_project_created (project_id, created_at),
  KEY idx_query (query_norm(100), created_at),
  KEY idx_ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS click_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  lang CHAR(2) NOT NULL,
  query_norm VARCHAR(255) NOT NULL,
  doc_type ENUM('product','post','banner') NOT NULL,
  doc_id VARCHAR(190) NOT NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ip_hash CHAR(16) NOT NULL,
  sid VARCHAR(32) NULL,
  custom_result_id INT UNSIGNED NULL,
  KEY idx_created (created_at),
  KEY idx_project_created (project_id, created_at),
  KEY idx_query (query_norm(100), created_at),
  KEY idx_custom (custom_result_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ip_blocks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip_hash CHAR(16) NOT NULL,
  ip_masked VARCHAR(64) NOT NULL DEFAULT '',
  reason VARCHAR(255) NOT NULL,
  source ENUM('auto','manual') NOT NULL,
  blocked_until DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uk_ip (ip_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL,
  ip_hash CHAR(16) NOT NULL,
  ip_masked VARCHAR(64) NOT NULL,
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  reason VARCHAR(32) NOT NULL,
  KEY idx_created (created_at),
  KEY idx_ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Repli du stockage clé/valeur quand ni APCu ni Redis ne sont disponibles
CREATE TABLE IF NOT EXISTS kv_store (
  k VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  v MEDIUMBLOB NOT NULL,
  expires_at INT UNSIGNED NOT NULL,
  KEY idx_exp (expires_at)
) ENGINE=InnoDB;

-- Layers du widget (page « Intégration ») : façon dont la fenêtre de recherche s'affiche sur le site.
-- type : below (sous la barre), fullscreen, floating, embedded ; un seul layer actif par projet (aucun = affichage par défaut).
-- settings (JSON) : input (sélecteur CSS du champ), container (bloc du site, type embedded), url_state (état dans l'URL), gap.
CREATE TABLE IF NOT EXISTS layers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL DEFAULT 1,
  name VARCHAR(100) NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'below',
  active TINYINT(1) NOT NULL DEFAULT 0,
  settings TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mesure d'audience, sans donnée personnelle ni cookie.
-- audience_visitors : une ligne par visiteur et par jour, identifié par une EMPREINTE DU JOUR
-- (HMAC de l'IP et du navigateur avec un sel aléatoire propre au jour). Aucune IP n'est stockée.
-- Ces lignes ne concernent que la journée en cours : chaque jour passé est agrégé dans audience_daily,
-- puis ses lignes et son sel sont supprimés (schedule:run) ; l'empreinte ne peut plus être recalculée.
CREATE TABLE IF NOT EXISTS audience_visitors (
  project_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  vhash CHAR(32) NOT NULL,
  device ENUM('mobile','tablet','desktop') NOT NULL,
  pageviews INT UNSIGNED NOT NULL DEFAULT 0,
  searches INT UNSIGNED NOT NULL DEFAULT 0,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  -- parcours d'achat de la journée (compteurs ; montants en centimes)
  bar_opens INT UNSIGNED NOT NULL DEFAULT 0,
  closes INT UNSIGNED NOT NULL DEFAULT 0,
  zero_searches INT UNSIGNED NOT NULL DEFAULT 0,
  carousel_clicks INT UNSIGNED NOT NULL DEFAULT 0,
  carts INT UNSIGNED NOT NULL DEFAULT 0,
  cart_value INT UNSIGNED NOT NULL DEFAULT 0,
  orders INT UNSIGNED NOT NULL DEFAULT 0,
  revenue INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (project_id, day, vhash),
  KEY idx_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- Statistiques globales quotidiennes par type d'appareil (conservées : aucune donnée personnelle).
CREATE TABLE IF NOT EXISTS audience_daily (
  project_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  device ENUM('mobile','tablet','desktop') NOT NULL,
  visitors INT UNSIGNED NOT NULL DEFAULT 0,
  pageviews INT UNSIGNED NOT NULL DEFAULT 0,
  searchers INT UNSIGNED NOT NULL DEFAULT 0,
  searches INT UNSIGNED NOT NULL DEFAULT 0,
  clickers INT UNSIGNED NOT NULL DEFAULT 0,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  searchers_1 INT UNSIGNED NOT NULL DEFAULT 0,
  searchers_2_3 INT UNSIGNED NOT NULL DEFAULT 0,
  searchers_4p INT UNSIGNED NOT NULL DEFAULT 0,
  -- parcours d'achat (nombre de visiteurs, sauf orders / revenue ; montants en centimes)
  bar_openers INT UNSIGNED NOT NULL DEFAULT 0,
  closers INT UNSIGNED NOT NULL DEFAULT 0,
  zero_searchers INT UNSIGNED NOT NULL DEFAULT 0,
  zero_leavers INT UNSIGNED NOT NULL DEFAULT 0,
  carousel_clickers INT UNSIGNED NOT NULL DEFAULT 0,
  carters INT UNSIGNED NOT NULL DEFAULT 0,
  buyers INT UNSIGNED NOT NULL DEFAULT 0,
  orders INT UNSIGNED NOT NULL DEFAULT 0,
  revenue BIGINT UNSIGNED NOT NULL DEFAULT 0,
  abandoners INT UNSIGNED NOT NULL DEFAULT 0,
  searcher_carters INT UNSIGNED NOT NULL DEFAULT 0,
  searcher_buyers INT UNSIGNED NOT NULL DEFAULT 0,
  searcher_orders INT UNSIGNED NOT NULL DEFAULT 0,
  searcher_revenue BIGINT UNSIGNED NOT NULL DEFAULT 0,
  searcher_abandoners INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (project_id, day, device)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- Recherches qui rapportent : par terme (masqué : e-mails, téléphones… retirés) et par jour, ajouts au panier et commandes
-- attribués à la DERNIÈRE recherche du visiteur dans la journée. Totaux uniquement ; purgés avec les journaux.
CREATE TABLE IF NOT EXISTS audience_terms (
  project_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  term VARCHAR(190) NOT NULL,
  searches INT UNSIGNED NOT NULL DEFAULT 0,
  carts INT UNSIGNED NOT NULL DEFAULT 0,
  orders INT UNSIGNED NOT NULL DEFAULT 0,
  revenue BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (project_id, day, term),
  KEY idx_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Produits : clics (recherche et carrousels), ajouts au panier, commandes, par jour. Totaux uniquement ; purgés avec les journaux.
CREATE TABLE IF NOT EXISTS audience_products (
  project_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(255) NOT NULL DEFAULT '',
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  carts INT UNSIGNED NOT NULL DEFAULT 0,
  orders INT UNSIGNED NOT NULL DEFAULT 0,
  revenue BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (project_id, day, product_id),
  KEY idx_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chiffre d'affaires par emplacement : clics, paniers, commandes et CA d'un produit selon l'endroit où il a été cliqué
-- (search, carousel:<id>, direct = panier sans clic DeepFind dans la journée). Totaux uniquement ; purgés avec les journaux.
CREATE TABLE IF NOT EXISTS audience_attrib (
  project_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  placement VARCHAR(40) CHARACTER SET ascii NOT NULL,
  product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  carts INT UNSIGNED NOT NULL DEFAULT 0,
  orders INT UNSIGNED NOT NULL DEFAULT 0,
  revenue BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (project_id, day, placement, product_id),
  KEY idx_product (project_id, product_id, day),
  KEY idx_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- Recommandations : paires de produits achetés ensemble (kind b, même commande) ou regardés ensemble (kind v, même visiteur,
-- même journée de mesure). Totaux par jour, sans visiteur ; gardés Recos::WINDOW_DAYS jours.
CREATE TABLE IF NOT EXISTS reco_pairs (
  project_id INT UNSIGNED NOT NULL,
  kind CHAR(1) NOT NULL,
  day DATE NOT NULL,
  a VARCHAR(64) COLLATE ascii_bin NOT NULL,
  b VARCHAR(64) COLLATE ascii_bin NOT NULL,
  n INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (project_id, kind, a, day, b),
  KEY idx_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- Campagnes de produits à pousser (Recommandations › Campagnes) : liste de SKU, période, objectif ; suivi des ventes.
CREATE TABLE IF NOT EXISTS campaigns (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL DEFAULT 1,
  lang CHAR(2) NOT NULL,
  name VARCHAR(190) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  starts_at DATE NULL,
  ends_at DATE NULL,
  objective VARCHAR(500) NOT NULL DEFAULT '',
  items TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_project_lang (project_id, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parcours (Analyse › Parcours) : uniquement les visiteurs qui ont cherché ou cliqué sur un carrousel, identifiés par
-- l'EMPREINTE DU JOUR (aucune IP, rien sur l'appareil). Supprimés Journeys::RETENTION_HOURS après leur dernier événement.
CREATE TABLE IF NOT EXISTS journeys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  vhash CHAR(32) CHARACTER SET ascii NOT NULL,
  device ENUM('mobile','tablet','desktop') NOT NULL,
  started_at DATETIME NOT NULL,
  last_at DATETIME NOT NULL,
  entry_path VARCHAR(300) NOT NULL DEFAULT '',
  trigger_type VARCHAR(12) NOT NULL DEFAULT 'search',
  pageviews INT UNSIGNED NOT NULL DEFAULT 0,
  searches INT UNSIGNED NOT NULL DEFAULT 0,
  zero_searches INT UNSIGNED NOT NULL DEFAULT 0,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  carousel_clicks INT UNSIGNED NOT NULL DEFAULT 0,
  carts INT UNSIGNED NOT NULL DEFAULT 0,
  cart_value INT UNSIGNED NOT NULL DEFAULT 0,
  orders INT UNSIGNED NOT NULL DEFAULT 0,
  revenue INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_visitor (project_id, day, vhash),
  KEY idx_last (project_id, last_at),
  KEY idx_purge (last_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Événements d'un parcours, datés à la milliseconde. data : détails en JSON (recherches masquées).
CREATE TABLE IF NOT EXISTS journey_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  journey_id BIGINT UNSIGNED NOT NULL,
  at DATETIME(3) NOT NULL,
  type VARCHAR(16) CHARACTER SET ascii NOT NULL,
  data TEXT NOT NULL,
  KEY idx_journey (journey_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sel aléatoire de l'empreinte du jour : supprimé dès que le jour est agrégé.
CREATE TABLE IF NOT EXISTS audience_salts (
  day DATE NOT NULL PRIMARY KEY,
  salt CHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- Règles selon la page (Recommandations › Règles) : d'après l'adresse de la page où se trouve le visiteur,
-- n'afficher que certains produits, les pré-sélectionner (filtre que le visiteur peut retirer) ou les exclure.
-- Sans terme de recherche ni période ; seule la première règle active (ordre « position ») qui correspond s'applique.
CREATE TABLE IF NOT EXISTS page_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL DEFAULT 1,
  lang CHAR(2) NOT NULL,
  name VARCHAR(190) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  urls TEXT NOT NULL,
  action ENUM('only','prefilter','exclude') NOT NULL DEFAULT 'prefilter',
  label VARCHAR(100) NOT NULL DEFAULT '',
  match_all TINYINT(1) NOT NULL DEFAULT 1,
  conditions TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_project_lang (project_id, lang, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Popins (Recommandations › Popins) : fenêtre affichée par le widget sur les pages du site choisies (toutes, l'accueil,
-- ou d'après l'adresse, comme les règles selon la page), en continu ou sur une période, après un délai.
-- La première popin active de la liste (ordre « position ») qui correspond à la page et n'a pas été fermée s'affiche.
-- rev : incrémenté pour la réafficher aux visiteurs qui l'avaient fermée (mémorisation dans leur navigateur).
CREATE TABLE IF NOT EXISTS popins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL DEFAULT 1,
  lang CHAR(2) NOT NULL,
  name VARCHAR(190) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  starts_at DATE NULL,
  ends_at DATE NULL,
  pages ENUM('all','custom') NOT NULL DEFAULT 'all',
  home TINYINT(1) NOT NULL DEFAULT 0,
  urls TEXT NOT NULL,
  exclude_urls TEXT NOT NULL,
  -- ciblage selon la provenance du visiteur (voir carousels.segments)
  segments TEXT NULL,
  device ENUM('all','desktop','mobile') NOT NULL DEFAULT 'all',
  delay_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  repeat_mode ENUM('visit','page','always') NOT NULL DEFAULT 'visit',
  remember_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  placement ENUM('center','bottom_right','bottom_left') NOT NULL DEFAULT 'center',
  max_width SMALLINT UNSIGNED NOT NULL DEFAULT 600,
  min_height SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  mode ENUM('visual','code') NOT NULL DEFAULT 'visual',
  image_desktop VARCHAR(500) NOT NULL DEFAULT '',
  image_mobile VARCHAR(500) NOT NULL DEFAULT '',
  alt VARCHAR(190) NOT NULL DEFAULT '',
  link_url VARCHAR(500) NOT NULL DEFAULT '',
  new_window TINYINT(1) NOT NULL DEFAULT 0,
  html MEDIUMTEXT NOT NULL,
  rev INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_project_lang (project_id, lang, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Statistiques des popins, agrégées par jour (aucune donnée personnelle) : affichages, clics, fermetures.
CREATE TABLE IF NOT EXISTS popin_stats (
  project_id INT UNSIGNED NOT NULL,
  popin_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  closes INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (project_id, popin_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- Carrousels de produits (Recommandations › Carrousels) : bandeau de produits inséré par le widget dans une page du site
-- (sélecteur CSS + position), sur les pages choisies comme les popins. Produits : sélection manuelle (ordre imposé),
-- automatiques (conditions + tri), similaires au produit de la page, ou personnalisés (historique gardé dans le navigateur
-- du visiteur, avec son consentement) ; « apprentissage » : classement selon le taux de clic dans le carrousel.
CREATE TABLE IF NOT EXISTS carousels (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL DEFAULT 1,
  lang CHAR(2) NOT NULL,
  name VARCHAR(190) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  starts_at DATE NULL,
  ends_at DATE NULL,
  pages ENUM('all','custom') NOT NULL DEFAULT 'all',
  home TINYINT(1) NOT NULL DEFAULT 0,
  urls TEXT NOT NULL,
  exclude_urls TEXT NOT NULL,
  device ENUM('all','desktop','mobile') NOT NULL DEFAULT 'all',
  selector VARCHAR(300) NOT NULL DEFAULT '',
  insert_mode ENUM('append','prepend','before','after','replace') NOT NULL DEFAULT 'append',
  title VARCHAR(190) NOT NULL DEFAULT '',
  title_class VARCHAR(300) NULL,
  more_url VARCHAR(500) NOT NULL DEFAULT '',
  more_label VARCHAR(60) NOT NULL DEFAULT '',
  source ENUM('manual','auto','similar','personal','trending','bought','viewed','cart') NOT NULL DEFAULT 'auto',
  items TEXT NOT NULL,
  match_all TINYINT(1) NOT NULL DEFAULT 1,
  conditions TEXT NOT NULL,
  -- adaptations selon la page : [{url:{match,text}, match_all, conditions}] (conditions ajoutées sur les pages dont l'adresse correspond)
  page_filters TEXT NULL,
  -- ciblage selon la provenance du visiteur : {channels: [...], utm: [{field, op, text}]} (vide : tout le monde)
  segments TEXT NULL,
  sort_by VARCHAR(20) NOT NULL DEFAULT 'biz',
  similar_by ENUM('category','brand','category_brand') NOT NULL DEFAULT 'category',
  personal_mode ENUM('recent','recommended') NOT NULL DEFAULT 'recommended',
  learning TINYINT(1) NOT NULL DEFAULT 0,
  in_stock_only TINYINT(1) NOT NULL DEFAULT 1,
  max_items SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  per_view_desktop TINYINT UNSIGNED NOT NULL DEFAULT 4,
  per_view_mobile TINYINT UNSIGNED NOT NULL DEFAULT 2,
  autoplay SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  custom_css MEDIUMTEXT NOT NULL,
  card_template TEXT NULL,
  card_template_mobile TEXT NULL,
  card_link VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_project_lang (project_id, lang, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Statistiques des carrousels, agrégées par jour (aucune donnée personnelle) : carrousel vu à l'écran, clics.
CREATE TABLE IF NOT EXISTS carousel_stats (
  project_id INT UNSIGNED NOT NULL,
  carousel_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (project_id, carousel_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- Par produit et par jour : affichages (produit visible dans le carrousel) et clics ; sert à l'apprentissage.
CREATE TABLE IF NOT EXISTS carousel_product_stats (
  project_id INT UNSIGNED NOT NULL,
  carousel_id INT UNSIGNED NOT NULL,
  product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  day DATE NOT NULL,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (project_id, carousel_id, product_id, day),
  KEY idx_day (day)
) ENGINE=InnoDB;

-- Supervision (Analyse › Temps réel & charge) : compteurs de l'API par minute et par projet, cumulés en mémoire
-- (APCu / Redis) puis versés une fois par minute (Logging\Metrics) ; conservés 6 jours. Aucune donnée personnelle.
-- s_* : requêtes de recherche (temps de réponse cumulé, répartition par durée h50 = moins de 50 ms…).
CREATE TABLE IF NOT EXISTS metrics_minute (
  project_id INT UNSIGNED NOT NULL,
  minute DATETIME NOT NULL,
  requests INT UNSIGNED NOT NULL DEFAULT 0,
  searches INT UNSIGNED NOT NULL DEFAULT 0,
  searches_cached INT UNSIGNED NOT NULL DEFAULT 0,
  searches_empty INT UNSIGNED NOT NULL DEFAULT 0,
  pageviews INT UNSIGNED NOT NULL DEFAULT 0,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  configs INT UNSIGNED NOT NULL DEFAULT 0,
  events INT UNSIGNED NOT NULL DEFAULT 0,
  errors INT UNSIGNED NOT NULL DEFAULT 0,
  denied INT UNSIGNED NOT NULL DEFAULT 0,
  visitors INT UNSIGNED NOT NULL DEFAULT 0,
  ms_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
  s_ms_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
  h50 INT UNSIGNED NOT NULL DEFAULT 0,
  h100 INT UNSIGNED NOT NULL DEFAULT 0,
  h200 INT UNSIGNED NOT NULL DEFAULT 0,
  h500 INT UNSIGNED NOT NULL DEFAULT 0,
  h1000 INT UNSIGNED NOT NULL DEFAULT 0,
  hmore INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (project_id, minute),
  KEY idx_minute (minute)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- Charge de la machine par minute (commune à tous les projets) : charge moyenne sur 1 min, nombre de cœurs, mémoire utilisée.
CREATE TABLE IF NOT EXISTS server_minute (
  minute DATETIME NOT NULL PRIMARY KEY,
  load1 DECIMAL(7,2) NOT NULL DEFAULT 0,
  cpus SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  mem_pct DECIMAL(5,1) NULL
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- Visiteurs actifs (« en ce moment ») : empreinte du jour (celle de la mesure d'audience, sans IP) et dernière activité ;
-- une écriture par visiteur et par minute au plus, lignes supprimées au bout de 15 minutes.
CREATE TABLE IF NOT EXISTS live_visitors (
  project_id INT UNSIGNED NOT NULL,
  vhash CHAR(32) NOT NULL,
  last_seen INT UNSIGNED NOT NULL,
  PRIMARY KEY (project_id, vhash),
  KEY idx_seen (project_id, last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- Notifications (Web Push) : abonnés. endpoint = adresse d'envoi fournie par le navigateur (service du navigateur : Google,
-- Mozilla, Apple…), p256dh / auth = clés publiques de chiffrement du navigateur. Aucune autre donnée sur la personne.
-- Supprimé au désabonnement, ou quand le service du navigateur indique que l'abonnement n'existe plus (404 / 410).
CREATE TABLE IF NOT EXISTS push_subscriptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  lang CHAR(2) NOT NULL,
  endpoint_hash CHAR(64) CHARACTER SET ascii NOT NULL,
  endpoint TEXT NOT NULL,
  p256dh VARCHAR(120) CHARACTER SET ascii NOT NULL,
  auth VARCHAR(40) CHARACTER SET ascii NOT NULL,
  device ENUM('phone','tablet','desktop') NOT NULL DEFAULT 'phone',
  channel VARCHAR(12) CHARACTER SET ascii NOT NULL DEFAULT 'direct',
  failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_endpoint (endpoint_hash),
  KEY idx_project (project_id, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications : messages envoyés (Recommandations › Envoi de notifications) et leurs résultats.
CREATE TABLE IF NOT EXISTS push_campaigns (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  lang CHAR(2) NOT NULL,
  title VARCHAR(80) NOT NULL,
  body VARCHAR(250) NOT NULL DEFAULT '',
  image VARCHAR(500) NOT NULL DEFAULT '',
  url VARCHAR(500) NOT NULL DEFAULT '',
  devices ENUM('all','phone','mobile','desktop') NOT NULL DEFAULT 'all',
  channels TEXT NULL,
  status ENUM('draft','scheduled','sending','sent') NOT NULL DEFAULT 'draft',
  scheduled_at DATETIME NULL,
  sent_at DATETIME NULL,
  targeted INT UNSIGNED NOT NULL DEFAULT 0,
  sent INT UNSIGNED NOT NULL DEFAULT 0,
  failed INT UNSIGNED NOT NULL DEFAULT 0,
  shown INT UNSIGNED NOT NULL DEFAULT 0,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_project (project_id, lang),
  KEY idx_due (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications : invitation (affichée, acceptée, plus tard), réponse du navigateur (autorisé, bloqué), abonnements, désabonnements,
-- par jour. Totaux uniquement.
CREATE TABLE IF NOT EXISTS push_stats (
  project_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  metric VARCHAR(12) CHARACTER SET ascii NOT NULL,
  n INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (project_id, day, metric)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;
