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
        View::render('integration', [
            'active' => 'integration',
            'tab' => $tab,
            'lang' => $lang,
            'layers' => $layers,
            'filters' => WidgetConfig::filters($lang),
            'sorts' => WidgetConfig::sorts($lang),
            'sortItems' => WidgetConfig::sortItems($lang),
            'firstKey' => (string) (Db::value('SELECT public_key FROM api_keys WHERE project_id = ? AND active = 1 ORDER BY id LIMIT 1', [\DeepFind\Project::id()]) ?? ''),
            'appUrl' => rtrim((string) Config::get('APP_URL', ''), '/'),
        ], 'Intégration');
    }

    /** Paramètres d'un layer : options, identité de marque, personnalisation avancée. */
    public function layer(): void
    {
        $l = WidgetConfig::layer((int) ($_GET['id'] ?? 0));
        if ($l === null) {
            Auth::flash('error', 'Layer introuvable.');
            App::back('/admin/integration');
            return;
        }
        View::render('integration_layer', ['active' => 'integration', 'layer' => $l], 'Paramètres du layer');
    }

    public function createLayer(): void
    {
        $id = WidgetConfig::createLayer((string) ($_POST['name'] ?? ''), (string) ($_POST['type'] ?? 'below'));
        $l = WidgetConfig::layer($id);
        Auth::flash('ok', 'Layer « ' . ($l['name'] ?? '') . ' » créé' . (!empty($l['active']) ? ' et activé.' : '. Activez-le pour l\'utiliser sur le site.'));
        App::back('/admin/integration/layer', ['id' => $id]);
    }

    public function saveLayer(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $l = WidgetConfig::layer($id);
        if ($l === null) {
            App::back('/admin/integration');
            return;
        }
        $settings = array_intersect_key($_POST, array_flip(['input', 'container', 'gap', 'min_chars', 'logo', 'color_primary', 'color_contrast', 'color_text', 'css', 'card_template']));
        $settings['url_state'] = isset($_POST['url_state']);
        if ($l['type'] === 'embedded' && trim((string) ($settings['container'] ?? '')) === '') {
            Auth::flash('error', 'Layer intégré : indiquez le sélecteur CSS du bloc du site dans lequel afficher les résultats.');
            App::back('/admin/integration/layer', ['id' => $id]);
            return;
        }
        $logo = trim((string) ($settings['logo'] ?? ''));
        WidgetConfig::saveLayer($id, (string) ($_POST['name'] ?? ''), $l['type'], $settings);
        $saved = WidgetConfig::layer($id)['settings'];
        if ($logo !== '' && $saved['logo'] === '') {
            Auth::flash('error', 'Logo ignoré : indiquez une adresse complète en https://');
        }
        if (trim((string) ($settings['card_template'] ?? '')) !== '' && $saved['card_template'] !== trim((string) $settings['card_template'])) {
            Auth::flash('warn', 'Modèle de carte produit : des balises ou attributs non autorisés (scripts, événements, liens javascript…) ont été retirés.');
        }
        Auth::flash('ok', 'Layer enregistré (pris en compte sur le site sous 5 minutes).');
        App::back('/admin/integration/layer', ['id' => $id]);
    }

    public function activateLayer(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $on = ($_POST['active'] ?? '') === '1';
        WidgetConfig::activateLayer($id, $on);
        Auth::flash('ok', $on ? 'Layer activé (pris en compte sur le site sous 5 minutes).'
            : 'Layer désactivé : le widget reprend l\'affichage par défaut (sous la barre de recherche).');
        App::backTo('/admin/integration');
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
