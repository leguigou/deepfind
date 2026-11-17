<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Rules\BoostRules;
use DeepFind\Rules\CustomResults;
use DeepFind\Rules\PageRules;

/** Recommandations › Règles : résultats selon la page du site (adresse), sans terme de recherche ni période. */
final class PageRulesController
{
    public function index(): void
    {
        $lang = Auth::lang();
        View::render('page_rules', ['active' => 'page_rules', 'lang' => $lang, 'list' => PageRules::all($lang)], 'Règles selon la page');
    }

    public function edit(): void
    {
        $lang = Auth::lang();
        $id = (int) ($_GET['id'] ?? 0);
        $rule = $id > 0 ? PageRules::find($id, $lang) : null;
        if ($id > 0 && $rule === null) {
            Auth::flash('error', 'Règle introuvable.');
            App::back('/admin/page-rules');
            return;
        }
        $rule ??= ['id' => 0, 'name' => '', 'active' => true, 'urls' => [['match' => 'contains', 'text' => '']], 'action' => 'prefilter',
            'label' => '', 'match_all' => true, 'conditions' => []];
        $fields = [];
        foreach (CustomResults::fields($lang) as $code => [$label, $type]) {
            $fields[] = ['code' => $code, 'label' => $label, 'type' => $type, 'static' => BoostRules::STATIC_VALUES[$code] ?? null];
        }
        View::render('page_rule_edit', ['active' => 'page_rules', 'rule' => $rule, 'fields' => $fields],
            $rule['id'] ? 'Règle « ' . $rule['name'] . ' »' : 'Nouvelle règle');
    }

    public function save(): void
    {
        $lang = Auth::lang();
        $in = json_decode((string) ($_POST['payload'] ?? ''), true);
        [$rule, $errors] = is_array($in) ? PageRules::sanitize($in, $lang) : [null, ['Formulaire illisible : rechargez la page.']];
        $id = is_array($in) ? (int) ($in['id'] ?? 0) : 0;
        if ($rule === null) {
            Auth::flash('error', implode(' ', $errors));
            App::back('/admin/page-rules/edit', $id ? ['id' => $id] : []);
            return;
        }
        $id = PageRules::save($lang, $rule, $id > 0 ? $id : null);
        Auth::flash('ok', sprintf('Règle « %s » enregistrée%s.', $rule['name'], $rule['active'] ? ' (effet immédiat)' : ', inactive'));
        App::back('/admin/page-rules/edit', ['id' => $id]);
    }

    public function toggle(): void
    {
        $lang = Auth::lang();
        $rule = PageRules::find((int) ($_POST['id'] ?? 0), $lang);
        if ($rule !== null) {
            PageRules::setActive($rule['id'], $lang, !$rule['active']);
        }
        App::backTo('/admin/page-rules');
    }

    public function reorder(): void
    {
        PageRules::reorder(Auth::lang(), array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))));
        Auth::flash('ok', 'Ordre des règles enregistré : la première règle de la liste qui correspond à la page s\'applique.');
        App::back('/admin/page-rules');
    }

    public function delete(): void
    {
        PageRules::delete((int) ($_POST['id'] ?? 0), Auth::lang());
        Auth::flash('ok', 'Règle supprimée.');
        App::back('/admin/page-rules');
    }
}
