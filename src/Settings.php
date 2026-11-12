<?php

declare(strict_types=1);

namespace DeepFind;

use DeepFind\Store\Store;

/**
 * Paramètres modifiables depuis l'extranet (table settings). Valeurs par défaut ci-dessous.
 */
final class Settings
{
    public const DEFAULTS = [
        // Anti-robots
        'rate_per_minute' => '90',
        'rate_per_hour' => '1500',
        'same_query_per_minute' => '15',
        'burst_multiplier' => '5',
        'violations_before_block' => '3',
        'block_minutes' => '60',
        'block_bad_user_agents' => '1',
        'bad_user_agents' => 'bot|crawl|spider|slurp|curl|wget|python|scrapy|httpclient|go-http|java/|libwww|okhttp|axios|node-fetch|headless|phantomjs|selenium|puppeteer|playwright',
        'ip_allowlist' => '',
        // Classement métier (biz_score, recalculé à chaque import)
        'w_stock' => '30',
        'w_margin' => '20',
        'w_popularity' => '30',
        'w_new' => '10',
        'w_manual_boost' => '5',
        'popularity_ref' => '100',
        'new_days' => '45',
        'out_of_stock_last' => '1',
        'biz_importance' => 'low',
        'matching_mode' => 'exact_then_approx',
        'min_relevance' => '0',
        'attribute_first' => '0',
        'name_position_weight' => '0.5',
        'rerank_window' => '100',
        'w_clicks' => '20',
        'clicks_ref' => '50',
        'scores_nightly' => '1',
        'scores_cron' => '30 4 * * *',
        'searchable_fields' => '',
        // Recherche
        'posts_limit' => '4',
        'max_limit' => '48',
        'cache_ttl' => '30',
        'facet_values_limit' => '30',
        // Journaux
        'log_searches' => '1',
        'log_retention_days' => '90',
        // Import
        'max_drop_percent' => '20',
    ];

    /** Paramètres marche/arrêt (affichés sous forme d'interrupteur) */
    public const BOOLEANS = ['block_bad_user_agents', 'out_of_stock_last', 'log_searches', 'attribute_first', 'scores_nightly'];

    public const LABELS = [
        'rate_per_minute' => 'Requêtes max. par IP et par minute',
        'rate_per_hour' => 'Requêtes max. par IP et par heure',
        'same_query_per_minute' => 'Même requête max. par IP et par minute',
        'burst_multiplier' => 'Blocage immédiat si la limite/minute est dépassée × N',
        'violations_before_block' => 'Minutes en dépassement (sur 1 h) avant blocage auto',
        'block_minutes' => 'Durée du blocage automatique (minutes)',
        'block_bad_user_agents' => 'Refuser les user-agents de robots',
        'bad_user_agents' => 'Motifs de user-agents refusés (regex, séparés par |)',
        'ip_allowlist' => 'IP jamais limitées (séparées par des virgules)',
        'w_stock' => 'Poids : en stock',
        'w_margin' => 'Poids : rentabilité (marge 0 à 1)',
        'w_popularity' => 'Poids : popularité (ventes 30 j)',
        'w_new' => 'Poids : nouveauté',
        'w_manual_boost' => 'Poids : par point de boost manuel',
        'popularity_ref' => 'Ventes 30 j considérées comme « max » de popularité',
        'new_days' => 'Un produit est « nouveau » pendant N jours',
        'out_of_stock_last' => 'Ruptures en fin de liste',
        'searchable_fields' => 'Ordre des champs cherchables',
        'matching_mode' => 'Correspondance des mots',
        'min_relevance' => 'Pertinence minimum',
        'attribute_first' => 'Priorité au champ où le mot est trouvé',
        'name_position_weight' => 'Bonus « mot au début du nom »',
        'rerank_window' => 'Résultats réordonnés',
        'biz_importance' => 'Importance du score métier',
        'w_clicks' => 'Poids : clics dans la recherche',
        'clicks_ref' => 'Clics 30 j considérés comme « max »',
        'scores_nightly' => 'Recalcul des scores chaque nuit',
        'scores_cron' => 'Horaire du recalcul des scores',
        'posts_limit' => 'Nombre d\'articles de blog affichés',
        'max_limit' => 'Produits max. par page',
        'cache_ttl' => 'Cache des réponses (secondes, 0 = désactivé)',
        'facet_values_limit' => 'Valeurs max. par filtre',
        'log_searches' => 'Journaliser les recherches',
        'log_retention_days' => 'Conservation des journaux (jours)',
        'max_drop_percent' => 'Import bloqué si le catalogue baisse de plus de N %',
    ];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $store = Store::get();
        $pid = Project::id();
        $key = 'settings:' . $pid . ':' . CacheVersion::get();
        $cached = PHP_SAPI === 'cli' ? null : $store->get($key);
        if (!is_array($cached)) {
            $cached = self::DEFAULTS;
            // Paramètres communs (anti-robots…) puis paramètres du projet courant
            foreach (Db::all('SELECT name, value FROM settings') as $row) {
                if (self::isGlobal($row['name'])) {
                    $cached[$row['name']] = (string) $row['value'];
                }
            }
            foreach (Db::all('SELECT name, value FROM project_settings WHERE project_id = ?', [$pid]) as $row) {
                if (!self::isGlobal($row['name'])) {
                    $cached[$row['name']] = (string) $row['value'];
                }
            }
            if (PHP_SAPI !== 'cli') {
                $store->set($key, $cached, 60);
            }
        }
        return self::$cache = $cached;
    }

    /** Paramètre commun à tous les projets (protection anti-robots, durée de conservation, état du planificateur). */
    public static function isGlobal(string $name): bool
    {
        return in_array($name, Migrations::GLOBAL_SETTINGS, true);
    }

    /** @return array<string,string> paramètres propres au projet (pour la copie entre projets) */
    public static function projectValues(int $projectId): array
    {
        $out = [];
        foreach (Db::all('SELECT name, value FROM project_settings WHERE project_id = ?', [$projectId]) as $r) {
            if (!self::isGlobal($r['name'])) {
                $out[$r['name']] = (string) $r['value'];
            }
        }
        return $out;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }

    public static function get(string $name): string
    {
        return self::all()[$name] ?? self::DEFAULTS[$name] ?? '';
    }

    public static function int(string $name): int
    {
        return (int) self::get($name);
    }

    public static function float(string $name): float
    {
        return (float) self::get($name);
    }

    public static function set(string $name, string $value): void
    {
        if (self::isGlobal($name)) {
            Db::exec('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?', [$name, $value, $value]);
            self::$cache = null;
            // Paramètre commun : tous les projets relisent leurs paramètres
            foreach (array_keys(Project::all()) as $pid) {
                CacheVersion::bump($pid);
            }
            return;
        }
        Db::exec(
            'INSERT INTO project_settings (project_id, name, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = ?',
            [Project::id(), $name, $value, $value]
        );
        self::flush();
    }

    /** Valeur JSON stockée (ex. libellés d'attributs découverts à l'import). */
    public static function json(string $name): array
    {
        $v = json_decode(self::get($name) ?: '[]', true);
        return is_array($v) ? $v : [];
    }

    public static function flush(): void
    {
        self::$cache = null;
        // Nouvelle version : les paramètres et les réponses en cache sont relus immédiatement partout.
        CacheVersion::bump();
    }
}
