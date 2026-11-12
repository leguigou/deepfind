-- DeepFind : schéma MySQL 8 / MariaDB 10.6+ (idempotent : peut être rejoué)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  name VARCHAR(100) NOT NULL PRIMARY KEY,
  value MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_keys (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
  code VARCHAR(50) NOT NULL,
  kind ENUM('products','posts') NOT NULL,
  lang CHAR(2) NOT NULL DEFAULT 'fr',
  url TEXT NOT NULL,
  delta_url TEXT NULL,
  auth_header TEXT NULL,
  mapping MEDIUMTEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  cron_full VARCHAR(100) NULL,
  cron_delta VARCHAR(100) NULL,
  scheduled_full_at DATETIME NULL,
  scheduled_delta_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uk_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_runs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_id INT UNSIGNED NOT NULL,
  type ENUM('full','delta') NOT NULL,
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
  lang CHAR(2) NOT NULL,
  type ENUM('equivalent','alias') NOT NULL DEFAULT 'equivalent',
  term VARCHAR(190) NOT NULL,
  synonyms TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_lang (lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS query_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  lang CHAR(2) NOT NULL,
  query VARCHAR(190) NOT NULL,
  sku VARCHAR(190) NOT NULL,
  action ENUM('pin','boost','hide') NOT NULL,
  position SMALLINT UNSIGNED NULL,
  starts_at DATE NULL,
  ends_at DATE NULL,
  created_at DATETIME NOT NULL,
  KEY idx_lang_query (lang, query)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redirects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  lang CHAR(2) NOT NULL,
  query VARCHAR(190) NOT NULL,
  url TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uk_lang_query (lang, query)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal des recherches : IP stockée uniquement sous forme d'empreinte (HMAC) + version tronquée
CREATE TABLE IF NOT EXISTS search_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL,
  api_key_id INT UNSIGNED NULL,
  lang CHAR(2) NOT NULL,
  query VARCHAR(255) NOT NULL,
  query_norm VARCHAR(255) NOT NULL,
  filters VARCHAR(500) NULL,
  results INT UNSIGNED NOT NULL DEFAULT 0,
  took_ms SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ip_hash CHAR(16) NOT NULL,
  ip_masked VARCHAR(64) NOT NULL,
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  sid VARCHAR(32) NULL,
  is_final TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_created (created_at),
  KEY idx_query (query_norm(100), created_at),
  KEY idx_ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS click_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL,
  lang CHAR(2) NOT NULL,
  query_norm VARCHAR(255) NOT NULL,
  doc_type ENUM('product','post') NOT NULL,
  doc_id VARCHAR(190) NOT NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ip_hash CHAR(16) NOT NULL,
  sid VARCHAR(32) NULL,
  KEY idx_created (created_at),
  KEY idx_query (query_norm(100), created_at)
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
