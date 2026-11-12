<?php foreach ($warnings as $w): ?>
  <div class="flash flash-warn"><?= e($w) ?></div>
<?php endforeach; ?>

<div class="cards">
  <div class="card kpi"><div class="kpi-label">Moteur Meilisearch</div><div class="kpi-value"><?= $healthy ? '<span class="dot ok"></span> Disponible' : '<span class="dot ko"></span> Injoignable' ?></div></div>
  <div class="card kpi"><div class="kpi-label">Recherches (24 h)</div><div class="kpi-value"><?= fmt_n($kpi['searches'] ?? 0) ?></div></div>
  <div class="card kpi"><div class="kpi-label">Sans résultat (24 h)</div><div class="kpi-value"><?= ($kpi['searches'] ?? 0) > 0 ? fmt_n(100 * $kpi['zero'] / $kpi['searches'], 1) . ' %' : '—' ?></div></div>
  <div class="card kpi"><div class="kpi-label">Temps de réponse moyen</div><div class="kpi-value"><?= fmt_n($kpi['took'] ?? 0) ?> ms</div></div>
  <div class="card kpi"><div class="kpi-label">IP bloquées</div><div class="kpi-value"><?= fmt_n($blocked) ?></div><div class="muted"><?= fmt_n($botEvents) ?> alerte(s) robots / 24 h</div></div>
</div>

<h2>Index</h2>
<table class="table">
  <thead><tr><th>Langue</th><th>Type</th><th>Index Meilisearch</th><th class="num">Documents</th></tr></thead>
  <tbody>
  <?php foreach ($indexes as $i): ?>
    <tr><td><?= e(strtoupper($i['lang'])) ?></td><td><?= e($i['label']) ?></td><td><code><?= e($i['uid']) ?></code></td>
      <td class="num"><?= $i['count'] === null ? '<span class="muted">non créé</span>' : fmt_n($i['count']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2>Derniers imports</h2>
<?php if (!$runs): ?><p class="muted">Aucun import pour l'instant. Configurez un flux dans « Flux &amp; mapping ».</p><?php else: ?>
<table class="table">
  <thead><tr><th>#</th><th>Source</th><th>Type</th><th>Statut</th><th>Fin</th><th class="num">Indexés</th><th class="num">Rejetés</th><th>Message</th></tr></thead>
  <tbody>
  <?php foreach ($runs as $r): ?>
    <tr><td><a href="<?= e(url('/admin/imports/view', ['id' => $r['id']])) ?>"><?= (int) $r['id'] ?></a></td><td><?= e($r['code']) ?></td><td><?= e($r['type']) ?></td>
      <td><?= badge($r['status']) ?></td><td><?= fmt_dt($r['finished_at']) ?></td><td class="num"><?= fmt_n($r['items_indexed']) ?></td>
      <td class="num"><?= fmt_n($r['items_rejected']) ?></td><td class="ellipsis"><?= e($r['message']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<p class="muted">Compteurs anti-robots : <?= e($store) ?> · PHP <?= e(PHP_VERSION) ?></p>
