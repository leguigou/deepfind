<?php
/** @var array $source @var ?array $insp */
$id = (int) $source['id'];
$isProducts = $kind === 'products';
$renderRows = static function (array $rows, string $prefix, array $opts, bool $required): void {
    foreach ($rows as $field => $r): ?>
      <tr class="<?= $r['path'] === '' && $required ? 'map-missing' : '' ?>">
        <td class="map-field">
          <strong><?= e($r['label']) ?></strong><?= $required ? ' <span class="req" title="Obligatoire">*</span>' : '' ?>
          <div class="muted"><code><?= e($field) ?></code><?= $r['help'] !== '' ? ' · ' . e($r['help']) : '' ?></div>
        </td>
        <td class="map-arrow" aria-hidden="true">←</td>
        <td class="map-source">
          <?= select_paths($prefix . '[' . $field . '][path]', $opts, $r['path'], $r['fixed'] !== null ? '— valeur fixe : ' . $r['fixed'] . ' —' : '— non mappé —', 'aria-label="Champ du flux pour ' . e($r['label']) . '"') ?>
          <?php if ($r['auto']): ?><span class="badge badge-info" title="Pré-sélectionné automatiquement d'après le nom du champ">auto</span><?php endif; ?>
          <div class="map-sample muted" data-sample-out></div>
        </td>
        <td class="map-transform">
          <input name="<?= e($prefix . '[' . $field . '][transform]') ?>" value="<?= e($r['transform']) ?>" placeholder="ex. to_float, divide:100" aria-label="Transformations">
        </td>
      </tr>
    <?php endforeach;
};
$tableHead = '<thead><tr><th>Champ DeepFind</th><th></th><th>Champ détecté dans le flux</th><th>Transformations (facultatif)</th></tr></thead>';
?>
<p>
  <a href="<?= e(url('/admin/sources')) ?>">← Flux &amp; mapping</a> ·
  <a href="<?= e(url('/admin/sources/mapping', ['id' => $id, 'refresh' => 1])) ?>">Retélécharger et réanalyser le flux</a> ·
  <a href="<?= e(url('/admin/sources/test', ['id' => $id])) ?>">Tester le résultat</a> ·
  <a href="<?= e(url('/admin/sources/edit', ['id' => $id])) ?>">Mode avancé (JSON)</a>
</p>
<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($insp): ?>
<form method="get" action="<?= e(url('/admin/sources/mapping')) ?>" class="card form inline map-analyse">
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="muted span-all">Flux analysé : <strong><?= e($feedFile) ?></strong> · <?= fmt_n($insp['scanned']) ?> élément(s) examiné(s) · <?= fmt_n(count($insp['paths'])) ?> champ(s) détecté(s)</div>
  <label>Élément qui représente <?= $isProducts ? 'un produit' : 'un article' ?>
    <?php $cands = []; foreach (array_slice($insp['candidates'], 0, 15, true) as $n => $c) { $cands[$n] = '<' . $n . '> × ' . fmt_n($c); } ?>
    <?= select_simple('item', $cands, $insp['item'], '', 'data-autosubmit') ?>
  </label>
  <?php if ($isProducts): ?>
  <label>Produits enfants (variantes)
    <?= select_simple('mode', ['nested' => 'Imbriqués dans le parent', 'flat' => 'Sur leurs propres lignes (avec parent_id)'], $mode, '', 'data-autosubmit') ?>
  </label>
  <?php if ($mode === 'nested'): ?>
  <label>Groupe des variantes
    <?php $g = ['-' => '— aucun (produits simples) —']; foreach ($groups as $gp) { $g[$gp] = $gp; } ?>
    <?= select_simple('variants', $g, $variantsPath === '' ? '-' : $variantsPath, '', 'data-autosubmit') ?>
  </label>
  <?php endif; ?>
  <?php endif; ?>
  <noscript><button class="btn btn-sm">Réanalyser</button></noscript>
</form>

