<?php

declare(strict_types=1);

namespace DeepFind\Import;

use DeepFind\Config;
use DeepFind\Db;
use DeepFind\Meili\MeiliClient;
use DeepFind\Rules\RulesRepository;
use DeepFind\Search\IndexSettings;
use DeepFind\Settings;
use DeepFind\Text;

/**
 * Orchestration d'un import :
 *  - complet  : construction d'un index temporaire puis bascule instantanée (aucune coupure pour les visiteurs) ;
 *  - delta    : mise à jour stock/prix/suppressions directement dans l'index en ligne.
 */
final class Importer
{
    private const BATCH = 1000;

    /** @var callable(string):void */
    private $logger;

    private array $report = [];

    public function __construct(private readonly MeiliClient $meili, ?callable $logger = null)
    {
        $this->logger = $logger ?? static function (string $m): void {
        };
    }

    public static function source(string $code): ?array
    {
        return Db::one('SELECT * FROM sources WHERE code = ?', [$code]);
    }

    public static function queue(int $sourceId, string $type, string $trigger): int
    {
        return Db::insert('import_runs', [
            'source_id' => $sourceId, 'type' => $type, 'status' => 'queued', 'trigger_by' => $trigger,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Lance un import et retourne la ligne import_runs finale. */
    public function run(array $source, string $type, string $trigger = 'cli', ?int $runId = null): array
    {
        if (!in_array($type, ['full', 'delta'], true)) {
            throw new \InvalidArgumentException('Type d\'import inconnu : ' . $type);
        }
        $runId ??= self::queue((int) $source['id'], $type, $trigger);
        Db::update('import_runs', ['status' => 'running', 'started_at' => date('Y-m-d H:i:s')], 'id = ?', [$runId]);
        $lockName = 'deepfind_import_' . $source['code'];
        $locked = (int) Db::value('SELECT GET_LOCK(?, 0)', [$lockName]) === 1;
        $this->report = ['rejects' => [], 'reject_samples' => [], 'read' => 0, 'indexed' => 0, 'rejected' => 0, 'skipped' => 0];
        $start = microtime(true);
        $status = 'success';
        $message = '';
        try {
            if (!$locked) {
                throw new \RuntimeException('Un import est déjà en cours pour cette source.');
            }
            $this->log(sprintf('Import %s de la source %s (%s)', $type, $source['code'], $source['kind']));
            $file = FeedFetcher::fetch($source, $type);
            $this->log('Flux : ' . basename($file) . ' (' . round(filesize($file) / 1048576, 1) . ' Mo)');
            $mapping = json_decode((string) $source['mapping'], true);
            if (!is_array($mapping)) {
                throw new \RuntimeException('Mapping JSON invalide pour la source ' . $source['code']);
            }
            if ($source['kind'] === 'posts') {
                if ($type === 'delta') {
                    throw new \RuntimeException('Les articles de blog ne gèrent que l\'import complet.');
                }
                $this->fullPosts($source, $mapping, $file, $runId);
            } elseif ($type === 'full') {
                $this->fullProducts($source, $mapping, $file, $runId);
            } else {
                $this->deltaProducts($source, $mapping, $file);
            }
            $message = sprintf('%d lus, %d indexés, %d rejetés', $this->report['read'], $this->report['indexed'], $this->report['rejected']);
        } catch (AbortImport $e) {
            $status = 'aborted';
            $message = $e->getMessage();
        } catch (\Throwable $e) {
            $status = 'failed';
            $message = $e->getMessage();
        } finally {
            if ($locked) {
                Db::value('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
            Db::exec('DELETE FROM import_staging WHERE run_id = ?', [$runId]);
        }
        $this->report['duration_s'] = round(microtime(true) - $start, 1);
        $this->log(strtoupper($status) . ' : ' . $message . ' (' . $this->report['duration_s'] . ' s)');
        Db::update('import_runs', [
            'status' => $status,
            'finished_at' => date('Y-m-d H:i:s'),
            'items_read' => $this->report['read'],
            'items_indexed' => $this->report['indexed'],
            'items_rejected' => $this->report['rejected'],
            'message' => Text::truncate($message, 2000),
            'report' => Text::json($this->report),
        ], 'id = ?', [$runId]);
        return Db::one('SELECT * FROM import_runs WHERE id = ?', [$runId]) ?? [];
    }

    // ================================================================== Produits : import complet

    private function fullProducts(array $source, array $mapping, string $file, int $runId): void
    {
        $lang = $source['lang'];
        $live = Config::productIndex($lang);
        $tmp = $live . '_tmp';
        $mapper = new Mapper($mapping, 'products');
        $builder = new ProductBuilder();
        $synonyms = RulesRepository::meiliSynonyms($lang);

        $this->prepareTmpIndex($tmp, IndexSettings::products($lang, [], $synonyms));
        $tasks = [];
        $batch = [];
        $push = function (array|string $doc, string $sku) use (&$batch, &$tasks, $tmp): void {
            if (is_string($doc)) {
                $this->reject($doc, $sku);
                return;
            }
            $batch[] = $doc;
            $this->report['indexed']++;
            if (count($batch) >= self::BATCH) {
                $tasks[] = $this->meili->addDocuments($tmp, $batch);
                $batch = [];
                $this->log('… ' . $this->report['indexed'] . ' produits envoyés');
            }
        };

        if ($mapper->variantMode() === 'nested') {
            foreach (XmlItemReader::items($file, [$mapper->itemElement()]) as [, $el]) {
                $this->report['read']++;
                $m = $mapper->mapProduct($el);
                $push($builder->build($m, $m['variants']), (string) ($m['fields']['sku'] ?? ''));
            }
        } else {
            $this->stageFlat($mapper, $file, $runId);
            $this->buildFromStaging($mapper, $builder, $runId, $push);
        }
        if ($batch !== []) {
            $tasks[] = $this->meili->addDocuments($tmp, $batch);
        }
        $this->log('Attente de l\'indexation Meilisearch…');
        $this->meili->waitTasks($tasks);

        $filterable = [];
        $labels = [];
        foreach ($builder->attributeMeta as $code => $meta) {
            $labels[$code] = $meta['label'];
            if ($meta['filterable']) {
                $filterable[] = $code;
            }
        }
        sort($filterable);
        if ($filterable !== []) {
            $this->meili->waitTask($this->meili->updateSettings($tmp, ['filterableAttributes' => IndexSettings::products($lang, $filterable, [])['filterableAttributes']]));
        }
        $this->report['filterable_attributes'] = $filterable;

        $this->swapIfSafe($live, $tmp, IndexSettings::products($lang, $filterable, $synonyms));
        Settings::set('attr_labels_' . $lang, Text::json(array_intersect_key($labels, array_flip($filterable))));
        RulesRepository::bumpVersion();
    }

    /** Flux à plat (parents et enfants sur des lignes distinctes) : passage par une table de travail MySQL. */
    private function stageFlat(Mapper $mapper, string $file, int $runId): void
    {
        $rows = [];
        $flush = static function () use (&$rows): void {
            if ($rows === []) {
                return;
            }
            $sql = 'INSERT INTO import_staging (run_id, group_key, is_child, payload) VALUES ' . implode(',', array_fill(0, count($rows), '(?,?,?,?)'));
            Db::exec($sql, array_merge(...$rows));
            $rows = [];
        };
        $byKey = $mapper->parentMatch();
        foreach (XmlItemReader::items($file, [$mapper->itemElement()]) as [, $el]) {
            $this->report['read']++;
            $m = $mapper->mapProduct($el);
            $parent = trim((string) ($m['fields']['parent_id'] ?? ''));
            $own = trim((string) ($m['fields'][$byKey] ?? ''));
            $isChild = $parent !== '' && $parent !== $own;
            $groupKey = $isChild ? $parent : $own;
            if ($groupKey === '') {
                $this->reject($byKey === 'sku' ? 'SKU manquant' : 'identifiant manquant', (string) ($m['fields']['sku'] ?? ''));
                continue;
            }
            $rows[] = [$runId, Text::truncate($groupKey, 190), $isChild ? 1 : 0, serialize($m)];
            if (count($rows) >= 500) {
                $flush();
            }
        }
        $flush();
    }

    private function buildFromStaging(Mapper $mapper, ProductBuilder $builder, int $runId, callable $push): void
    {
        $pdo = Db::connect(false);
        $st = $pdo->prepare('SELECT group_key, is_child, payload FROM import_staging WHERE run_id = ? ORDER BY group_key, is_child, id');
        $st->execute([$runId]);
        $currentKey = null;
        $parent = null;
        $children = [];
        $emit = function () use (&$parent, &$children, $builder, $push): void {
            if ($parent === null) {
                foreach ($children as $c) {
                    $this->reject('enfant sans parent', (string) ($c['fields']['sku'] ?? ''));
                }
            } else {
                $push($builder->build($parent, $children), (string) ($parent['fields']['sku'] ?? ''));
            }
            $parent = null;
            $children = [];
        };
        while ($row = $st->fetch()) {
            if ($row['group_key'] !== $currentKey) {
                if ($currentKey !== null) {
                    $emit();
                }
                $currentKey = $row['group_key'];
            }
            $m = unserialize($row['payload'], ['allowed_classes' => false]);
            if ((int) $row['is_child'] === 0 && $parent === null) {
                $parent = $m;
            } elseif ((int) $row['is_child'] === 0) {
                $this->reject('doublon', (string) ($m['fields']['sku'] ?? ''));
            } else {
                $children[] = ['fields' => $m['fields'], 'attributes' => $m['attributes']];
            }
        }
        if ($currentKey !== null) {
            $emit();
        }
        $st->closeCursor();
    }

    // ================================================================== Produits : delta

    private function deltaProducts(array $source, array $mapping, string $file): void
    {
        $live = Config::productIndex($source['lang']);
        if ($this->meili->getIndex($live) === null) {
            throw new \RuntimeException('Index ' . $live . ' absent : lancez d\'abord un import complet.');
        }
        $mapper = new Mapper($mapping, 'products');
        $builder = new ProductBuilder();
        $changes = [];
        $deletes = [];
        foreach (XmlItemReader::items($file, [$mapper->itemElement(), $mapper->deleteElement()]) as [$name, $el]) {
            if ($name === $mapper->deleteElement()) {
                foreach ($mapper->deletedSkus($el) as $sku) {
                    $deletes[] = mb_strtoupper($sku, 'UTF-8');
                }
                continue;
            }
            $this->report['read']++;
            $m = $mapper->mapProduct($el, true);
            $sku = trim((string) ($m['fields']['sku'] ?? ''));
            if ($sku === '') {
                $this->reject('SKU manquant', '');
                continue;
            }
            $changes[] = ['sku' => mb_strtoupper($sku, 'UTF-8'), 'fields' => $m['fields']];
            foreach ($m['variants'] as $v) {
                $vsku = trim((string) ($v['fields']['sku'] ?? ''));
                if ($vsku !== '') {
                    $changes[] = ['sku' => mb_strtoupper($vsku, 'UTF-8'), 'fields' => $v['fields']];
                }
            }
        }
        $this->report['deleted_requested'] = count($deletes);

        $tasks = [];
        $all = array_merge(
            array_map(static fn ($c) => ['op' => 'update'] + $c, $changes),
            array_map(static fn ($s) => ['op' => 'delete', 'sku' => $s, 'fields' => []], $deletes)
        );
        foreach (array_chunk($all, 200) as $chunk) {
            $skus = array_values(array_unique(array_column($chunk, 'sku')));
            $docs = [];
            $bySku = [];
            foreach ($this->meili->fetchDocuments($live, self::inFilter('all_skus', $skus), 1000) as $doc) {
                $docs[$doc['id']] = $doc;
                foreach ($doc['all_skus'] as $s) {
                    $bySku[$s] = $doc['id'];
                }
            }
            $touched = [];
            $toDelete = [];
            foreach ($chunk as $c) {
                $id = $bySku[$c['sku']] ?? null;
                if ($id === null || !isset($docs[$id])) {
                    $this->reject('SKU inconnu (sera créé au prochain import complet)', $c['sku']);
                    continue;
                }
                $doc = &$docs[$id];
                $isParent = mb_strtoupper($doc['sku'], 'UTF-8') === $c['sku'];
                if ($c['op'] === 'delete') {
                    if ($isParent) {
                        $toDelete[$id] = true;
                    } else {
                        $doc['variants'] = array_values(array_filter($doc['variants'], static fn ($v) => mb_strtoupper($v['sku'], 'UTF-8') !== $c['sku']));
                        $touched[$id] = true;
                    }
                } elseif ($isParent) {
                    self::applyParentChanges($doc, $c['fields']);
                    $touched[$id] = true;
                } else {
                    foreach ($doc['variants'] as &$v) {
                        if (mb_strtoupper($v['sku'], 'UTF-8') === $c['sku']) {
                            foreach (['price', 'final_price', 'in_stock', 'salable_qty', 'url', 'image', 'ean'] as $k) {
                                if (array_key_exists($k, $c['fields'])) {
                                    $v[$k] = $c['fields'][$k];
                                }
                            }
                        }
                    }
                    unset($v);
                    $touched[$id] = true;
                }
                unset($doc);
            }
            $out = [];
            foreach (array_keys($touched) as $id) {
                if (!isset($toDelete[$id])) {
                    $out[] = $builder->computeDerived($docs[$id]);
                }
            }
            if ($out !== []) {
                $tasks[] = $this->meili->addDocuments($live, $out);
                $this->report['indexed'] += count($out);
            }
            if ($toDelete !== []) {
                $tasks[] = $this->meili->deleteDocuments($live, array_keys($toDelete));
                $this->report['deleted'] = ($this->report['deleted'] ?? 0) + count($toDelete);
            }
        }
        $this->meili->waitTasks($tasks);
        RulesRepository::bumpVersion();
    }

    private static function applyParentChanges(array &$doc, array $f): void
    {
        $raw = ['price' => 'raw_price', 'final_price' => 'raw_final_price', 'in_stock' => 'raw_in_stock', 'salable_qty' => 'raw_salable_qty'];
        foreach ($f as $k => $v) {
            if (isset($raw[$k])) {
                $doc[$raw[$k]] = $v;
            } elseif ($k === 'created_at') {
                $doc['created_ts'] = $v;
            } elseif (in_array($k, ['name', 'brand', 'url', 'image', 'image_hover', 'short_description', 'description', 'ean', 'margin_rate', 'sales_30d', 'rating', 'reviews_count', 'search_keywords', 'manual_boost'], true)) {
                $doc[$k] = $v;
            }
        }
    }

    // ================================================================== Articles de blog

    private function fullPosts(array $source, array $mapping, string $file, int $runId): void
    {
        $lang = $source['lang'];
        $live = Config::postIndex($lang);
        $tmp = $live . '_tmp';
        $mapper = new Mapper($mapping, 'posts');
        $synonyms = RulesRepository::meiliSynonyms($lang);
        $this->prepareTmpIndex($tmp, IndexSettings::posts($lang, $synonyms));
        $tasks = [];
        $batch = [];
        foreach (XmlItemReader::items($file, [$mapper->itemElement()]) as [, $el]) {
            $this->report['read']++;
            $f = $mapper->mapPost($el);
            if (isset($f['enabled']) && (int) $f['enabled'] === 0) {
                $this->report['skipped']++;
                continue;
            }
            $id = (string) ($f['id'] ?? '');
            if ($id === '' || ($f['title'] ?? '') === '' || ($f['url'] ?? '') === '') {
                $this->reject('id, titre ou URL manquant', $id);
                continue;
            }
            $batch[] = [
                'id' => 'b' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $id),
                'title' => $f['title'],
                'url' => $f['url'],
                'image' => $f['image'] ?? '',
                'excerpt' => Text::truncate((string) ($f['excerpt'] ?? ''), 400),
                'content' => Text::truncate((string) ($f['content'] ?? ''), 8000),
                'author' => $f['author'] ?? '',
                'published_ts' => (int) ($f['published_at'] ?? 0),
                'updated_ts' => (int) ($f['updated_at'] ?? 0),
                'categories' => $f['categories'] ?? [],
                'tags' => $f['tags'] ?? [],
                'related_skus' => array_map(static fn ($s) => mb_strtoupper($s, 'UTF-8'), $f['related_skus'] ?? []),
                'manual_boost' => (int) ($f['manual_boost'] ?? 0),
            ];
            $this->report['indexed']++;
            if (count($batch) >= self::BATCH) {
                $tasks[] = $this->meili->addDocuments($tmp, $batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $tasks[] = $this->meili->addDocuments($tmp, $batch);
        }
        $this->meili->waitTasks($tasks);
        $this->swapIfSafe($live, $tmp, IndexSettings::posts($lang, $synonyms));
        RulesRepository::bumpVersion();
    }

    // ================================================================== Outils communs

    private function prepareTmpIndex(string $tmp, array $settings): void
    {
        if ($this->meili->getIndex($tmp) !== null) {
            $this->meili->waitTask((int) $this->meili->deleteIndex($tmp));
        }
        $this->meili->waitTask($this->meili->createIndex($tmp));
        $this->meili->waitTask($this->meili->updateSettings($tmp, $settings));
    }

    /** Contrôle anti-catastrophe puis bascule atomique de l'index temporaire vers l'index en ligne. */
    private function swapIfSafe(string $live, string $tmp, array $liveSettings): void
    {
        $newCount = (int) ($this->meili->stats($tmp)['numberOfDocuments'] ?? 0);
        $this->report['index_documents'] = $newCount;
        $liveIndex = $this->meili->getIndex($live);
        $oldCount = $liveIndex !== null ? (int) ($this->meili->stats($live)['numberOfDocuments'] ?? 0) : 0;
        $this->report['previous_documents'] = $oldCount;
        $maxDrop = max(0, Settings::int('max_drop_percent'));
        if ($newCount === 0) {
            $this->meili->deleteIndex($tmp);
            throw new AbortImport('Aucun document valide dans le flux : index en ligne conservé.');
        }
        if ($oldCount > 0 && $newCount < $oldCount * (1 - $maxDrop / 100)) {
            $this->meili->deleteIndex($tmp);
            throw new AbortImport(sprintf(
                'Import bloqué : %d documents contre %d en ligne (baisse > %d %%). Vérifiez le flux ou augmentez le seuil.',
                $newCount,
                $oldCount,
                $maxDrop
            ));
        }
        if ($liveIndex === null) {
            $this->meili->waitTask($this->meili->createIndex($live));
        }
        $this->meili->waitTask($this->meili->swap($live, $tmp));
        $this->meili->deleteIndex($tmp);
        // Les réglages voyagent avec l'index ; on les réapplique pour être sûr (synonymes modifiés pendant l'import).
        $this->meili->updateSettings($live, ['synonyms' => $liveSettings['synonyms']]);
        $this->log(sprintf('Bascule effectuée : %d documents en ligne (avant : %d).', $newCount, $oldCount));
    }

    private function reject(string $reason, string $sku): void
    {
        $this->report['rejected']++;
        $this->report['rejects'][$reason] = ($this->report['rejects'][$reason] ?? 0) + 1;
        if (count($this->report['reject_samples']) < 100) {
            $this->report['reject_samples'][] = ['sku' => $sku, 'reason' => $reason];
        }
    }

    private function log(string $msg): void
    {
        ($this->logger)('[' . date('H:i:s') . '] ' . $msg);
    }

    public static function inFilter(string $field, array $values): string
    {
        return $field . ' IN [' . implode(',', array_map(static fn ($v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v) . '"', $values)) . ']';
    }
}
