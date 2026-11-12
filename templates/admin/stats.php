<?php $s = (int) ($kpi['searches'] ?? 0); $back = '/admin/stats?days=' . $days; ?>
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
  <div class="card kpi"><div class="kpi-label">Sans résultat exact</div><div class="kpi-value"><?= $s ? fmt_n(100 * $kpi['zero'] / $s, 1) . ' %' : '—' ?></div><div class="muted">aucun résultat ou seulement des approchants</div></div>
  <div class="card kpi"><div class="kpi-label">Clics</div><div class="kpi-value"><?= fmt_n($kpi['clicks']) ?></div><div class="muted">taux de clic : <?= $s ? fmt_n(100 * $kpi['clicks'] / $s, 1) . ' %' : '—' ?></div></div>
  <div class="card kpi"><div class="kpi-label">Temps moyen</div><div class="kpi-value"><?= fmt_n($kpi['took'] ?? 0) ?> ms</div></div>
</div>

<h2>Recherches sans résultat exact <a class="btn btn-sm btn-light" href="<?= e(url('/admin/logs/export', ['zero' => 1, 'days' => $days])) ?>">Exporter CSV</a></h2>
<p class="muted">Pour chaque requête : ajoutez un <strong>synonyme</strong> (ex. « combi » → « combinaison »), une <strong>redirection</strong> vers une page, ou un <strong>produit épinglé</strong>.
  Si vous ne vendez pas ce produit, ne faites rien : le visiteur voit « Aucun résultat exact ».</p>
<table class="table compact zero-table">
  <thead><tr><th>Requête</th><th class="num">Recherches</th><th>Ce que le visiteur a vu</th><th>Dernière</th><th>Ajouter un synonyme (la requête trouvera aussi ces mots)</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($zero as $z): ?>
    <tr>
      <td><strong><?= e($z['query_norm']) ?></strong></td>
      <td class="num"><?= fmt_n($z['n']) ?></td>
      <td><?= (int) $z['approx'] ? '<span class="badge badge-warn">' . fmt_n($z['results']) . ' approchant(s)</span>' : '<span class="badge badge-ko">aucun résultat</span>' ?></td>
      <td><?= fmt_dt($z['last_at']) ?></td>
      <td>
        <form method="post" action="<?= e(url('/admin/synonyms/add')) ?>" class="inline-form syn-form">
          <?= csrf_field() ?>
          <input type="hidden" name="back" value="<?= e($back) ?>">
          <input type="hidden" name="type" value="alias">
          <input type="hidden" name="term" value="<?= e($z['query_norm']) ?>">
          <input name="synonyms" required placeholder="mots qui existent dans le catalogue" aria-label="Synonymes pour <?= e($z['query_norm']) ?>">
          <button class="btn btn-sm">Ajouter</button>
        </form>
      </td>
      <td class="actions">
        <a class="btn btn-sm btn-light" href="<?= e(url('/admin/redirects', ['query' => $z['query_norm']])) ?>">Redirection</a>
        <a class="btn btn-sm btn-light" href="<?= e(url('/admin/rules', ['query' => $z['query_norm']])) ?>">Épingler</a>
        <a class="btn btn-sm btn-light" href="<?= e(url('/admin/preview', ['q' => $z['query_norm']])) ?>">Aperçu</a>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$zero): ?><tr><td colspan="6" class="muted">Aucune sur la période.</td></tr><?php endif; ?>
  </tbody>
</table>

<h2>Produits les plus cliqués</h2>
<p class="muted">Les clics des 30 derniers jours peuvent faire remonter un produit dans les résultats : critère « Clics » dans <a href="<?= e(url('/admin/relevance')) ?>">Pertinence &amp; classement</a>.</p>
<table class="table compact">
  <thead><tr><th></th><th>Produit</th><th>SKU</th><th class="num">Clics</th><th class="num">Requêtes différentes</th><th class="num">Position moy.</th><th>Dernières requêtes</th></tr></thead>
  <tbody>
  <?php foreach ($clicked as $c): ?>
    <tr>
      <td><?php if ($c['image']): ?><img class="pv-thumb" src="<?= e($c['image']) ?>" alt="" loading="lazy"><?php endif; ?></td>
      <td><?= $c['name'] ? ($c['url'] ? '<a href="' . e($c['url']) . '" target="_blank" rel="noopener">' . e($c['name']) . '</a>' : e($c['name'])) : '<span class="muted">' . e($c['doc_id']) . ' (plus dans l\'index)</span>' ?></td>
      <td><code><?= e($c['sku'] ?? '') ?></code></td>
      <td class="num"><strong><?= fmt_n($c['n']) ?></strong></td>
      <td class="num"><?= fmt_n($c['queries']) ?></td>
      <td class="num"><?= fmt_n($c['pos'], 1) ?></td>
      <td class="muted"><?= e(str_replace('|', ' · ', (string) $c['last_queries'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$clicked): ?><tr><td colspan="7" class="muted">Pas encore de clic sur la période.</td></tr><?php endif; ?>
  </tbody>
</table>

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
    <h2>Requêtes avec résultats mais sans clic</h2>
    <p class="muted">Recherchées au moins 3 fois, jamais cliquées : les résultats ne correspondent peut-être pas à l'attente. Ouvrez l'aperçu pour vérifier.</p>
    <table class="table compact">
      <thead><tr><th>Requête</th><th class="num">Recherches</th><th class="num">Résultats moy.</th></tr></thead>
      <tbody>
      <?php foreach ($noClick as $t): ?>
        <tr><td><a href="<?= e(url('/admin/preview', ['q' => $t['query_norm']])) ?>"><?= e($t['query_norm']) ?></a></td><td class="num"><?= fmt_n($t['n']) ?></td><td class="num"><?= fmt_n($t['avg_results']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$noClick): ?><tr><td colspan="3" class="muted">Aucune.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid2">
  <div>
    <h2>Par jour</h2>
    <table class="table compact">
      <thead><tr><th>Jour</th><th class="num">Recherches</th><th class="num">Sans résultat exact</th></tr></thead>
      <tbody><?php foreach ($daily as $d): ?><tr><td><?= e(date('d/m/Y', strtotime($d['d']))) ?></td><td class="num"><?= fmt_n($d['n']) ?></td><td class="num"><?= fmt_n($d['zero']) ?></td></tr><?php endforeach; ?></tbody>
    </table>
  </div>
  <div>
    <h2>Articles de blog cliqués</h2>
    <table class="table compact">
      <thead><tr><th>Article</th><th class="num">Clics</th></tr></thead>
      <tbody><?php foreach ($clickedPosts as $c): ?><tr><td><code><?= e($c['doc_id']) ?></code></td><td class="num"><?= fmt_n($c['n']) ?></td></tr><?php endforeach; ?>
      <?php if (!$clickedPosts): ?><tr><td colspan="2" class="muted">Aucun.</td></tr><?php endif; ?></tbody>
    </table>
  </div>
</div>
