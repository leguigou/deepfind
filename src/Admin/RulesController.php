<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Config;
use DeepFind\Db;
use DeepFind\Meili\MeiliClient;
use DeepFind\Rules\RulesRepository;

/** Synonymes (règles de résultats et redirections ont leurs propres pages). */
final class RulesController
{
    // ------------------------------------------------------------ Synonymes

    public function synonyms(): void
    {
        $lang = Auth::lang();
        View::render('synonyms', [
            'active' => 'synonyms', 'lang' => $lang,
            'rows' => Db::all('SELECT * FROM synonyms WHERE project_id = ? AND lang = ? ORDER BY term', [\DeepFind\Project::id(), $lang]),
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
        Db::exec('DELETE FROM synonyms WHERE id = ? AND project_id = ?', [(int) ($_POST['id'] ?? 0), \DeepFind\Project::id()]);
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

    // ------------------------------------------------------------ Redirections

    // ------------------------------------------------------------

    /** Retour vers la page d'origine (ex. statistiques) si elle est indiquée et interne à l'extranet. */
    private function backTo(string $default): void
    {
        App::backTo($default);
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
