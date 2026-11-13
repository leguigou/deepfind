<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Db;
use DeepFind\Rules\Redirections;
use DeepFind\Rules\RulesRepository;

/** Redirections : nom, statut, redirection automatique, URL de destination, termes (exacts / larges), période facultative. */
final class RedirectionsController
{
    public function index(): void
    {
        $lang = Auth::lang();
        View::render('redirects', ['active' => 'redirects', 'lang' => $lang, 'list' => Redirections::all($lang)], 'Redirections');
    }

    public function edit(): void
    {
        $lang = Auth::lang();
        $id = (int) ($_GET['id'] ?? 0);
        $r = $id > 0 ? Redirections::find($id, $lang) : null;
        if ($id > 0 && $r === null) {
            Auth::flash('error', 'Redirection introuvable.');
            App::back('/admin/redirects');
            return;
        }
        if ($r === null) {
            $term = trim((string) ($_GET['term'] ?? ''));
            $r = ['id' => 0, 'name' => $term !== '' ? 'Redirection « ' . $term . ' »' : '', 'active' => true, 'auto' => true, 'url' => '',
                'terms' => $term !== '' ? [['term' => mb_strtolower($term, 'UTF-8'), 'match' => 'exact']] : [], 'starts_at' => null, 'ends_at' => null];
        }
        View::render('redirect_edit', ['active' => 'redirects', 'r' => $r], $r['id'] ? 'Redirection' : 'Nouvelle redirection');
    }

    public function save(): void
    {
        $lang = Auth::lang();
        $in = json_decode((string) ($_POST['payload'] ?? ''), true);
        [$r, $errors] = is_array($in) ? Redirections::sanitize($in) : [null, ['Formulaire illisible : rechargez la page.']];
        $id = is_array($in) ? (int) ($in['id'] ?? 0) : 0;
        if ($r === null) {
            Auth::flash('error', implode(' ', $errors));
            App::back('/admin/redirects/edit', $id ? ['id' => $id] : []);
            return;
        }
        $id = Redirections::save($lang, $r, $id > 0 ? $id : null);
        Auth::flash('ok', sprintf('Redirection « %s » enregistrée%s.', $r['name'], $r['active'] ? ' (effet immédiat)' : ', inactive'));
        App::back('/admin/redirects/edit', ['id' => $id]);
    }

    public function toggle(): void
    {
        Db::exec('UPDATE redirections SET active = 1 - active, updated_at = ? WHERE id = ? AND project_id = ? AND lang = ?',
            [date('Y-m-d H:i:s'), (int) ($_POST['id'] ?? 0), \DeepFind\Project::id(), Auth::lang()]);
        \DeepFind\CacheVersion::bump();
        App::backTo('/admin/redirects');
    }

    public function delete(): void
    {
        Redirections::delete((int) ($_POST['id'] ?? 0), Auth::lang());
        Auth::flash('ok', 'Redirection supprimée.');
        App::back('/admin/redirects');
    }

    /** Import CSV (requete;url) : chaque ligne devient une redirection automatique en correspondance exacte. */
    public function import(): void
    {
        $f = $_FILES['csv'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 5 * 1024 * 1024) {
            Auth::flash('error', 'Fichier CSV manquant, invalide ou trop gros (5 Mo maximum).');
        } else {
            [$n, $errors] = RulesRepository::importRedirects($f['tmp_name'], Auth::lang(), isset($_POST['replace']));
            Auth::flash($errors ? 'warn' : 'ok', sprintf('%d redirection(s) importée(s).', $n) . ($errors ? ' ' . implode(' ; ', array_slice($errors, 0, 5)) : ''));
        }
        App::back('/admin/redirects');
    }
}
