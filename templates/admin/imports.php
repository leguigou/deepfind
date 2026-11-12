<p class="muted">Les imports manuels sont traités par le worker (Supervisor) ou par la tâche cron « queue:work » (toutes les minutes). Rechargez la page pour suivre l'avancement.</p>
<table class="table">
  <thead><tr><th>#</th><th>Source</th><th>Type</th><th>Déclencheur</th><th>Statut</th><th>Créé</th><th>Début</th><th>Fin</th><th class="num">Lus</th><th class="num">Indexés</th><th class="num">Rejetés</th><th>Message</th></tr></thead>
  <tbody>
  <?php foreach ($runs as $r): ?>
    <tr>
      <td><a href="<?= e(url('/admin/imports/view', ['id' => $r['id']])) ?>"><?= (int) $r['id'] ?></a></td>
      <td><?= e($r['code']) ?></td><td><?= e($r['type']) ?></td><td><?= e($r['trigger_by']) ?></td><td><?= badge($r['status']) ?></td>
      <td><?= fmt_dt($r['created_at']) ?></td><td><?= fmt_dt($r['started_at']) ?></td><td><?= fmt_dt($r['finished_at']) ?></td>
      <td class="num"><?= fmt_n($r['items_read']) ?></td><td class="num"><?= fmt_n($r['items_indexed']) ?></td><td class="num"><?= fmt_n($r['items_rejected']) ?></td>
      <td class="ellipsis"><?= e($r['message']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$runs): ?><tr><td colspan="12" class="muted">Aucun import.</td></tr><?php endif; ?>
  </tbody>
</table>
