<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Config;
use DeepFind\ConfigTransfer;
use DeepFind\Db;
use DeepFind\Meili\MeiliClient;
use DeepFind\Project;

/** Projets : sélection, création, renommage, suppression, copie de configuration et sauvegardes. */
final class ProjectsController
{
    public function switch(): void
    {
        $id = (int) ($_POST['project_id'] ?? 0);
        if (Project::find($id) !== null) {
            $_SESSION['project_id'] = $id;
            Project::use($id);
            Auth::flash('ok', 'Projet actif : ' . Project::current()['name']);
        }
        $back = (string) ($_POST['back'] ?? '');
        // Les pages de détail (une source, un import…) appartiennent à l'ancien projet : retour à la liste
        $back = preg_replace('#^(/admin/(?:sources|imports))/[a-z]+.*$#', '$1', $back) ?? $back;
        $_POST['back'] = $back;
        App::backTo('/admin');
    }

    public function index(): void
    {
        $rows = [];
        foreach (Project::all() as $p) {
            $pid = $p['id'];
            $rows[] = $p + [
                'sources' => (int) Db::value('SELECT COUNT(*) FROM sources WHERE project_id = ?', [$pid]),
                'keys' => (int) Db::value('SELECT COUNT(*) FROM api_keys WHERE project_id = ? AND active = 1', [$pid]),
                'searches' => (int) Db::value('SELECT COUNT(*) FROM search_logs WHERE project_id = ? AND created_at >= NOW() - INTERVAL 7 DAY AND is_final = 1', [$pid]),
                'last_import' => (string) (Db::value("SELECT MAX(r.finished_at) FROM import_runs r JOIN sources s ON s.id = r.source_id WHERE s.project_id = ? AND r.status = 'success'", [$pid]) ?? ''),
            ];
        }
        View::render('projects', [
            'active' => 'projects', 'rows' => $rows,
            'snapshots' => Db::all('SELECT id, label, created_at FROM config_snapshots WHERE project_id = ? ORDER BY id DESC LIMIT 30', [Project::id()]),
        ], 'Projets');
    }

