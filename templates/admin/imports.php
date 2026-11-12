<p class="muted">Lancement manuel : <a href="<?= e(url('/admin/sources')) ?>">Flux &amp; mapping</a> › « Import complet » ou « Delta ». Les imports sont traités en arrière-plan par le worker ; l'avancement se met à jour tout seul.</p>
<table class="table">
  <thead><tr><th>#</th><th>Source</th><th>Type</th><th>Déclencheur</th><th>Statut</th><th>Avancement</th><th>Créé</th><th>Début</th><th>Fin</th><th class="num">Lus</th><th class="num">Indexés</th><th class="num">Rejetés</th><th>Message</th></tr></thead>
  <tbody>
  <?php foreach ($runs as $r): ?>
    <tr>
      <td><a href="<?= e(url('/admin/imports/view', ['id' => $r['id']])) ?>"><?= (int) $r['id'] ?></a></td>
      <td><?= e($r['code']) ?></td><td><?= e($r['type']) ?></td><td><?= e($r['trigger_by']) ?></td><td data-run-status="<?= (int) $r['id'] ?>"><?= badge($r['status']) ?></td>
      <td class="prog-cell"><?php $pg = \DeepFind\Import\Progress::of($r); ?>
        <div class="prog" <?= $pg['active'] ? 'data-run-progress="' . (int) $r['id'] . '"' : '' ?>>
          <div class="prog-bar"><span style="width: <?= (int) $pg['percent'] ?>%"></span></div>
          <div class="prog-text muted"><span data-f="percent"><?= (int) $pg['percent'] ?></span> % · <span data-f="label"><?= e($pg['label']) ?></span></div>
        </div></td>
      <td><?= fmt_dt($r['created_at']) ?></td><td><?= fmt_dt($r['started_at']) ?></td><td><?= fmt_dt($r['finished_at']) ?></td>
      <td class="num"><?= fmt_n($r['items_read']) ?></td><td class="num"><?= fmt_n($r['items_indexed']) ?></td><td class="num"><?= fmt_n($r['items_rejected']) ?></td>
      <td class="ellipsis"><?= e($r['message']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$runs): ?><tr><td colspan="13" class="muted">Aucun import.</td></tr><?php endif; ?>
  </tbody>
</table>
