<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Db;
use DeepFind\Import\FeedFetcher;
use DeepFind\Import\Importer;
use DeepFind\Import\Mapper;
use DeepFind\Import\ProductBuilder;
use DeepFind\Import\XmlItemReader;
use DeepFind\Text;

final class SourcesController
{
    public function index(): void
    {
        $sources = Db::all(
            'SELECT s.*, (SELECT CONCAT(r.status, "|", COALESCE(r.finished_at, r.created_at), "|", r.items_indexed)
                          FROM import_runs r WHERE r.source_id = s.id ORDER BY r.id DESC LIMIT 1) AS last_run
             FROM sources s ORDER BY s.kind, s.lang, s.code'
        );
        View::render('sources', ['active' => 'sources', 'sources' => $sources], 'Flux & mapping');
    }

    public function edit(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $source = $id > 0 ? Db::one('SELECT * FROM sources WHERE id = ?', [$id]) : null;
        if ($source === null) {
            $kind = ($_GET['kind'] ?? 'products') === 'posts' ? 'posts' : 'products';
            $source = [
                'id' => 0, 'code' => '', 'kind' => $kind, 'lang' => Auth::lang(), 'url' => '', 'delta_url' => '',
                'auth_header' => '', 'active' => 1, 'cron_full' => '15 3 * * *', 'cron_delta' => null,
                'mapping' => json_encode($kind === 'posts' ? Mapper::defaultPosts() : Mapper::defaultProducts(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        } else {
            $decoded = json_decode((string) $source['mapping'], true);
            if (is_array($decoded)) {
                $source['mapping'] = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        View::render('source_edit', [
            'active' => 'sources',
            'source' => $source,
            'fields' => $source['kind'] === 'posts' ? Mapper::POST_FIELDS : Mapper::PRODUCT_FIELDS,
            'variantFields' => Mapper::VARIANT_FIELDS,
        ], $id ? 'Modifier la source' : 'Nouvelle source');
    }

    public function save(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $code = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) ($_POST['code'] ?? ''))) ?? '';
        $mapping = json_decode((string) ($_POST['mapping'] ?? ''), true);
        $kind = ($_POST['kind'] ?? 'products') === 'posts' ? 'posts' : 'products';
        $lang = in_array($_POST['lang'] ?? '', Auth::langs(), true) ? $_POST['lang'] : Auth::lang();
        $errors = [];
        if ($code === '') {
            $errors[] = 'Code obligatoire (lettres minuscules, chiffres, - et _).';
        }
        if (!is_array($mapping)) {
            $errors[] = 'Le mapping n\'est pas un JSON valide : ' . json_last_error_msg();
        }
        if (trim((string) ($_POST['url'] ?? '')) === '') {
            $errors[] = 'URL (ou chemin) du flux obligatoire.';
        }
        $cron = [];
        foreach (['cron_full' => 'complet', 'cron_delta' => 'delta'] as $col => $label) {
            $expr = trim((string) preg_replace('/\s+/', ' ', (string) ($_POST[$col] ?? '')));
            if ($expr !== '' && !\DeepFind\Import\Cron::isValid($expr)) {
                $errors[] = 'Planification de l\'import ' . $label . ' invalide : « ' . $expr . ' » (format cron : minute heure jour mois jour-semaine).';
            }
            $cron[$col] = $expr !== '' ? $expr : null;
        }
        if ($kind === 'posts') {
            $cron['cron_delta'] = null;
        }
        if ($errors !== []) {
            foreach ($errors as $err) {
                Auth::flash('error', $err);
            }
            App::back('/admin/sources/edit', $id ? ['id' => $id] : ['kind' => $kind]);
            return;
        }
        $data = [
            'code' => $code,
            'kind' => $kind,
            'lang' => $lang,
            'url' => trim((string) $_POST['url']),
            'delta_url' => trim((string) ($_POST['delta_url'] ?? '')) ?: null,
            'auth_header' => trim((string) ($_POST['auth_header'] ?? '')) ?: null,
            'mapping' => Text::json($mapping),
            'active' => isset($_POST['active']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ] + $cron;
        try {
            if ($id > 0) {
                Db::update('sources', $data, 'id = ?', [$id]);
            } else {
                $id = Db::insert('sources', $data + ['created_at' => date('Y-m-d H:i:s')]);
            }
        } catch (\PDOException $e) {
            Auth::flash('error', str_contains($e->getMessage(), 'Duplicate') ? 'Ce code de source existe déjà.' : $e->getMessage());
            App::back('/admin/sources/edit', $id ? ['id' => $id] : ['kind' => $kind]);
            return;
        }
        Auth::flash('ok', 'Source enregistrée.');
        App::back(isset($_POST['to_mapping']) ? '/admin/sources/mapping' : '/admin/sources/edit', ['id' => $id]);
    }

    public function delete(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        Db::exec('DELETE FROM sources WHERE id = ?', [$id]);
        Auth::flash('ok', 'Source supprimée (l\'index Meilisearch existant est conservé).');
        App::back('/admin/sources');
    }

    public function import(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $type = ($_POST['type'] ?? 'full') === 'delta' ? 'delta' : 'full';
        $source = Db::one('SELECT * FROM sources WHERE id = ?', [$id]);
        if ($source === null) {
            App::back('/admin/sources');
            return;
        }
        $pending = Db::value("SELECT id FROM import_runs WHERE source_id = ? AND status IN ('queued','running') LIMIT 1", [$id]);
        if ($pending !== null) {
            Auth::flash('warn', 'Un import est déjà en attente ou en cours pour cette source (#' . $pending . ').');
        } else {
            $runId = Importer::queue($id, $type, 'manual');
            Auth::flash('ok', 'Import #' . $runId . ' mis en file d\'attente : suivez son avancement ci-dessous (la page se met à jour toute seule).');
            App::back('/admin/imports/view', ['id' => $runId]);
            return;
        }
        App::back('/admin/imports');
    }

    /** Test du mapping sur les premiers éléments du flux, sans rien indexer. */
    public function test(): void
    {
        $id = (int) ($_REQUEST['id'] ?? 0);
        $source = Db::one('SELECT * FROM sources WHERE id = ?', [$id]);
        if ($source === null) {
            App::back('/admin/sources');
            return;
        }
        $type = ($_REQUEST['type'] ?? 'full') === 'delta' ? 'delta' : 'full';
        $mappingJson = isset($_POST['mapping']) ? (string) $_POST['mapping'] : (string) $source['mapping'];
        $mapping = json_decode($mappingJson, true);
        $error = null;
        $samples = [];
        $file = null;
        if (!is_array($mapping)) {
            $error = 'Mapping JSON invalide : ' . json_last_error_msg();
        } else {
            try {
                $file = FeedFetcher::cachedOrFetch($source, $type, isset($_REQUEST['refresh']));
                $mapper = new Mapper($mapping, $source['kind']);
                $builder = new ProductBuilder();
                $names = [$mapper->itemElement()];
                foreach (XmlItemReader::items($file, $names, max(1, min(20, (int) ($_REQUEST['n'] ?? 3)))) as [, $el]) {
                    $xml = (string) $el->asXML();
                    if ($source['kind'] === 'posts') {
                        $mapped = $mapper->mapPost($el);
                        $doc = null;
                    } else {
                        $mapped = $mapper->mapProduct($el, $type === 'delta');
                        $doc = $type === 'full' && $mapper->variantMode() === 'nested' ? $builder->build($mapped, $mapped['variants']) : null;
                    }
                    $samples[] = ['xml' => Text::truncate($xml, 4000), 'mapped' => $mapped, 'doc' => $doc];
                }
                if ($samples === []) {
                    $error = 'Aucun élément <' . $mapper->itemElement() . '> trouvé dans le flux : vérifiez « item_element ».';
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        View::render('source_test', [
            'active' => 'sources', 'source' => $source, 'type' => $type, 'samples' => $samples, 'error' => $error,
            'file' => $file ? basename($file) . ' (' . round(filesize($file) / 1048576, 2) . ' Mo)' : null,
            'flat' => is_array($mapping) && ($mapping['variant_mode'] ?? 'nested') === 'flat',
        ], 'Test du mapping');
    }

    public function imports(): void
    {
        $runs = Db::all('SELECT r.*, s.code, s.kind, s.lang FROM import_runs r JOIN sources s ON s.id = r.source_id ORDER BY r.id DESC LIMIT 100');
        View::render('imports', ['active' => 'imports', 'runs' => $runs], 'Historique des imports');
    }

    /** Avancement des imports en cours (JSON, interrogé toutes les 2 s par la page). */
    public function importStatus(): void
    {
        session_write_close();
        $ids = array_slice(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? '')))), 0, 50);
        $out = [];
        if ($ids !== []) {
            $rows = Db::all('SELECT * FROM import_runs WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
            foreach ($rows as $r) {
                $out[] = \DeepFind\Import\Progress::of($r);
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        echo Text::json(['runs' => $out]);
    }

    public function importView(): void
    {
        $run = Db::one('SELECT r.*, s.code, s.kind, s.lang FROM import_runs r JOIN sources s ON s.id = r.source_id WHERE r.id = ?', [(int) ($_GET['id'] ?? 0)]);
        if ($run === null) {
            App::back('/admin/imports');
            return;
        }
        $run['report_data'] = json_decode((string) $run['report'], true) ?: [];
        View::render('import_view', ['active' => 'imports', 'run' => $run], 'Import #' . $run['id']);
    }
}