<form method="post" action="<?= e(url('/admin/sources/mapping/save')) ?>" class="form">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="item_element" value="<?= e($insp['item']) ?>">
  <input type="hidden" name="variant_mode" value="<?= e($mode) ?>">
  <input type="hidden" name="variants_path" value="<?= e($variantsPath) ?>">

  <h2>Champs obligatoires</h2>
  <table class="table map-table"><?= $tableHead ?><tbody><?php $renderRows($required, 'f', $root, true); ?></tbody></table>

  <h2>Champs secondaires</h2>
  <table class="table map-table"><?= $tableHead ?><tbody><?php $renderRows($secondary, 'f', $root, false); ?></tbody></table>

  <?php if ($isProducts && $mode === 'flat'): ?>
  <h2>Rattachement des enfants</h2>
  <div class="card"><label>La valeur de « parent_id » d'un enfant correspond au…
    <?= select_simple('parent_match', ['id' => 'champ « Identifiant » du parent', 'sku' => 'champ « SKU » du parent'], $parentMatch, '') ?></label></div>
  <?php else: ?>
  <input type="hidden" name="parent_match" value="<?= e($parentMatch) ?>">
  <?php endif; ?>

  <?php if ($variantRows): ?>
  <h2>Variantes <code><?= e($variantsPath) ?></code></h2>
  <p class="muted">Chemins relatifs à chaque élément &lt;<?= e(basename($variantsPath)) ?>&gt;.</p>
  <table class="table map-table"><?= $tableHead ?><tbody><?php $renderRows($variantRows, 'v', $varOpts, false); ?></tbody></table>
  <?php endif; ?>

  <?php if ($isProducts): ?>
  <h2>Catégories</h2>
  <div class="card grid4">
    <label>Élément catégorie <?= select_simple('cat[path]', $catCandidates, $cat['path'], '— aucune —') ?></label>
    <?php $catAttrs = $cat['attrs'][$cat['path']] ?? array_values(array_unique(array_merge(...array_values($cat['attrs'] ?: [[]])))); ?>
    <label>Attribut « chemin complet » <?= select_simple('cat[path_path]', $catAttrs, $cat['path_path']) ?></label>
    <label>Attribut « identifiant » <?= select_simple('cat[id_path]', $catAttrs, $cat['id_path']) ?></label>
    <label>Séparateur du chemin <input name="cat[separator]" value="<?= e($cat['separator']) ?>" size="3"></label>
  </div>

  <h2>Attributs filtrables (filtres du widget)</h2>
  <?php
  $renderBlock = static function (string $prefix, array $groups, array $state): void {
      if (!$groups) {
          echo '<p class="muted">Aucune liste d\'attributs « par code » détectée (ex. &lt;attribute code="couleur"&gt;). Utilisez les attributs simples ci-dessous.</p>';
          return;
      }
      $paths = [];
      foreach ($groups as $g) {
          $paths[$g['path']] = $g['path'] . ' (' . $g['count'] . ' codes : ' . implode(', ', array_slice($g['codes'], 0, 6)) . (count($g['codes']) > 6 ? '…' : '') . ')';
      }
      $attrs = [];
      foreach ($groups as $g) {
          if ($g['path'] === $state['path']) {
              $attrs = $g['attrs'];
          }
      } ?>
      <div class="card">
        <div class="grid4">
          <label>Liste d'attributs <?= select_simple($prefix . '[path]', $paths, $state['path'], '— ne pas utiliser —') ?></label>
          <label>Code <?= select_simple($prefix . '[code_path]', $attrs, $state['code_path'], '') ?></label>
          <label>Libellé <?= select_simple($prefix . '[label_path]', $attrs, $state['label_path'], '— le code —') ?></label>
          <label>Indicateur « filtrable » <?= select_simple($prefix . '[filterable_path]', $attrs, $state['filterable_path'], '— aucun —') ?></label>
        </div>
        <?php if ($state['codes']): ?>
        <p class="muted">Codes à proposer comme filtres (en plus de ceux marqués filtrables dans le flux) :</p>
        <div class="code-checks">
          <?php foreach ($state['codes'] as $c): ?>
            <label class="check"><input type="checkbox" name="<?= e($prefix) ?>[filterable_codes][]" value="<?= e($c) ?>" <?= in_array(\DeepFind\Import\Mapper::code($c), $state['filterable_codes'], true) ? 'checked' : '' ?>> <?= e($c) ?></label>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
  <?php };
  $renderExtra = static function (string $prefix, array $rows, array $opts): void { ?>
      <table class="table compact map-table">
        <thead><tr><th>Champ du flux</th><th>Libellé du filtre</th><th>Filtrable</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
          <tr>
            <td><?= select_paths($prefix . '[' . $i . '][path]', $opts, $r['path'], '— choisir —') ?><div class="map-sample muted" data-sample-out></div></td>
            <td><input name="<?= e($prefix . '[' . $i . '][label]') ?>" value="<?= e($r['label']) ?>" placeholder="Couleur"></td>
            <td><input type="checkbox" name="<?= e($prefix . '[' . $i . '][filterable]') ?>" value="1" <?= $r['filterable'] ? 'checked' : '' ?> aria-label="Filtrable"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
  <?php };
  $renderBlock('attr', $codeGroups, $attrBlock); ?>
  <h3>Attributs simples (un champ du flux = un attribut)</h3>
  <?php $renderExtra('extra', $extra, $root); ?>

  <?php if ($variantRows): ?>
  <h3>Attributs des variantes (taille, couleur…)</h3>
  <?php $renderBlock('vattr', $vcodeGroups, $vattrBlock); $renderExtra('vextra', $vextra, $varOpts); ?>
  <?php endif; ?>
  <?php endif; ?>

  <div class="actions sticky-actions">
    <button class="btn">Enregistrer le mapping</button>
    <a class="btn btn-light" href="<?= e(url('/admin/sources/test', ['id' => $id])) ?>">Tester le résultat</a>
  </div>
</form>
<?php endif; ?>
