<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Config;
use DeepFind\Db;
use DeepFind\Http\Response;
use DeepFind\Meili\MeiliClient;
use DeepFind\Rules\BoostRules;

/** Règles de boost : nom, statut, coefficient, termes de recherche (exacts / larges) et conditions sur les produits. */
final class BoostRulesController
{
    public function index(): void
    {
        $lang = Auth::lang();
        $fields = [];
        foreach (BoostRules::fields($lang) as $code => [$label, $type]) {
            $fields[] = ['code' => $code, 'label' => $label, 'type' => $type, 'static' => BoostRules::STATIC_VALUES[$code] ?? null];
        }
        View::render('boost_rules', [
            'active' => 'boost_rules', 'lang' => $lang, 'rules' => BoostRules::all($lang), 'fields' => $fields,
        ], 'Règles de boost');
    }

    public function save(): void
    {
        $lang = Auth::lang();
        $in = json_decode((string) ($_POST['payload'] ?? ''), true);
        [$rule, $errors] = is_array($in) ? BoostRules::sanitize($in, $lang) : [null, ['Formulaire illisible : rechargez la page.']];
        if ($rule === null) {
            Auth::flash('error', implode(' ', $errors));
        } else {
            $id = (int) ($in['id'] ?? 0);
            BoostRules::save($lang, $rule, $id > 0 ? $id : null);
            Auth::flash('ok', sprintf('Règle « %s » enregistrée%s (effet immédiat).', $rule['name'], $rule['active'] ? '' : ', inactive'));
        }
        App::backTo('/admin/boost-rules');
    }

    public function toggle(): void
    {
        Db::exec('UPDATE boost_rules SET active = 1 - active, updated_at = ? WHERE id = ? AND project_id = ? AND lang = ?',
            [date('Y-m-d H:i:s'), (int) ($_POST['id'] ?? 0), \DeepFind\Project::id(), Auth::lang()]);
        \DeepFind\CacheVersion::bump();
        App::backTo('/admin/boost-rules');
    }

    public function delete(): void
    {
        Db::exec('DELETE FROM boost_rules WHERE id = ? AND project_id = ? AND lang = ?', [(int) ($_POST['id'] ?? 0), \DeepFind\Project::id(), Auth::lang()]);
        \DeepFind\CacheVersion::bump();
        Auth::flash('ok', 'Règle supprimée.');
        App::backTo('/admin/boost-rules');
    }

    /** Valeurs proposées pour un champ (compteurs de filtres du moteur), en JSON. */
    public function values(): void
    {
        $lang = Auth::lang();
        $field = (string) ($_GET['field'] ?? '');
        $fields = BoostRules::fields($lang);
        if (!isset($fields[$field]) || $fields[$field][1] !== 'list' || $field === 'sku') {
            Response::json(['values' => []]);
            return;
        }
        if (isset(BoostRules::STATIC_VALUES[$field])) {
            $out = [];
            foreach (BoostRules::STATIC_VALUES[$field] as $v => $label) {
                $out[] = ['value' => (string) $v, 'label' => $label];
            }
            Response::json(['values' => $out]);
            return;
        }
        $values = [];
        try {
            $res = MeiliClient::search()->multiSearch([['indexUid' => Config::productIndex($lang), 'q' => '', 'limit' => 0, 'facets' => [$field]]]);
            foreach ($res[0]['facetDistribution'][$field] ?? [] as $v => $n) {
                $values[] = ['value' => (string) $v, 'label' => (string) $v, 'count' => (int) $n];
            }
        } catch (\Throwable) {
            // index absent : saisie libre uniquement
        }
        usort($values, static fn ($a, $b) => strnatcasecmp($a['value'], $b['value']));
        Response::json(['values' => $values]);
    }
}
