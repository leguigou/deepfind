<?php /** @var array $rows @var array $info @var string $filter @var ?string $error @var string $back */ ?>
<p class="muted">Produits retirés de <strong>toutes</strong> les recherches du site (bouton « Désactiver » de l'<a href="<?= e(url('/admin/preview')) ?>">aperçu interne</a>).
  Ils restent dans le flux et dans l'index : « Activer » les fait réapparaître immédiatement.</p>
<?php if ($error): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<div class="page-head">
  <form method="get" class="form inline dis-filter">
    <label>Filtrer <input name="f" value="<?= e($filter) ?>" placeholder="SKU, nom ou marque"></label>
    <button class="btn btn-light">Filtrer</button>
    <?php if ($filter !== ''): ?><a href="<?= e(url('/admin/disabled')) ?>">Tout afficher</a><?php endif; ?>
  </form>
  <?php if ($rows && $filter === ''): ?>
    <form method="post" action="<?= e(url('/admin/disabled/enable-all')) ?>"><?= csrf_field() ?>
      <button class="btn btn-light" data-confirm="Réactiver les <?= count($rows) ?> produit(s) désactivé(s) ? Ils réapparaîtront dans les recherches du site.">Tout réactiver</button></form>
  <?php endif; ?>
</div>
<p><strong><?= count($rows) ?></strong> produit(s) désactivé(s)<?= $filter !== '' ? ' correspondant au filtre' : '' ?>.</p>
<div class="table-scroll">
<table class="table compact pv-table">
  <thead><tr><th></th><th>Produit</th><th class="num">Prix</th><th>Stock</th><th>Désactivé le</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $d = $info[mb_strtoupper($r['sku'], 'UTF-8')] ?? null; ?>
    <tr>
      <td><?php if (!empty($d['image'])): ?><img class="pv-thumb" src="<?= e($d['image']) ?>" alt="" loading="lazy"><?php endif; ?></td>
      <td class="pv-product">
        <?php if ($d): ?><a href="<?= e($d['url']) ?>" target="_blank" rel="noopener"><?= e($d['name']) ?></a><br><span class="muted"><?= e($d['brand'] ?? '') ?></span> · <?php else: ?><span class="muted">Absent de l'index (plus dans le flux ?)</span><br><?php endif; ?>
        <code><?= e($r['sku']) ?></code></td>
      <td class="num"><?= $d ? fmt_n($d['final_price'] ?? 0, 2) . ' €' : '—' ?></td>
      <td><?= !$d ? '—' : (!empty($d['in_stock']) ? '<span class="badge badge-ok">' . (int) ($d['salable_qty'] ?? 0) . '</span>' : '<span class="badge badge-ko">rupture</span>') ?></td>
      <td><?= fmt_dt($r['created_at']) ?></td>
      <td><div class="pv-actions">
        <form method="post" action="<?= e(url('/admin/preview/toggle')) ?>"><?= csrf_field() ?>
          <input type="hidden" name="sku" value="<?= e($r['sku']) ?>"><input type="hidden" name="state" value="on"><input type="hidden" name="back" value="<?= e($back) ?>">
          <button class="btn btn-sm">Activer</button></form>
        <a class="btn btn-sm btn-light" href="<?= e(url('/admin/preview', ['q' => $r['sku'], 'show_disabled' => 1])) ?>">Voir dans l'aperçu</a>
      </div></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted"><?= $filter !== '' ? 'Aucun produit désactivé ne correspond à ce filtre.' : 'Aucun produit désactivé.' ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
