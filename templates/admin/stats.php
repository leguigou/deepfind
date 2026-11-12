<?php $s = (int) ($kpi['searches'] ?? 0); ?>
<form method="get" class="inline-form">
  Période :
  <?php foreach ([1 => '24 h', 7 => '7 jours', 30 => '30 jours', 90 => '90 jours'] as $d => $l): ?>
    <a class="btn btn-sm <?= $days === $d ? '' : 'btn-light' ?>" href="<?= e(url('/admin/stats', ['days' => $d])) ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
  <span class="muted">· langue <?= e(strtoupper($lang)) ?> · seules les recherches « finales » sont comptées (pas chaque lettre tapée)</span>
</form>
<div class="cards">
  <div class="card kpi"><div class="kpi-label">Recherches</div><div class="kpi-value"><?= fmt_n($s) ?></div></div>
  <div class="card kpi"><div class="kpi-label">Requêtes distinctes</div><div class="kpi-value"><?= fmt_n($kpi['uniq'] ?? 0) ?></div></div>
  <div class="card kpi"><div class="kpi-label">Sans résultat</div><div class="kpi-value"><?= $s ? fmt_n(100 * $kpi['zero'] / $s, 1) . ' %' : '—' ?></div></div>
  <div class="card kpi"><div class="kpi-label">Clics</div><div class="kpi-value"><?= fmt_n($kpi['clicks']) ?></div><div class="muted">taux de clic : <?= $s ? fmt_n(100 * $kpi['clicks'] / $s, 1) . ' %' : '—' ?></div></div>
  <div class="card kpi"><div class="kpi-label">Temps moyen</div><div class="kpi-value"><?= fmt_n($kpi['took'] ?? 0) ?> ms</div></div>
</div>

<div class="grid2">
  <div>
    <h2>Top requêtes</h2>
    <table class="table compact">
      <thead><tr><th>Requête</th><th class="num">Recherches</th><th class="num">Résultats moy.</th><th class="num">Clics</th></tr></thead>
      <tbody>
      <?php foreach ($top as $t): ?>
        <tr><td><a href="<?= e(url('/admin/preview', ['q' => $t['query_norm']])) ?>"><?= e($t['query_norm']) ?></a></td><td class="num"><?= fmt_n($t['n']) ?></td>
          <td class="num"><?= fmt_n($t['avg_results']) ?></td><td class="num"><?= fmt_n($t['clicks']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$top): ?><tr><td colspan="4" class="muted">Pas encore de données.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div>
    <h2>Recherches sans résultat <a class="btn btn-sm btn-light" href="<?= e(url('/admin/logs/export', ['zero' => 1, 'days' => $days])) ?>">Exporter CSV</a></h2>
    <p class="muted">Pistes : ajouter un synonyme, une redirection, ou compléter les mots-clés du produit dans Magento.</p>
    <table class="table compact">
      <thead><tr><th>Requête</th><th class="num">Nombre</th><th>Dernière</th></tr></thead>
      <tbody>
      <?php foreach ($zero as $z): ?>
        <tr><td><?= e($z['query_norm']) ?></td><td class="num"><?= fmt_n($z['n']) ?></td><td><?= fmt_dt($z['last_at']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$zero): ?><tr><td colspan="3" class="muted">Aucune.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid2">
  <div>
    <h2>Par jour</h2>
    <table class="table compact">
      <thead><tr><th>Jour</th><th class="num">Recherches</th><th class="num">Sans résultat</th></tr></thead>
      <tbody><?php foreach ($daily as $d): ?><tr><td><?= e(date('d/m/Y', strtotime($d['d']))) ?></td><td class="num"><?= fmt_n($d['n']) ?></td><td class="num"><?= fmt_n($d['zero']) ?></td></tr><?php endforeach; ?></tbody>
    </table>
  </div>
  <div>
    <h2>Éléments les plus cliqués</h2>
    <table class="table compact">
      <thead><tr><th>Type</th><th>Identifiant</th><th class="num">Clics</th></tr></thead>
      <tbody><?php foreach ($clicked as $c): ?><tr><td><?= $c['doc_type'] === 'post' ? 'Article' : 'Produit' ?></td><td><code><?= e($c['doc_id']) ?></code></td><td class="num"><?= fmt_n($c['n']) ?></td></tr><?php endforeach; ?></tbody>
    </table>
  </div>
</div>
