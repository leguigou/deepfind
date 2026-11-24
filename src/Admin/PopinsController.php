<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Http\Response;
use DeepFind\Rules\Banners;
use DeepFind\Rules\PageRules;
use DeepFind\Rules\Popins;

/** Recommandations › Popins : liste (priorité, affichages / clics / fermetures), édition, téléversement d'images. */
final class PopinsController
{
    private const DAYS = [7 => '7 jours', 30 => '30 jours', 90 => '90 jours'];

    public function index(): void
    {
        $lang = Auth::lang();
        $days = (int) ($_GET['days'] ?? 30);
        $days = isset(self::DAYS[$days]) ? $days : 30;
        $list = Popins::all($lang);
        View::render('popins', [
            'active' => 'popins', 'lang' => $lang, 'list' => $list, 'days' => $days, 'dayChoices' => self::DAYS,
            'stats' => Popins::stats(array_column($list, 'id'), $days),
        ], 'Popins');
    }

    public function edit(): void
    {
        $lang = Auth::lang();
        $id = (int) ($_GET['id'] ?? 0);
        $p = $id > 0 ? Popins::find($id, $lang) : null;
        if ($id > 0 && $p === null) {
            Auth::flash('error', 'Popin introuvable.');
            App::back('/admin/popins');
            return;
        }
        $p ??= Popins::blank();
        View::render('popin_edit', [
            'active' => 'popins', 'p' => $p, 'maxKb' => (int) (Banners::MAX_UPLOAD / 1024), 'urlOps' => PageRules::URL_OPS,
            'stats' => $p['id'] ? Popins::stats([$p['id']], 30)[$p['id']] : null,
        ], $p['id'] ? 'Popin « ' . $p['name'] . ' »' : 'Nouvelle popin');
    }

    public function save(): void
    {
        $lang = Auth::lang();
        $in = json_decode((string) ($_POST['payload'] ?? ''), true);
        [$p, $errors] = is_array($in) ? Popins::sanitize($in) : [null, ['Formulaire illisible : rechargez la page.']];
        $id = is_array($in) ? (int) ($in['id'] ?? 0) : 0;
        if ($p === null) {
            Auth::flash('error', implode(' ', $errors));
            App::back('/admin/popins/edit', $id ? ['id' => $id] : []);
            return;
        }
        $id = Popins::save($lang, $p, $id > 0 ? $id : null);
        $notes = [];
        if ($p['mode'] === 'code' && trim((string) ($in['html'] ?? '')) !== $p['html']) {
            $notes[] = 'Le code HTML a été nettoyé (scripts, événements et liens non sûrs retirés).';
        }
        if ($p['reset_memory']) {
            $notes[] = 'Elle sera de nouveau proposée aux visiteurs qui l\'avaient fermée.';
        }
        Auth::flash('ok', sprintf('Popin « %s » enregistrée%s.', $p['name'], $p['active'] ? ' (visible sur le site d\'ici quelques minutes)' : ', inactive')
            . ($notes ? ' ' . implode(' ', $notes) : ''));
        App::back('/admin/popins/edit', ['id' => $id]);
    }

    public function toggle(): void
    {
        $lang = Auth::lang();
        $p = Popins::find((int) ($_POST['id'] ?? 0), $lang);
        if ($p !== null) {
            Popins::setActive($p['id'], $lang, !$p['active']);
        }
        App::backTo('/admin/popins');
    }

    public function reorder(): void
    {
        Popins::reorder(Auth::lang(), array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))));
        Auth::flash('ok', 'Ordre enregistré : sur une page, la première popin de la liste qui correspond s\'affiche.');
        App::back('/admin/popins');
    }

    public function delete(): void
    {
        Popins::delete((int) ($_POST['id'] ?? 0), Auth::lang());
        Auth::flash('ok', 'Popin supprimée.');
        App::back('/admin/popins');
    }

    /** Aperçu direct : la popin du formulaire (non enregistrée), nettoyée comme à l'enregistrement, prête pour le widget. JSON. */
    public function preview(): void
    {
        $in = json_decode((string) ($_POST['payload'] ?? ''), true);
        if (is_array($in) && trim((string) ($in['name'] ?? '')) === '') {
            $in['name'] = 'Aperçu';
        }
        [$p, $errors] = is_array($in) ? Popins::sanitize($in) : [null, ['Formulaire illisible : rechargez la page.']];
        if ($p === null) {
            Response::json(['error' => implode(' ', $errors)], 422);
            return;
        }
        Response::json(['item' => Popins::payload(['id' => (int) ($in['id'] ?? 0) ?: 1, 'rev' => 1] + $p)]);
    }

    /** Téléversement d'une image (JSON : url ou erreur) ; même stockage que les bannières. */
    public function upload(): void
    {
        [$url, $error] = Banners::storeUpload($_FILES['image'] ?? []);
        Response::json($url !== null ? ['url' => $url] : ['error' => $error], $url !== null ? 200 : 422);
    }
}
