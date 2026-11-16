<p class="muted">Lancement manuel : <a href="<?= e(url('/admin/sources')) ?>">Flux &amp; mapping</a> › « Import complet » ou « Delta ». Les imports sont traités en arrière-plan par le worker ; l'avancement se met à jour tout seul.</p>
<?php
// Durée de traitement (début → fin) ; écart avec l'import complet précédent (produits ajoutés / réactivés en vert, désactivés auto en rouge)
$duration = static function (array $r): string {
    if (!$r['started_at']) {
        return '—';
    }
    $s = max(0, (int) strtotime((string) ($r['finished_at'] ?: date('Y-m-d H:i:s'))) - (int) strtotime((string) $r['started_at']));
    $txt = $s < 60 ? $s . ' s' : intdiv($s, 60) . ' min ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . ' s';
    return $r['finished_at'] ? $txt : $txt . ' (en cours)';
};
?>
<div class="table-scroll">
<table class="table imports-table">
  <thead><tr><th>#</th><th>Source</th><th>Type</th><th>Déclencheur</th><th>Statut</th><th>Début</th><th>Fin</th><th class="num">Durée</th>
    <th class="num">Lus</th><th class="num">Indexés</th><th class="num">Rejetés</th><th class="num" title="Par rapport à l'import complet précédent : produits ajoutés ou réactivés (vert), désactivés auto car absents du flux (rouge)">Écart</th><th>Message</th></tr></thead>
  <tbody>
  <?php foreach ($runs as $r): $rep = json_decode((string) ($r['report'] ?? ''), true) ?: []; $pg = \DeepFind\Import\Progress::of($r); ?>
    <tr>
      <td><a href="<?= e(url('/admin/imports/view', ['id' => $r['id']])) ?>"><?= (int) $r['id'] ?></a></td>
      <td><?= e($r['code']) ?></td><td><?= e($r['type']) ?></td><td><?= e($r['trigger_by']) ?></td>
      <td><span data-run-status="<?= (int) $r['id'] ?>"><?= badge($r['status']) ?></span>
        <?php if ($pg['active']): ?>
        <div class="prog prog-mini" data-run-progress="<?= (int) $r['id'] ?>">
          <div class="prog-bar"><span style="width: <?= (int) $pg['percent'] ?>%"></span></div>
          <div class="prog-text muted"><span data-f="percent"><?= (int) $pg['percent'] ?></span> % · <span data-f="label"><?= e($pg['label']) ?></span></div>
        </div>
        <?php endif; ?></td>
      <td class="nowrap"><?= fmt_dt($r['started_at']) ?></td><td class="nowrap"><?= fmt_dt($r['finished_at']) ?></td>
      <td class="num nowrap"><?= e($duration($r)) ?></td>
      <td class="num"><?= fmt_n($r['items_read']) ?></td><td class="num"><?= fmt_n($r['items_indexed']) ?></td><td class="num"><?= fmt_n($r['items_rejected']) ?></td>
      <td class="num nowrap"><?php if (isset($rep['auto_disabled'])):
          $plus = (int) ($rep['added'] ?? 0) + (int) ($rep['reactivated'] ?? 0); $minus = (int) $rep['auto_disabled']; ?>
        <span class="diff" title="<?= e(sprintf('%d nouveau(x), %d réactivé(s), %d désactivé(s) auto', (int) ($rep['added'] ?? 0), (int) ($rep['reactivated'] ?? 0), $minus)) ?>">
          <?= $plus ? '<span class="diff-plus">+' . fmt_n($plus) . '</span>' : '' ?><?= $minus ? '<span class="diff-minus">−' . fmt_n($minus) . '</span>' : '' ?><?= !$plus && !$minus ? '<span class="muted">0</span>' : '' ?></span>
      <?php else: ?><span class="muted">—</span><?php endif; ?></td>
      <td class="ellipsis"><?= e($r['message']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$runs): ?><tr><td colspan="13" class="muted">Aucun import.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted small">Écart : comparaison avec l'import complet précédent, par SKU. Vert = produits nouveaux ou de retour dans le flux (réactivés automatiquement) ;
  rouge = produits absents du flux, désactivés auto (gardés <?= \DeepFind\Import\Importer::RETAIN_DAYS ?> jours dans l'index sans être proposés, puis retirés).
  Les produits désactivés à la main restent désactivés quoi qu'il arrive.</p>
