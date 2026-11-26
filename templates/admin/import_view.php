<?php $rep = $run['report_data']; ?>
<p><a href="<?= e(url('/admin/imports')) ?>">← Historique</a></p>
<?php $pg = \DeepFind\Import\Progress::of($run); ?>
<div class="card prog-big" <?= $pg['active'] ? 'data-run-progress="' . (int) $run['id'] . '"' : '' ?>>
  <div class="prog-head">
    <strong data-f="label"><?= e($pg['label']) ?></strong>
    <span class="prog-pct"><span data-f="percent"><?= (int) $pg['percent'] ?></span> %</span>
  </div>
  <div class="prog-bar"><span style="width: <?= (int) $pg['percent'] ?>%"></span></div>
  <div class="prog-meta muted">
    <span data-f="detail"><?= e($pg['detail']) ?></span>
    · écoulé <span data-f="elapsed"><?= e($pg['elapsed']) ?></span>
    <span data-f-wrap="eta" <?= $pg['eta'] ? '' : 'hidden' ?>>· reste environ <span data-f="eta"><?= e((string) $pg['eta']) ?></span></span>
  </div>
  <ol class="prog-steps">
    <?php $phases = \DeepFind\Import\Progress::PHASES; unset($phases['done']);
    if ($run['kind'] === 'posts' || $run['type'] === 'delta') { unset($phases['group']); }
    if ($run['type'] === 'delta') { unset($phases['swap']); }
    if ($run['type'] === 'scores') { $phases = ['score' => $phases['score'], 'index' => $phases['index']]; } else { unset($phases['score']); }
    $cur = array_search((string) ($run['phase'] ?? ''), array_keys($phases), true);
    $i = 0; foreach ($phases as $k => $l): ?>
      <li data-step="<?= e($k) ?>" class="<?= $run['status'] === 'success' || ($cur !== false && $i < $cur) ? 'done' : ($cur === $i && $pg['active'] ? 'on' : '') ?>"><?= e($l) ?></li>
    <?php $i++; endforeach; ?>
  </ol>
  <?php if ($pg['active']): ?><p class="muted small">Vous pouvez quitter cette page : l'import continue en arrière-plan. Les résultats seront visibles dans le moteur dès la fin, sans délai de cache.</p><?php endif; ?>
</div>
<?php
$typeLabel = ['full' => 'Import complet', 'delta' => 'Mise à jour (delta)', 'scores' => 'Scores'][$run['type']] ?? $run['type'];
$sellable = isset($rep['index_documents']) ? (int) ($rep['sellable_documents'] ?? $run['items_indexed']) : null;
$keptOff = $sellable !== null ? max(0, (int) $rep['index_documents'] - $sellable) : 0;
?>
<div class="imp-kpis">
  <div class="card kpi"><div class="kpi-label">Statut</div><div class="kpi-value"><?= badge($run['status']) ?></div>
    <?php if ($run['started_at']): ?><div class="muted small">le <?= e(date('d/m/Y à H:i', strtotime((string) $run['started_at']))) ?></div><?php endif; ?></div>
  <div class="card kpi"><div class="kpi-label">Source</div><div class="kpi-value imp-src" title="<?= e($run['code']) ?>"><?= e($run['code']) ?></div>
    <div class="muted small"><?= e($typeLabel) ?></div></div>
  <div class="card kpi"><div class="kpi-label">Produits du flux</div><div class="kpi-value"><?= fmt_n($run['items_read']) ?> <small class="muted">lus</small></div>
    <div class="small"><span class="imp-ok"><?= fmt_n($run['items_indexed']) ?> indexés</span> · <span class="<?= $run['items_rejected'] > 0 ? 'imp-ko' : 'muted' ?>"><?= fmt_n($run['items_rejected']) ?> rejetés</span></div></div>
  <div class="card kpi"><div class="kpi-label">Durée</div><div class="kpi-value"><?= isset($rep['duration_s']) ? e(\DeepFind\Import\Progress::duration((int) round($rep['duration_s']))) : '—' ?></div>
    <?php if (isset($rep['memory_peak_mb'])): ?><div class="muted small">mémoire max. <?= fmt_n($rep['memory_peak_mb'], 1) ?> Mo</div><?php endif; ?></div>
  <?php if ($sellable !== null): ?>
  <div class="card kpi"><div class="kpi-label">Index en ligne</div><div class="kpi-value"><?= fmt_n($rep['index_documents']) ?> <small class="muted">documents</small></div>
    <div class="small"><?= fmt_n($sellable) ?> vendables <span class="muted">(avant : <?= fmt_n($rep['previous_documents'] ?? 0) ?>)</span><?php if ($keptOff > 0): ?>
      · <span title="Produits absents du flux, gardés <?= \DeepFind\Import\Importer::RETAIN_DAYS ?> jours comme désactivés (non affichés) avant d'être retirés"><?= fmt_n($keptOff) ?> désactivés auto</span><?php endif; ?></div></div>
  <?php endif; ?>
</div>
<?php if ($run['message']): ?><div class="flash flash-<?= $run['status'] === 'success' ? 'ok' : ($run['status'] === 'aborted' ? 'warn' : 'error') ?>"><?= e($run['message']) ?></div><?php endif; ?>
<?php if (!empty($rep['filterable_attributes'])): ?><p class="imp-attrs"><span class="muted">Attributs filtrables détectés :</span>
  <?php foreach ($rep['filterable_attributes'] as $at): ?><code><?= e($at) ?></code><?php endforeach; ?></p><?php endif; ?>
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