    public function create(): void
    {
        $code = strtolower(trim((string) ($_POST['code'] ?? '')));
        try {
            $id = Project::create($code, (string) ($_POST['name'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            Auth::flash('error', $e->getMessage());
            App::back('/admin/projects');
            return;
        }
        $from = (int) ($_POST['copy_from'] ?? 0);
        if ($from > 0 && Project::find($from) !== null) {
            ConfigTransfer::apply($id, ConfigTransfer::export($from), array_keys(ConfigTransfer::SECTIONS), true);
            Auth::flash('ok', sprintf('Projet créé avec la configuration de « %s ». Ses flux sont inactifs : lancez un import complet depuis « Flux & mapping » (et activez-les pour les imports planifiés).', Project::find($from)['name']));
        } else {
            Auth::flash('ok', 'Projet créé (vide). Configurez ses flux dans « Flux & mapping ».');
        }
        $_SESSION['project_id'] = $id;
        App::back('/admin/sources');
    }

    public function rename(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if (Project::find($id) !== null && $name !== '') {
            Db::update('projects', ['name' => \DeepFind\Text::truncate($name, 100)], 'id = ?', [$id]);
            Project::reload();
            Auth::flash('ok', 'Projet renommé.');
        }
        App::back('/admin/projects');
    }

    public function delete(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $p = Project::find($id);
        if ($p === null) {
            App::back('/admin/projects');
            return;
        }
        if (count(Project::all()) <= 1) {
            Auth::flash('error', 'Impossible de supprimer le dernier projet.');
            App::back('/admin/projects');
            return;
        }
        if (trim((string) ($_POST['confirm'] ?? '')) !== $p['code']) {
            Auth::flash('error', 'Pour confirmer, saisissez exactement le code du projet (« ' . $p['code'] . ' »).');
            App::back('/admin/projects');
            return;
        }
        // Index Meilisearch du projet
        $meili = MeiliClient::admin();
        $deleted = 0;
        Project::with($id, static function () use ($meili, &$deleted): void {
            foreach (array_filter(array_map('trim', explode(',', (string) Config::get('APP_LANGS', 'fr')))) as $lang) {
                foreach ([Config::productIndex($lang), Config::postIndex($lang)] as $uid) {
                    foreach ([$uid, $uid . '_tmp'] as $u) {
                        try {
                            if ($meili->deleteIndex($u) !== null) {
                                $deleted++;
                            }
                        } catch (\Throwable) {
                        }
                    }
                }
            }
        });
        Db::exec('DELETE r FROM import_runs r JOIN sources s ON s.id = r.source_id WHERE s.project_id = ?', [$id]);
        foreach (['sources', 'synonyms', 'query_rules', 'redirects', 'product_boosts', 'boost_rules', 'custom_results', 'search_logs', 'click_logs', 'api_keys', 'project_settings', 'config_snapshots'] as $t) {
            Db::exec("DELETE FROM `$t` WHERE project_id = ?", [$id]);
        }
        Db::exec('DELETE FROM settings WHERE name IN (?, ?)', ['data_version_' . $id, 'scores_scheduled_at_' . $id]);
        @unlink(Config::varDir('cache') . '/data_version_' . $id);
        Db::exec('DELETE FROM projects WHERE id = ?', [$id]);
        Project::reload();
        if ((int) ($_SESSION['project_id'] ?? 0) === $id) {
            $_SESSION['project_id'] = Project::defaultId();
        }
        Auth::flash('ok', sprintf('Projet « %s » supprimé (%d index Meilisearch supprimé(s)).', $p['name'], $deleted));
        App::back('/admin/projects');
    }

    /** Copie de configuration : formulaire, puis résumé des changements, puis confirmation. */
    public function copy(): void
    {
        $projects = Project::all();
        $from = (int) ($_GET['from'] ?? Project::id());
        $to = (int) ($_GET['to'] ?? 0);
        if ($to === 0 || $to === $from) {
            foreach ($projects as $p) {
                if ($p['id'] !== $from) {
                    $to = $p['id'];
                    break;
                }
            }
        }
        $sections = array_values(array_intersect((array) ($_GET['sections'] ?? []), array_keys(ConfigTransfer::SECTIONS)));
        $summary = null;
        if (isset($_GET['preview']) && $sections !== [] && isset($projects[$from], $projects[$to]) && $from !== $to) {
            $summary = ConfigTransfer::summary(ConfigTransfer::export($from), $to, $sections);
        }
        View::render('project_copy', [
            'active' => 'projects', 'projects' => $projects, 'from' => $from, 'to' => $to,
            'sections' => $sections ?: array_keys(ConfigTransfer::SECTIONS), 'chosen' => $sections,
            'includeUrls' => !empty($_GET['include_urls']), 'summary' => $summary,
        ], 'Copier une configuration');
    }

    public function copyApply(): void
    {
        $from = (int) ($_POST['from'] ?? 0);
        $to = (int) ($_POST['to'] ?? 0);
        $sections = array_values(array_intersect((array) ($_POST['sections'] ?? []), array_keys(ConfigTransfer::SECTIONS)));
        $pf = Project::find($from);
        $pt = Project::find($to);
        if ($pf === null || $pt === null || $from === $to || $sections === []) {
            Auth::flash('error', 'Choisissez deux projets différents et au moins un élément à copier.');
            App::back('/admin/projects/copy');
            return;
        }
        $snap = ConfigTransfer::snapshot($to, 'Avant copie depuis « ' . $pf['name'] . ' »');
        $msgs = ConfigTransfer::apply($to, ConfigTransfer::export($from), $sections, !empty($_POST['include_urls']));
        Auth::flash('ok', sprintf('Configuration de « %s » copiée vers « %s ». Sauvegarde #%d créée (restaurable dans « Projets »).', $pf['name'], $pt['name'], $snap));
        foreach ($msgs as $m) {
            Auth::flash('info', $m);
        }
        if (in_array('relevance', $sections, true) || in_array('boosts', $sections, true) || in_array('sources', $sections, true)) {
            Auth::flash('warn', 'Pensez à recalculer les scores (Pertinence › « Enregistrer et recalculer les scores ») ou à relancer un import complet dans « ' . $pt['name'] . ' » pour appliquer les poids, boosts et mappings copiés.');
        }
        $_SESSION['project_id'] = $to;
        App::back('/admin/projects');
    }

    public function restore(): void
    {
        try {
            $msgs = ConfigTransfer::restore((int) ($_POST['id'] ?? 0), Project::id());
            Auth::flash('ok', 'Sauvegarde restaurée (la configuration précédente a elle-même été sauvegardée).');
            foreach ($msgs as $m) {
                Auth::flash('info', $m);
            }
        } catch (\Throwable $e) {
            Auth::flash('error', $e->getMessage());
        }
        App::back('/admin/projects');
    }

    public function snapshotNow(): void
    {
        $id = ConfigTransfer::snapshot(Project::id(), trim((string) ($_POST['label'] ?? '')) ?: 'Sauvegarde manuelle');
        Auth::flash('ok', 'Sauvegarde #' . $id . ' créée.');
        App::back('/admin/projects');
    }

    /** Téléchargement de la configuration du projet (JSON), pour archive ou pour une autre installation. */
    public function export(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="deepfind-config-' . Project::current()['code'] . '-' . date('Ymd-His') . '.json"');
        echo json_encode(ConfigTransfer::export(Project::id()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
