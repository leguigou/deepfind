<?php
/** Une ligne = un groupe de mots. Équivalents : interchangeables. Sens unique (alias) : le 1er mot mène aux suivants. */
$oneWayToggle = static fn (string $id) => '<label class="toggle toggle-sm"><input type="checkbox" role="switch" name="one_way" value="1" id="' . e($id) . '">'
    . '<span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span>'
    . '<span class="toggle-text">Sens unique : le 1er mot mène aux suivants, pas l\'inverse</span></label>';
?>
<div class="syn-help">
  <p class="muted">Langue <?= e(strtoupper($lang)) ?>. Une ligne = des mots qui doivent donner les mêmes résultats, séparés par des virgules.
    Ex. <code>ecoflow, ecoflau, ecauflo</code> : chercher l'un de ces mots trouve aussi les autres, donc les produits EcoFlow. Les changements sont envoyés au moteur immédiatement.</p>
  <p class="muted small"><strong>Sens unique</strong> (rarement utile) : seul le 1er mot mène aux suivants. Ex. <code>combi, combinaison</code> : « combi » trouve les combinaisons,
    mais « combinaison » ne fait pas sortir tout ce qui contient « combi ».
    Pluriels et lettre isolée fausse (« palmes », « palne ») : en général inutile, le moteur les trouve déjà ; vérifiez dans l'<a href="<?= e(url('/admin/preview')) ?>">aperçu interne</a>.</p>
</div>
<div class="grid2">
  <form method="post" action="<?= e(url('/admin/synonyms/add')) ?>" class="card form">
    <?= csrf_field() ?>
    <h3>Ajouter</h3>
    <label>Mots équivalents <input name="words" required placeholder="ex. : ecoflow, ecoflau, ecauflo">
      <small class="muted">Au moins deux mots ou expressions, séparés par des virgules.</small></label>
    <?= $oneWayToggle('syn-add-oneway') ?>
    <button class="btn">Ajouter</button>
  </form>
  <form method="post" action="<?= e(url('/admin/synonyms/import')) ?>" enctype="multipart/form-data" class="card form">
    <?= csrf_field() ?>
    <h3>Importer un CSV</h3>
    <p class="muted">Colonnes : <code>type;terme;synonymes</code> (voir samples/synonymes.csv). Type <code>equivalent</code> ou <code>alias</code> (sens unique) ; synonymes séparés par <code>|</code>.</p>
    <?= file_field('csv', '.csv,text/csv', 'Fichier CSV, 5 Mo maximum') ?>
    <label class="toggle toggle-sm"><input type="checkbox" role="switch" name="replace"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">Remplacer tous les synonymes de cette langue</span></label>
    <button class="btn">Importer</button>
  </form>
</div>
<form method="post" action="<?= e(url('/admin/synonyms/sync')) ?>" class="actions synonym-sync">
  <?= csrf_field() ?>
  <button type="submit" class="btn btn-light" aria-describedby="synonym-sync-help">Synchroniser avec Meilisearch</button>
  <span id="synonym-sync-help" class="muted small">À utiliser si les changements ne sont pas visibles dans la recherche.</span>
</form>
<?php if ($rows): ?>
<div class="table-search">
  <input type="search" data-table-search="synonyms-table" placeholder="Rechercher un mot…" aria-label="Rechercher dans les synonymes" autocomplete="off">
  <span class="muted small" data-table-search-count data-label="ligne(s)" aria-live="polite"><?= count($rows) ?> ligne(s)</span>
</div>
<?php endif; ?>
<table class="table" id="synonyms-table">
  <thead><tr><th>Mots</th><th>Sens</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $words = array_merge([$r['term']], array_values(array_filter(array_map('trim', explode('|', $r['synonyms'])), 'strlen')));
      $alias = $r['type'] === 'alias'; ?>
    <tr data-search="<?= e(implode(' ', $words)) ?>">
      <td><div class="chips syn-words"><?php foreach ($words as $i => $w): ?><?php if ($alias && $i === 1): ?><span class="syn-arrow" aria-label="mène à">→</span><?php endif; ?><span class="chip<?= $alias && $i === 0 ? ' syn-source' : '' ?>"><?= e($w) ?></span><?php endforeach; ?></div></td>
      <td class="muted small"><?= $alias ? 'Sens unique' : 'Équivalents' ?></td>
      <td class="actions">
        <button type="button" class="btn btn-sm btn-light" data-dialog-open="synonym-dialog"
          data-fill-id="<?= (int) $r['id'] ?>" data-fill-words="<?= e(implode(', ', $words)) ?>" data-fill-one_way="<?= $alias ? '1' : '0' ?>">Modifier</button>
        <form method="post" action="<?= e(url('/admin/synonyms/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="Supprimer ?">Supprimer</button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="3" class="muted">Aucun synonyme.</td></tr><?php endif; ?>
  <tr data-table-search-empty hidden><td colspan="3" class="muted">Aucune ligne ne contient ce mot.</td></tr>
  </tbody>
</table>

<dialog id="synonym-dialog" class="modal" aria-labelledby="synonym-dialog-title">
  <form method="post" action="<?= e(url('/admin/synonyms/update')) ?>" class="form modal-form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="">
    <div class="modal-head">
      <div class="modal-title"><h2 id="synonym-dialog-title">Modifier les synonymes</h2></div>
      <button type="button" class="icon-btn modal-close" data-dialog-close aria-label="Fermer">✕</button>
    </div>
    <div class="modal-body">
      <label>Mots équivalents <input name="words" required>
        <small class="muted">Au moins deux mots ou expressions, séparés par des virgules.</small></label>
      <?= $oneWayToggle('syn-edit-oneway') ?>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-light" data-dialog-close>Annuler</button>
      <button class="btn">Enregistrer</button>
    </div>
  </form>
</dialog>
