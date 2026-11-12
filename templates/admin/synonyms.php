<p class="muted">Langue <?= e(strtoupper($lang)) ?>. <strong>Équivalent</strong> : tous les termes sont interchangeables (palme = palmes = fins).
  <strong>Alias</strong> : sens unique (« combi » cherche aussi « combinaison », pas l'inverse). Les changements sont envoyés au moteur immédiatement.</p>
<div class="grid2">
  <form method="post" action="<?= e(url('/admin/synonyms/add')) ?>" class="card form">
    <?= csrf_field() ?>
    <h3>Ajouter</h3>
    <label>Type <select name="type"><option value="equivalent">Équivalent</option><option value="alias">Alias (sens unique)</option></select></label>
    <label>Terme <input name="term" required placeholder="palme"></label>
    <label>Synonymes (séparés par | ou ,) <input name="synonyms" required placeholder="palmes|fins"></label>
    <button class="btn">Ajouter</button>
  </form>
  <form method="post" action="<?= e(url('/admin/synonyms/import')) ?>" enctype="multipart/form-data" class="card form">
    <?= csrf_field() ?>
    <h3>Importer un CSV</h3>
    <p class="muted">Colonnes : <code>type;terme;synonymes</code> (voir samples/synonymes.csv)</p>
    <input type="file" name="csv" accept=".csv,text/csv" required>
    <label class="check"><input type="checkbox" name="replace"> Remplacer tous les synonymes de cette langue</label>
    <button class="btn">Importer</button>
  </form>
</div>
<form method="post" action="<?= e(url('/admin/synonyms/sync')) ?>"><?= csrf_field() ?><button class="btn btn-light btn-sm">Renvoyer tous les synonymes au moteur</button></form>
<table class="table">
  <thead><tr><th>Type</th><th>Terme</th><th>Synonymes</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= $r['type'] === 'alias' ? 'Alias' : 'Équivalent' ?></td><td><strong><?= e($r['term']) ?></strong></td><td><?= e(str_replace('|', ', ', $r['synonyms'])) ?></td>
      <td class="actions"><form method="post" action="<?= e(url('/admin/synonyms/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="Supprimer ?">Supprimer</button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="4" class="muted">Aucun synonyme.</td></tr><?php endif; ?>
  </tbody>
</table>
