<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\CacheVersion;
use DeepFind\Settings;
use DeepFind\Store\Store;

/** Cache : nombre d'entrées, place occupée, purge. */
final class CacheController
{
    /** catégorie de clé => [libellé, description, purgeable avec « tous les caches »] */
    public const CATEGORIES = [
        'res' => ['Résultats de recherche', 'Réponses de l\'API gardées quelques secondes (paramètre « Cache des réponses »)', true],
        'rules' => ['Règles compilées', 'Épinglages, masquages et redirections prêts à l\'emploi', true],
        'settings' => ['Paramètres', 'Copie des paramètres de l\'extranet', true],
        'idx' => ['Existence des index', 'Évite d\'interroger Meilisearch sur l\'existence des index', true],
        'key' => ['Clés publiques', 'Clés et domaines autorisés du widget', true],
        'rl' => ['Compteurs anti-robots', 'Requêtes par IP et par minute/heure (jamais purgés : sinon les robots repartent de zéro)', false],
        'blk' => ['Blocages (copie)', 'Copie des IP bloquées, relue en base toutes les 60 s', false],
        'last' => ['Frappe en cours', 'Dernière recherche de chaque visiteur (statistiques « recherche finale »)', false],
        'login' => ['Tentatives de connexion', 'Protection de la page de connexion de l\'extranet', false],
    ];

    public function index(): void
    {
        $store = Store::get();
        $stats = $store->stats();
        $rows = [];
        foreach ($stats['categories'] as $cat => $s) {
            $rows[$cat] = $s + ['label' => self::CATEGORIES[$cat][0] ?? $cat, 'help' => self::CATEGORIES[$cat][1] ?? '', 'purgeable' => self::CATEGORIES[$cat][2] ?? true];
        }
        uasort($rows, static fn ($a, $b) => $b['bytes'] <=> $a['bytes']);
        View::render('cache', [
            'active' => 'cache',
            'backend' => $store->name(),
            'rows' => $rows,
            'stats' => $stats,
            'ttl' => Settings::int('cache_ttl'),
            'version' => CacheVersion::get(),
        ], 'Cache');
    }

    public function purge(): void
    {
        $scope = ($_POST['scope'] ?? 'results') === 'all' ? 'all' : 'results';
        // Uniquement les entrées du projet courant (les clés publiques sont communes : purgées avec « tous les caches »)
        $pid = \DeepFind\Project::id();
        $prefixes = ['res:' . $pid . ':'];
        if ($scope === 'all') {
            $prefixes = [];
            foreach (self::CATEGORIES as $cat => [, , $purgeable]) {
                if ($purgeable) {
                    $prefixes[] = $cat === 'key' ? 'key:' : $cat . ':' . $pid . ':';
                }
            }
        }
        $n = Store::get()->purge($prefixes);
        // La nouvelle version rend aussi inaccessibles les entrées d'autres processus (ex. autre pool PHP-FPM).
        CacheVersion::bump();
        Auth::flash('ok', sprintf('%d entrée(s) du projet supprimée(s). Les prochaines recherches interrogent directement le moteur.', $n));
        App::back('/admin/cache');
    }
}
