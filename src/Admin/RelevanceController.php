<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Config;
use DeepFind\Db;
use DeepFind\Import\Importer;
use DeepFind\Meili\MeiliClient;
use DeepFind\Search\Relevance;
use DeepFind\Settings;
use DeepFind\Text;

/**
 * Pertinence & classement : ce qui fait remonter un produit dans les résultats.
 *  - ordre des champs (recherche texte) et importance du score métier : appliqués tout de suite au moteur ;
 *  - poids du score métier : calculés à l'import, donc appliqués au prochain import complet (bouton dédié).
 */
final class RelevanceController
{
    public const WEIGHTS = ['w_stock', 'w_margin', 'w_popularity', 'w_new', 'w_manual_boost', 'popularity_ref', 'new_days'];

    public function index(): void
    {
        $sources = Db::all("SELECT id, code, lang FROM sources WHERE kind = 'products' AND active = 1 ORDER BY lang, code");
        View::render('relevance', [
            'active' => 'relevance',
            'fields' => Relevance::fields(),
            'importance' => Relevance::importance(),
            'values' => Settings::all(),
            'sources' => $sources,
            'weightsChangedAt' => Settings::get('weights_changed_at'),
            'lastFull' => (string) (Db::value("SELECT MAX(finished_at) FROM import_runs r JOIN sources s ON s.id = r.source_id WHERE s.kind = 'products' AND r.type = 'full' AND r.status = 'success'") ?? ''),
        ], 'Pertinence & classement');
    }

    public function save(): void
    {
        // 1. Ordre des champs
        $rows = [];
        foreach (array_keys(Relevance::FIELDS) as $i => $f) {
            $rows[] = [
                'field' => $f,
                'enabled' => $f === 'name' || !empty($_POST['fields'][$f]['enabled']),
                'pos' => (float) ($_POST['fields'][$f]['pos'] ?? ($i + 1)) + $i / 100,
            ];
        }
        usort($rows, static fn ($a, $b) => $a['pos'] <=> $b['pos']);
        Settings::set('searchable_fields', Text::json(array_map(static fn ($r) => ['field' => $r['field'], 'enabled' => $r['enabled']], $rows)));

        // 2. Importance du score métier + ruptures en fin de liste
        $imp = (string) ($_POST['biz_importance'] ?? 'low');
        Settings::set('biz_importance', isset(Relevance::IMPORTANCE[$imp]) ? $imp : 'low');
        Settings::set('out_of_stock_last', isset($_POST['out_of_stock_last']) ? '1' : '0');

        // 3. Poids (pris en compte au prochain import complet)
        $weightsChanged = false;
        foreach (self::WEIGHTS as $w) {
            if (!array_key_exists($w, $_POST)) {
                continue;
            }
            $v = (string) max(0, (float) str_replace(',', '.', (string) $_POST[$w]));
            if ($v !== Settings::get($w)) {
                $weightsChanged = true;
                Settings::set($w, $v);
            }
        }
        if ($weightsChanged) {
            Settings::set('weights_changed_at', date('Y-m-d H:i:s'));
        }

        // Application immédiate au moteur (ordre des champs, règles de classement)
        $applied = 0;
        $error = null;
        try {
            $meili = MeiliClient::admin();
            foreach (\DeepFind\Admin\Auth::langs() as $lang) {
                $uid = Config::productIndex($lang);
                if ($meili->getIndex($uid) !== null) {
                    $meili->updateSettings($uid, [
                        'searchableAttributes' => Relevance::searchableAttributes(),
                        'rankingRules' => Relevance::rankingRules(),
                    ]);
                    $applied++;
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $queued = [];
        if (isset($_POST['reindex'])) {
            foreach (Db::all("SELECT id, code FROM sources WHERE kind = 'products' AND active = 1") as $s) {
                if (Db::value("SELECT id FROM import_runs WHERE source_id = ? AND status IN ('queued','running')", [$s['id']]) === null) {
                    $queued[] = '#' . Importer::queue((int) $s['id'], 'full', 'manual') . ' (' . $s['code'] . ')';
                }
            }
        }

        if ($error !== null) {
            Auth::flash('error', 'Réglages enregistrés mais non appliqués au moteur : ' . $error);
        } else {
            Auth::flash('ok', sprintf('Réglages enregistrés et appliqués à %d index (Meilisearch recalcule le classement en arrière-plan, quelques secondes à quelques minutes).', $applied));
        }
        if ($queued !== []) {
            Auth::flash('ok', 'Import complet mis en file d\'attente pour recalculer le score métier : ' . implode(', ', $queued) . '.');
        } elseif ($weightsChanged) {
            Auth::flash('warn', 'Les poids du score métier ont changé : ils s\'appliqueront au prochain import complet (bouton « Enregistrer et recalculer maintenant » pour le lancer tout de suite).');
        }
        App::back('/admin/relevance');
    }
}
