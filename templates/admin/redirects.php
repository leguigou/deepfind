<p class="muted">Langue <?= e(strtoupper($lang)) ?>. Quand le visiteur valide exactement cette requête (touche Entrée), il est envoyé vers l'URL ; un lien est aussi affiché dans les résultats.</p>
<div class="grid2">
  <form method="post" action="<?= e(url('/admin/redirects/add')) ?>" class="card form">
    <?= csrf_field() ?>
    <h3>Ajouter</h3>
    <label>Requête <input name="query" required placeholder="retour"></label>
    <label>URL <input name="url" type="url" required placeholder="https://www.cabesto.com/cgv#retours"></label>
    <button class="btn">Enregistrer</button>
  </form>
  <form method="post" action="<?= e(url('/admin/redirects/import')) ?>" enctype="multipart/form-data" class="card form">
    <?= csrf_field() ?>
    <h3>Importer un CSV</h3>
    <p class="muted">Colonnes : <code>requete;url</code></p>
    <input type="file" name="csv" accept=".csv,text/csv" required>
    <label class="check"><input type="checkbox" name="replace"> Remplacer toutes les redirections de cette langue</label>
    <button class="btn">Importer</button>
  </form>
</div>
<table class="table">
  <thead><tr><th>Requête</th><th>URL</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><strong><?= e($r['query']) ?></strong></td><td><a href="<?= e($r['url']) ?>" target="_blank" rel="noopener"><?= e($r['url']) ?></a></td>
      <td class="actions"><form method="post" action="<?= e(url('/admin/redirects/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="Supprimer ?">Supprimer</button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="3" class="muted">Aucune redirection.</td></tr><?php endif; ?>
  </tbody>
</table>
