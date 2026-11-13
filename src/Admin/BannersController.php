<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Db;
use DeepFind\Http\Response;
use DeepFind\Rules\Banners;

/** Bannières en haut des résultats : liste avec impressions / clics / CTR, édition, téléversement d'images. */
final class BannersController
{
    private const DAYS = [7 => '7 jours', 30 => '30 jours', 90 => '90 jours'];

    public function index(): void
    {
        $lang = Auth::lang();
        $days = (int) ($_GET['days'] ?? 30);
        $days = isset(self::DAYS[$days]) ? $days : 30;
        $list = Banners::all($lang);
        View::render('banners', [
            'active' => 'banners', 'lang' => $lang, 'list' => $list, 'days' => $days, 'dayChoices' => self::DAYS,
            'stats' => Banners::stats($lang, array_column($list, 'id'), $days),
            'logging' => \DeepFind\Settings::int('log_searches') === 1,
        ], 'Bannières');
    }

    public function edit(): void
    {
        $lang = Auth::lang();
        $id = (int) ($_GET['id'] ?? 0);
        $b = $id > 0 ? Banners::find($id, $lang) : null;
        if ($id > 0 && $b === null) {
            Auth::flash('error', 'Bannière introuvable.');
            App::back('/admin/banners');
            return;
        }
        $b ??= ['id' => 0, 'name' => '', 'active' => true, 'is_default' => false, 'starts_at' => null, 'ends_at' => null, 'terms' => [],
            'mode' => 'visual', 'image_desktop' => '', 'image_mobile' => '', 'alt' => '', 'link_url' => '', 'new_window' => false, 'html' => ''];
        View::render('banner_edit', [
            'active' => 'banners', 'b' => $b, 'maxKb' => (int) (Banners::MAX_UPLOAD / 1024),
            'stats' => $b['id'] ? Banners::stats($lang, [$b['id']], 30)[$b['id']] : null,
        ], $b['id'] ? 'Bannière' : 'Nouvelle bannière');
    }

    public function save(): void
    {
        $lang = Auth::lang();
        $in = json_decode((string) ($_POST['payload'] ?? ''), true);
        [$b, $errors] = is_array($in) ? Banners::sanitize($in, $lang) : [null, ['Formulaire illisible : rechargez la page.']];
        $id = is_array($in) ? (int) ($in['id'] ?? 0) : 0;
        if ($b === null) {
            Auth::flash('error', implode(' ', $errors));
            App::back('/admin/banners/edit', $id ? ['id' => $id] : []);
            return;
        }
        $id = Banners::save($lang, $b, $id > 0 ? $id : null);
        $note = $b['mode'] === 'code' && trim((string) ($in['html'] ?? '')) !== $b['html'] ? ' Le code HTML a été nettoyé (scripts, événements et liens non sûrs retirés).' : '';
        Auth::flash('ok', sprintf('Bannière « %s » enregistrée%s.', $b['name'], $b['active'] ? ' (effet immédiat)' : ', inactive') . $note);
        App::back('/admin/banners/edit', ['id' => $id]);
    }

    public function toggle(): void
    {
        Db::exec('UPDATE banners SET active = 1 - active, updated_at = ? WHERE id = ? AND project_id = ? AND lang = ?',
            [date('Y-m-d H:i:s'), (int) ($_POST['id'] ?? 0), \DeepFind\Project::id(), Auth::lang()]);
        \DeepFind\CacheVersion::bump();
        App::backTo('/admin/banners');
    }

    public function delete(): void
    {
        Banners::delete((int) ($_POST['id'] ?? 0), Auth::lang());
        Auth::flash('ok', 'Bannière supprimée.');
        App::back('/admin/banners');
    }

    /** Téléversement d'une image (JSON : url ou erreur). */
    public function upload(): void
    {
        [$url, $error] = Banners::storeUpload($_FILES['image'] ?? []);
        Response::json($url !== null ? ['url' => $url] : ['error' => $error], $url !== null ? 200 : 422);
    }
}
