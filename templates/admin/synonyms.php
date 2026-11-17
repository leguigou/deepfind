<div class="syn-help">
  <p class="muted">Langue <?= e(strtoupper($lang)) ?>. Les changements sont envoyés au moteur immédiatement.</p>
  <ul class="muted">
    <li><strong>Équivalent</strong> : les mots désignent la même chose, <em>dans les deux sens</em>. Ex. terme « palme », synonymes « nageoire » :
      chercher « palme » trouve aussi les produits appelés « nageoire », et chercher « nageoire » trouve aussi les « palmes ».</li>
    <li><strong>Alias (sens unique)</strong> : le terme mène aux synonymes, <em>pas l'inverse</em>. À utiliser pour une faute de frappe ou une abréviation.
      Ex. « ecoflau » → « ecoflow » : chercher « ecoflau » trouve les produits EcoFlow, mais chercher « ecoflow » ne cherche pas « ecoflau ».</li>
  </ul>
  <p class="muted small">En général inutile pour un pluriel ou une seule lettre fausse (« palmes », « palne ») : la tolérance aux fautes du moteur les trouve déjà. Vérifiez dans l'<a href="<?= e(url('/admin/preview')) ?>">aperçu interne</a> avant d'ajouter un synonyme.</p>
</div>
<div class="grid2">
  <form method="post" action="<?= e(url('/admin/synonyms/add')) ?>" class="card form">
    <?= csrf_field() ?>
    <h3>Ajouter</h3>
    <label>Type <select name="type"><option value="equivalent">Équivalent (dans les deux sens)</option><option value="alias">Alias (sens unique : faute de frappe, abréviation)</option></select></label>
    <label>Terme <input name="term" required placeholder="palme"></label>
    <label>Synonymes (séparés par , ou |) <input name="synonyms" required placeholder="nageoire"></label>
    <button class="btn">Ajouter</button>
  </form>
  <form method="post" action="<?= e(url('/admin/synonyms/import')) ?>" enctype="multipart/form-data" class="card form">
    <?= csrf_field() ?>
    <h3>Importer un CSV</h3>
    <p class="muted">Colonnes : <code>type;terme;synonymes</code> (voir samples/synonymes.csv)</p>
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
  <input type="search" data-table-search="synonyms-table" placeholder="Rechercher un terme ou un synonyme…" aria-label="Rechercher dans les synonymes" autocomplete="off">
  <span class="muted small" data-table-search-count data-label="synonyme(s)" aria-live="polite"><?= count($rows) ?> synonyme(s)</span>
</div>
<?php endif; ?>
<table class="table" id="synonyms-table">
  <thead><tr><th>Type</th><th>Terme</th><th>Synonymes</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $list = str_replace('|', ', ', $r['synonyms']); ?>
    <tr data-search="<?= e($r['term'] . ' ' . $list) ?>"><td><?= $r['type'] === 'alias' ? 'Alias' : 'Équivalent' ?></td><td><strong><?= e($r['term']) ?></strong></td><td><?= e($list) ?></td>
      <td class="actions">
        <button type="button" class="btn btn-sm btn-light" data-dialog-open="synonym-dialog"
          data-fill-id="<?= (int) $r['id'] ?>" data-fill-type="<?= e($r['type']) ?>" data-fill-term="<?= e($r['term']) ?>" data-fill-synonyms="<?= e($list) ?>">Modifier</button>
        <form method="post" action="<?= e(url('/admin/synonyms/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="Supprimer ?">Supprimer</button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="4" class="muted">Aucun synonyme.</td></tr><?php endif; ?>
  <tr data-table-search-empty hidden><td colspan="4" class="muted">Aucun synonyme ne correspond à cette recherche.</td></tr>
  </tbody>
</table>

<dialog id="synonym-dialog" class="modal" aria-labelledby="synonym-dialog-title">
  <form method="post" action="<?= e(url('/admin/synonyms/update')) ?>" class="form modal-form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="">
    <div class="modal-head">
      <div class="modal-title"><h2 id="synonym-dialog-title">Modifier le synonyme</h2></div>
      <button type="button" class="icon-btn modal-close" data-dialog-close aria-label="Fermer">✕</button>
    </div>
    <div class="modal-body">
      <label>Type <select name="type"><option value="equivalent">Équivalent (dans les deux sens)</option><option value="alias">Alias (sens unique : faute de frappe, abréviation)</option></select></label>
      <label>Terme <input name="term" required maxlength="190"></label>
      <label>Synonymes <input name="synonyms" required>
        <small class="muted">Séparés par des virgules ou des |.</small></label>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-light" data-dialog-close>Annuler</button>
      <button class="btn">Enregistrer</button>
    </div>
  </form>
</dialog>
