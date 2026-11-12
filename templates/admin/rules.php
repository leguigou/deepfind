<p class="muted">Langue <?= e(strtoupper($lang)) ?>. Une règle s'applique quand la recherche correspond exactement à la requête (sans tenir compte des majuscules ni des accents).
  Requête <code>*</code> + « Masquer » : produit masqué de toutes les recherches. Actif immédiatement.</p>
<div class="grid2">
  <form method="post" action="<?= e(url('/admin/rules/add')) ?>" class="card form">
    <?= csrf_field() ?>
    <h3>Ajouter une règle</h3>
    <label>Requête <input name="query" required placeholder="palme"></label>
    <label>SKU parent (plusieurs possibles, séparés par des espaces) <input name="sku" required placeholder="C4-PREDATOR"></label>
    <div class="grid2">
      <label>Action <select name="action"><?php foreach ($actions as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label>Position (épingler) <input type="number" name="position" min="1" max="100" placeholder="1"></label>
      <label>Début (facultatif) <input type="date" name="starts_at"></label>
      <label>Fin (facultatif) <input type="date" name="ends_at"></label>
    </div>
    <button class="btn">Ajouter</button>
  </form>
  <form method="post" action="<?= e(url('/admin/rules/import')) ?>" enctype="multipart/form-data" class="card form">
    <?= csrf_field() ?>
    <h3>Importer un CSV</h3>
    <p class="muted">Colonnes : <code>requete;sku;action;position_ou_poids;debut;fin</code> avec action = pin, boost ou hide (voir samples/boosts.csv)</p>
    <input type="file" name="csv" accept=".csv,text/csv" required>
    <label class="check"><input type="checkbox" name="replace"> Remplacer toutes les règles de cette langue</label>
    <button class="btn">Importer</button>
  </form>
</div>
<table class="table">
  <thead><tr><th>Requête</th><th>SKU</th><th>Action</th><th>Position</th><th>Période</th><th></th></tr></thead>
  <tbody>
  <?php $today = date('Y-m-d'); foreach ($rows as $r):
      $expired = ($r['ends_at'] && $r['ends_at'] < $today) || ($r['starts_at'] && $r['starts_at'] > $today); ?>
    <tr class="<?= $expired ? 'dim' : '' ?>"><td><strong><?= e($r['query']) ?></strong></td><td><code><?= e($r['sku']) ?></code></td><td><?= e($actions[$r['action']] ?? $r['action']) ?></td>
      <td><?= $r['position'] ? (int) $r['position'] : '—' ?></td>
      <td><?= $r['starts_at'] || $r['ends_at'] ? e(($r['starts_at'] ?: '…') . ' → ' . ($r['ends_at'] ?: '…')) : 'Permanente' ?><?= $expired ? ' <span class="badge badge-warn">inactive</span>' : '' ?></td>
      <td class="actions"><form method="post" action="<?= e(url('/admin/rules/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="Supprimer ?">Supprimer</button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">Aucune règle.</td></tr><?php endif; ?>
  </tbody>
</table>
