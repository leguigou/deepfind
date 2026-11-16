<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Config;
use DeepFind\Db;
use DeepFind\Meili\MeiliClient;
use DeepFind\Search\IndexSettings;
use DeepFind\Widget\WidgetConfig;

/** Intégration du widget sur le site : layers (affichage), filtres, options de tri, code à ajouter au site. */
final class IntegrationController
{
    public const TABS = ['layers' => 'Layers', 'filters' => 'Filtres', 'sorts' => 'Options de tri'];

    public function index(): void
    {
        $tab = (string) ($_GET['tab'] ?? 'layers');
        $tab = isset(self::TABS[$tab]) ? $tab : 'layers';
        $lang = Auth::lang();
        $layers = WidgetConfig::layers();
        $edit = isset($_GET['layer']) ? WidgetConfig::layer((int) $_GET['layer']) : null;
        $edit ??= WidgetConfig::activeLayer() ?? ($layers[0] ?? null);
        View::render('integration', [
            'active' => 'integration',
            'tab' => $tab,
            'lang' => $lang,
            'layers' => $layers,
            'edit' => $edit,
            'filters' => WidgetConfig::filters($lang),
            'sorts' => WidgetConfig::sorts($lang),
            'sortItems' => WidgetConfig::sortItems($lang),
            'firstKey' => (string) (Db::value('SELECT public_key FROM api_keys WHERE project_id = ? AND active = 1 ORDER BY id LIMIT 1', [\DeepFind\Project::id()]) ?? ''),
            'appUrl' => rtrim((string) Config::get('APP_URL', ''), '/'),
        ], 'Intégration');
    }

    public function createLayer(): void
    {
        $id = WidgetConfig::createLayer((string) ($_POST['name'] ?? ''), (string) ($_POST['type'] ?? 'below'));
        $l = WidgetConfig::layer($id);
        Auth::flash('ok', 'Layer « ' . ($l['name'] ?? '') . ' » créé' . (!empty($l['active']) ? ' et activé.' : '. Activez-le pour l\'utiliser sur le site.'));
        App::back('/admin/integration', ['layer' => $id]);
    }

    public function saveLayer(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $type = (string) ($_POST['type'] ?? '');
        $settings = [
            'input' => $_POST['input'] ?? '', 'container' => $_POST['container'] ?? '',
            'url_state' => isset($_POST['url_state']), 'gap' => $_POST['gap'] ?? 12,
        ];
        if ($type === 'embedded' && trim((string) $settings['container']) === '') {
            Auth::flash('error', 'Layer intégré : indiquez le sélecteur CSS du bloc du site dans lequel afficher les résultats.');
            App::back('/admin/integration', ['layer' => $id]);
            return;
        }
        WidgetConfig::saveLayer($id, (string) ($_POST['name'] ?? ''), $type, $settings);
        Auth::flash('ok', 'Layer enregistré (pris en compte sur le site sous 5 minutes).');
        App::back('/admin/integration', ['layer' => $id]);
    }

    public function activateLayer(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $on = ($_POST['active'] ?? '') === '1';
        WidgetConfig::activateLayer($id, $on);
        Auth::flash('ok', $on ? 'Layer activé (pris en compte sur le site sous 5 minutes).'
            : 'Layer désactivé : le widget reprend l\'affichage par défaut (sous la barre de recherche).');
        App::back('/admin/integration', ['layer' => $id]);
    }

    public function deleteLayer(): void
    {
        WidgetConfig::deleteLayer((int) ($_POST['id'] ?? 0));
        Auth::flash('ok', 'Layer supprimé.');
        App::back('/admin/integration');
    }

    public function saveFilters(): void
    {
        $in = json_decode((string) ($_POST['payload'] ?? '[]'), true);
        WidgetConfig::saveFilters(Auth::lang(), is_array($in) ? $in : []);
        Auth::flash('ok', 'Filtres enregistrés.');
        App::back('/admin/integration', ['tab' => 'filters']);
    }

    public function saveSorts(): void
    {
        $in = json_decode((string) ($_POST['payload'] ?? '{}'), true);
        $in = is_array($in) ? $in : [];
        WidgetConfig::saveSorts(Auth::lang(), (string) ($in['relevance'] ?? ''), is_array($in['items'] ?? null) ? $in['items'] : []);
        $msg = 'Options de tri enregistrées.';
        // Les champs choisis doivent être triables dans le moteur : mis à jour tout de suite si besoin (sinon au prochain import complet)
        try {
            $meili = MeiliClient::admin();
            $updated = false;
            foreach (Auth::langs() as $l) {
                $uid = Config::productIndex($l);
                if ($meili->getIndex($uid) === null) {
                    continue;
                }
                $have = $meili->getSettings($uid)['sortableAttributes'] ?? [];
                if (array_diff(IndexSettings::SORTABLE, $have) !== []) {
                    $meili->updateSettings($uid, ['sortableAttributes' => IndexSettings::SORTABLE]);
                    $updated = true;
                }
            }
            if ($updated) {
                $msg .= ' Le moteur rend les nouveaux champs triables (quelques minutes sur un gros catalogue) ; d\'ici là, ces tris peuvent échouer.';
            }
        } catch (\Throwable $e) {
            Auth::flash('error', 'Le moteur n\'a pas pu être mis à jour (' . $e->getMessage() . ') : les nouveaux tris fonctionneront après le prochain import complet.');
        }
        Auth::flash('ok', $msg);
        App::back('/admin/integration', ['tab' => 'sorts']);
    }
}
