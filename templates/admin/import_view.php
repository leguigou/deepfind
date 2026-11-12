<?php $rep = $run['report_data']; ?>
<p><a href="<?= e(url('/admin/imports')) ?>">← Historique</a></p>
<div class="cards">
  <div class="card kpi"><div class="kpi-label">Statut</div><div class="kpi-value"><?= badge($run['status']) ?></div></div>
  <div class="card kpi"><div class="kpi-label">Source</div><div class="kpi-value"><?= e($run['code']) ?> · <?= e($run['type']) ?></div></div>
  <div class="card kpi"><div class="kpi-label">Lus / indexés / rejetés</div><div class="kpi-value"><?= fmt_n($run['items_read']) ?> / <?= fmt_n($run['items_indexed']) ?> / <?= fmt_n($run['items_rejected']) ?></div></div>
  <div class="card kpi"><div class="kpi-label">Durée</div><div class="kpi-value"><?= isset($rep['duration_s']) ? fmt_n($rep['duration_s'], 1) . ' s' : '—' ?></div></div>
</div>
<?php if ($run['message']): ?><div class="flash flash-<?= $run['status'] === 'success' ? 'ok' : ($run['status'] === 'aborted' ? 'warn' : 'error') ?>"><?= e($run['message']) ?></div><?php endif; ?>
<?php if (isset($rep['index_documents'])): ?><p>Documents dans l'index : <strong><?= fmt_n($rep['index_documents']) ?></strong> (avant : <?= fmt_n($rep['previous_documents'] ?? 0) ?>)</p><?php endif; ?>
<?php if (!empty($rep['filterable_attributes'])): ?><p>Attributs filtrables détectés : <?= e(implode(', ', $rep['filterable_attributes'])) ?></p><?php endif; ?>
<?php if (!empty($rep['rejects'])): ?>
  <h2>Motifs de rejet</h2>
  <table class="table compact"><thead><tr><th>Motif</th><th class="num">Nombre</th></tr></thead><tbody>
  <?php foreach ($rep['rejects'] as $reason => $n): ?><tr><td><?= e($reason) ?></td><td class="num"><?= fmt_n($n) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <h2>Exemples (100 max.)</h2>
  <table class="table compact"><thead><tr><th>SKU</th><th>Motif</th></tr></thead><tbody>
  <?php foreach ($rep['reject_samples'] ?? [] as $s): ?><tr><td><code><?= e($s['sku']) ?></code></td><td><?= e($s['reason']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
<?php endif; ?>
