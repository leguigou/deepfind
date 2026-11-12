<?php use DeepFind\Project; $current = Project::id(); ?>
<p class="muted">Chaque projet a ses propres flux, index de recherche, synonymes, règles, boosts, paramètres de pertinence, clés, statistiques et journaux.
  Exemple : « Production » pour le site, « Test » pour essayer des réglages sans toucher aux résultats du site, puis <a href="<?= e(url('/admin/projects/copy')) ?>">copier la configuration</a> validée.
  La protection anti-robots et le jeton d'accès sont communs à tous les projets.</p>

<table class="table">
  <thead><tr><th>Projet</th><th>Code / index</th><th class="num">Flux</th><th class="num">Clés actives</th><th class="num">Recherches 7 j</th><th>Dernier import</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['id'] === $current ? 'row-current' : '' ?>">
      <td>
        <form method="post" action="<?= e(url('/admin/projects/rename')) ?>" class="inline-form">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <input name="name" value="<?= e($r['name']) ?>" aria-label="Nom du projet" class="name-input">
          <button class="btn btn-sm btn-light">Renommer</button>
        </form>
        <?= $r['id'] === $current ? '<span class="badge badge-ok">projet actif</span>' : '' ?>
      </td>
      <td><code><?= e($r['code']) ?></code><br><span class="muted small"><?= e($r['index_prefix']) ?>products_…</span></td>
      <td class="num"><?= fmt_n($r['sources']) ?></td>
      <td class="num"><?= fmt_n($r['keys']) ?></td>
      <td class="num"><?= fmt_n($r['searches']) ?></td>
      <td><?= fmt_dt($r['last_import'] ?: null) ?></td>
      <td class="actions">
        <?php if ($r['id'] !== $current): ?>
          <form method="post" action="<?= e(url('/admin/project')) ?>"><?= csrf_field() ?><input type="hidden" name="project_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="back" value="/admin/projects"><button class="btn btn-sm">Ouvrir</button></form>
        <?php endif; ?>
        <a class="btn btn-sm btn-light" href="<?= e(url('/admin/projects/copy', ['from' => $r['id']])) ?>">Copier sa configuration…</a>
        <?php if (count($rows) > 1): ?>
        <details class="danger-details"><summary class="btn btn-sm btn-danger">Supprimer…</summary>
          <form method="post" action="<?= e(url('/admin/projects/delete')) ?>" class="card form">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <p>Supprime définitivement ses flux, index de recherche, règles, boosts, clés, statistiques et journaux.</p>
            <label>Saisissez <code><?= e($r['code']) ?></code> pour confirmer <input name="confirm" autocomplete="off"></label>
            <button class="btn btn-danger btn-sm">Supprimer définitivement</button>
          </form>
        </details>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<div class="grid2">
  <form method="post" action="<?= e(url('/admin/projects/create')) ?>" class="card form">
    <?= csrf_field() ?>
    <h3>Nouveau projet</h3>
    <div class="grid2">
      <label>Nom <input name="name" required placeholder="Cabesto Test"></label>
      <label>Code (minuscules) <input name="code" required pattern="[a-z0-9][a-z0-9\-]{1,19}" placeholder="test"></label>
    </div>
    <label>Point de départ
      <select name="copy_from">
        <option value="0">Projet vide</option>
        <?php foreach ($rows as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $r['id'] === $current ? 'selected' : '' ?>>Copie de la configuration de « <?= e($r['name']) ?> »</option><?php endforeach; ?>
      </select>
      <small class="muted">La copie reprend flux (inactifs), mapping, pertinence, synonymes, règles, redirections et boosts ; pas les statistiques ni les clés. Il faudra lancer un import pour remplir ses index.</small>
    </label>
    <button class="btn">Créer le projet</button>
  </form>

  <div class="card">
    <h3>Sauvegardes de « <?= e(Project::current()['name']) ?> »</h3>
    <p class="muted small">Une sauvegarde est créée automatiquement avant chaque copie vers ce projet. Restaurer remet la configuration (pertinence, synonymes, règles, redirections, boosts, mapping et planification des flux) telle qu'elle était.</p>
    <form method="post" action="<?= e(url('/admin/projects/snapshot')) ?>" class="inline-form">
      <?= csrf_field() ?><input name="label" placeholder="Nom de la sauvegarde (facultatif)"><button class="btn btn-sm btn-light">Sauvegarder maintenant</button>
      <a class="btn btn-sm btn-light" href="<?= e(url('/admin/projects/export')) ?>">Télécharger la configuration (JSON)</a>
    </form>
    <table class="table compact">
      <tbody>
      <?php foreach ($snapshots as $s): ?>
        <tr><td>#<?= (int) $s['id'] ?></td><td><?= e($s['label']) ?></td><td><?= fmt_dt($s['created_at']) ?></td>
          <td class="actions"><form method="post" action="<?= e(url('/admin/projects/restore')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button class="btn btn-sm btn-light" data-confirm="Restaurer la sauvegarde #<?= (int) $s['id'] ?> sur « <?= e(Project::current()['name']) ?> » ?">Restaurer</button></form></td></tr>
      <?php endforeach; ?>
      <?php if (!$snapshots): ?><tr><td class="muted">Aucune sauvegarde.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
