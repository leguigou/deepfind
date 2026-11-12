<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Config;
use DeepFind\Db;
use DeepFind\Import\ProductBuilder;
use DeepFind\Meili\MeiliClient;
use DeepFind\Rules\Boosts;
use DeepFind\Rules\RulesRepository;
use DeepFind\Search\Filter;
use DeepFind\Settings;

/**
 * Boosts produits : mettre en avant (ou en retrait) un produit sur toutes les recherches.
 * Le score du produit est recalculé et envoyé au moteur immédiatement.
 */
final class BoostsController
{
    public function index(): void
    {
        $lang = Auth::lang();
        $rows = Db::all('SELECT * FROM product_boosts WHERE project_id = ? AND lang = ? ORDER BY boost DESC, sku', [\DeepFind\Project::id(), $lang]);
        $info = [];
        if ($rows !== []) {
            try {
                foreach (MeiliClient::admin()->fetchDocuments(Config::productIndex($lang), Filter::in('all_skus', array_map(static fn ($r) => mb_strtoupper($r['sku'], 'UTF-8'), $rows)), count($rows) * 2) as $d) {
                    $info[mb_strtoupper($d['sku'], 'UTF-8')] = $d;
                }
            } catch (\Throwable) {
            }
        }
        View::render('boosts', [
            'active' => 'boosts', 'lang' => $lang, 'rows' => $rows, 'info' => $info,
            'weight' => Settings::float('w_manual_boost'), 'prefill' => (string) ($_GET['sku'] ?? ''),
        ], 'Boosts produits');
    }

    public function save(): void
    {
        $lang = Auth::lang();
        $skus = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($_POST['sku'] ?? '')) ?: [])));
        if ($skus === []) {
            Auth::flash('error', 'SKU obligatoire.');
            App::back('/admin/boosts');
            return;
        }
        $boost = (int) ($_POST['boost'] ?? 0);
        foreach ($skus as $sku) {
            if ($boost === 0) {
                Db::exec('DELETE FROM product_boosts WHERE project_id = ? AND lang = ? AND sku = ?', [\DeepFind\Project::id(), $lang, $sku]);
            } else {
                Boosts::save($lang, $sku, $boost, (string) ($_POST['starts_at'] ?? ''), (string) ($_POST['ends_at'] ?? ''), (string) ($_POST['note'] ?? ''));
            }
        }
        [$found, $missing] = self::apply($lang, $skus);
        Auth::flash($missing ? 'warn' : 'ok', ($boost === 0 ? 'Boost retiré' : sprintf('Boost %+d enregistré', $boost)) . sprintf(' : %d produit(s) mis à jour dans le moteur.', $found)
            . ($missing ? ' Introuvable(s) dans l\'index : ' . implode(', ', $missing) . ' (appliqué au prochain import).' : ''));
        App::backTo('/admin/boosts');
    }

    public function delete(): void
    {
        $lang = Auth::lang();
        $row = Db::one('SELECT sku FROM product_boosts WHERE id = ? AND lang = ? AND project_id = ?', [(int) ($_POST['id'] ?? 0), $lang, \DeepFind\Project::id()]);
        if ($row !== null) {
            Db::exec('DELETE FROM product_boosts WHERE id = ?', [(int) $_POST['id']]);
            self::apply($lang, [$row['sku']]);
            Auth::flash('ok', 'Boost supprimé.');
        }
        App::back('/admin/boosts');
    }

    /**
     * Recalcule et réenvoie le score des produits concernés (effet immédiat dans la recherche).
     * @return array{0:int, 1:list<string>}
     */
    public static function apply(string $lang, array $skus): array
    {
        $upper = array_map(static fn ($s) => mb_strtoupper($s, 'UTF-8'), $skus);
        $meili = MeiliClient::admin();
        $index = Config::productIndex($lang);
        try {
            if ($meili->getIndex($index) === null) {
                return [0, $skus];
            }
            $docs = $meili->fetchDocuments($index, Filter::in('all_skus', $upper), count($upper) * 2);
        } catch (\Throwable) {
            return [0, $skus];
        }
        $builder = ProductBuilder::forLang($lang);
        $out = [];
        $seen = [];
        foreach ($docs as $d) {
            $out[] = $builder->computeDerived($builder->applySignals($d));
            foreach ($d['all_skus'] ?? [] as $s) {
                $seen[$s] = true;
            }
        }
        if ($out !== []) {
            $meili->waitTask($meili->addDocuments($index, $out), 60);
            RulesRepository::bumpVersion();
        }
        $missing = array_values(array_filter($skus, static fn ($s) => !isset($seen[mb_strtoupper($s, 'UTF-8')])));
        return [count($out), $missing];
    }
}
