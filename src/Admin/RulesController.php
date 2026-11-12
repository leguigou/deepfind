<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Config;
use DeepFind\Db;
use DeepFind\Meili\MeiliClient;
use DeepFind\Rules\RulesRepository;

/** Synonymes, règles de résultats (épingler / remonter / masquer) et redirections. */
final class RulesController
{
    // ------------------------------------------------------------ Synonymes

    public function synonyms(): void
    {
        $lang = Auth::lang();
        View::render('synonyms', [
            'active' => 'synonyms', 'lang' => $lang,
            'rows' => Db::all('SELECT * FROM synonyms WHERE lang = ? ORDER BY term', [$lang]),
        ], 'Synonymes & alias');
    }

    public function synonymAdd(): void
    {
        $term = trim((string) ($_POST['term'] ?? ''));
        $syn = trim(str_replace(',', '|', (string) ($_POST['synonyms'] ?? '')));
        if ($term === '' || $syn === '') {
            Auth::flash('error', 'Terme et synonymes obligatoires.');
        } else {
            RulesRepository::addSynonym(Auth::lang(), ($_POST['type'] ?? '') === 'alias' ? 'alias' : 'equivalent', $term, $syn);
            $this->sync(Auth::lang());
        }
        $this->backTo('/admin/synonyms');
    }

    public function synonymDelete(): void
    {
        Db::exec('DELETE FROM synonyms WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        $this->sync(Auth::lang());
        App::back('/admin/synonyms');
    }

    public function synonymImport(): void
    {
        $file = $this->upload();
        if ($file !== null) {
            [$n, $errors] = RulesRepository::importSynonyms($file, Auth::lang(), isset($_POST['replace']));
            $this->report($n, $errors);
            $this->sync(Auth::lang());
        }
        App::back('/admin/synonyms');
    }

    public function synonymSync(): void
    {
        $this->sync(Auth::lang());
        App::back('/admin/synonyms');
    }

    /** Envoie les synonymes aux index en ligne (prise en compte en quelques secondes). */
    private function sync(string $lang): void
    {
        $synonyms = RulesRepository::meiliSynonyms($lang);
        $meili = MeiliClient::admin();
        $done = 0;
        try {
            foreach ([Config::productIndex($lang), Config::postIndex($lang)] as $uid) {
                if ($meili->getIndex($uid) !== null) {
                    $meili->updateSettings($uid, ['synonyms' => $synonyms === [] ? new \stdClass() : $synonyms]);
                    $done++;
                }
            }
            RulesRepository::bumpVersion();
            Auth::flash('ok', sprintf('%d entrée(s) de synonymes envoyée(s) à %d index.', count($synonyms), $done));
        } catch (\Throwable $e) {
            Auth::flash('error', 'Synonymes enregistrés mais non envoyés au moteur : ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------ Règles

    public function rules(): void
    {
        $lang = Auth::lang();
        View::render('rules', [
            'active' => 'rules', 'lang' => $lang, 'actions' => RulesRepository::ACTIONS,
            'rows' => Db::all('SELECT * FROM query_rules WHERE lang = ? ORDER BY query, action, position, id', [$lang]),
        ], 'Règles de résultats');
    }

    public function ruleAdd(): void
    {
        $q = trim((string) ($_POST['query'] ?? ''));
        $sku = trim((string) ($_POST['sku'] ?? ''));
        $action = (string) ($_POST['action'] ?? '');
        if ($q === '' || $sku === '' || !isset(RulesRepository::ACTIONS[$action])) {
            Auth::flash('error', 'Requête, SKU et action obligatoires.');
        } else {
            foreach (preg_split('/[\s,;]+/', $sku) ?: [] as $i => $one) {
                if ($one !== '') {
                    $pos = ($_POST['position'] ?? '') !== '' ? (int) $_POST['position'] + ($action === 'pin' ? $i : 0) : null;
                    RulesRepository::addRule(Auth::lang(), $q, $one, $action, $pos, (string) ($_POST['starts_at'] ?? ''), (string) ($_POST['ends_at'] ?? ''));
                }
            }
            RulesRepository::bumpVersion();
            Auth::flash('ok', 'Règle ajoutée (active immédiatement).');
        }
        App::back('/admin/rules');
    }

    public function ruleDelete(): void
    {
        Db::exec('DELETE FROM query_rules WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        RulesRepository::bumpVersion();
        App::back('/admin/rules');
    }

    public function ruleImport(): void
    {
        $file = $this->upload();
        if ($file !== null) {
            [$n, $errors] = RulesRepository::importRules($file, Auth::lang(), isset($_POST['replace']));
            RulesRepository::bumpVersion();
            $this->report($n, $errors);
        }
        App::back('/admin/rules');
    }

    // ------------------------------------------------------------ Redirections

    public function redirects(): void
    {
        $lang = Auth::lang();
        View::render('redirects', [
            'active' => 'redirects', 'lang' => $lang,
            'rows' => Db::all('SELECT * FROM redirects WHERE lang = ? ORDER BY query', [$lang]),
        ], 'Redirections');
    }

    public function redirectAdd(): void
    {
        $q = trim((string) ($_POST['query'] ?? ''));
        $url = trim((string) ($_POST['url'] ?? ''));
        if ($q === '' || !preg_match('#^https?://#i', $url)) {
            Auth::flash('error', 'Requête obligatoire et URL commençant par http(s)://');
        } else {
            RulesRepository::addRedirect(Auth::lang(), $q, $url);
            RulesRepository::bumpVersion();
            Auth::flash('ok', 'Redirection enregistrée.');
        }
        $this->backTo('/admin/redirects');
    }

    public function redirectDelete(): void
    {
        Db::exec('DELETE FROM redirects WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        RulesRepository::bumpVersion();
        App::back('/admin/redirects');
    }

    public function redirectImport(): void
    {
        $file = $this->upload();
        if ($file !== null) {
            [$n, $errors] = RulesRepository::importRedirects($file, Auth::lang(), isset($_POST['replace']));
            RulesRepository::bumpVersion();
            $this->report($n, $errors);
        }
        App::back('/admin/redirects');
    }

    // ------------------------------------------------------------

    /** Retour vers la page d'origine (ex. statistiques) si elle est indiquée et interne à l'extranet. */
    private function backTo(string $default): void
    {
        $back = (string) ($_POST['back'] ?? '');
        if (preg_match('#^/admin/[a-z/]*(\?[\w=&%.\-]*)?$#', $back)) {
            \DeepFind\Http\Response::redirect(url($back));
            return;
        }
        App::back($default);
    }

    private function upload(): ?string
    {
        $f = $_FILES['csv'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            Auth::flash('error', 'Fichier CSV manquant ou invalide.');
            return null;
        }
        if ($f['size'] > 5 * 1024 * 1024) {
            Auth::flash('error', 'Fichier trop volumineux (5 Mo max).');
            return null;
        }
        return $f['tmp_name'];
    }

    private function report(int $n, array $errors): void
    {
        Auth::flash($errors ? 'warn' : 'ok', $n . ' ligne(s) importée(s)' . ($errors ? ', ' . count($errors) . ' erreur(s) : ' . implode(' ; ', array_slice($errors, 0, 5)) : '.'));
    }
}
