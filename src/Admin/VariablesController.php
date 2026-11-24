<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Widget\WidgetConfig;
use DeepFind\Widget\WidgetTexts;

/** Paramètres › Variables : variables système (lecture seule), textes du widget et variables personnalisées, par langue. */
final class VariablesController
{
    public function index(): void
    {
        $lang = Auth::lang();
        View::render('variables', [
            'active' => 'variables', 'lang' => $lang, 'defaults' => WidgetTexts::defaults($lang), 'overrides' => WidgetTexts::overrides($lang),
            'groups' => WidgetTexts::GROUPS, 'system' => WidgetConfig::CARD_VARS, 'links' => WidgetConfig::LINK_VARS,
            'custom' => WidgetTexts::customVars(), 'values' => WidgetTexts::customValues($lang), 'reserved' => WidgetTexts::reserved(),
        ], 'Variables');
    }

    public function save(): void
    {
        $lang = Auth::lang();
        $n = WidgetTexts::saveTexts($lang, (array) ($_POST['texts'] ?? []));
        $rows = [];
        foreach ((array) ($_POST['vars'] ?? []) as $r) {
            if (is_array($r)) {
                $rows[] = $r;
            }
        }
        $errors = WidgetTexts::saveCustom($lang, $rows);
        if ($errors !== []) {
            Auth::flash('error', implode(' ', $errors));
        }
        Auth::flash('ok', sprintf('Variables enregistrées (langue %s) : %d texte(s) modifié(s), %d variable(s) personnalisée(s). Visible sur le site d\'ici quelques minutes.',
            strtoupper($lang), $n, count(WidgetTexts::customVars())));
        App::back('/admin/variables');
    }
}
