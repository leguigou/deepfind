<?php
/** Recommandations › Campagnes : liste. @var list<array> $list */
$euro = static fn (int $cents) => fmt_n($cents / 100, 2) . ' €';
$statusLabel = ['current' => ['En cours', 'badge-ok'], 'upcoming' => ['À venir', 'badge-warn'], 'ended' => ['Terminée', ''], 'off' => ['Désactivée', 'badge-ko']];
$delta = static function (int $now, int $before): string {
    if ($before === 0) {
        return $now > 0 ? '<span class="cp-up">nouveau</span>' : '<span class="muted">—</span>';
    }
    $pct = round(100 * ($now - $before) / $before);
    return '<span class="' . ($pct >= 0 ? 'cp-up' : 'cp-down') . '">' . ($pct >= 0 ? '+' : '') . $pct . ' %</span>';
};
?>
<p class="muted">Une campagne regroupe les produits que vous voulez pousser sur une période, avec leur objectif, et suit leurs résultats : clics dans la recherche
  et les carrousels, ajouts au panier, commandes, chiffre d'affaires, comparés à la période précédente de même durée. Pour les mettre en avant, utilisez
  ensuite un <a href="<?= e(url('/admin/carousels')) ?>">carrousel</a>, un <a href="<?= e(url('/admin/custom-results')) ?>">résultat personnalisé</a> ou une
  <a href="<?= e(url('/admin/boost-rules')) ?>">règle de boost</a>.</p>
<p><a class="btn" href="<?= e(url('/admin/campaigns/edit')) ?>">+ Nouvelle campagne</a></p>
<div class="table-wrap">
<table class="table cp-table">
  <thead><tr><th>Campagne</th><th>Période</th><th class="num">Produits</th><th class="num">Clics</th><th class="num">Paniers</th><th class="num">Commandes</th><th class="num">Chiffre d'affaires</th><th class="num" title="Chiffre d'affaires comparé à la période précédente de même durée">Évolution du CA</th><th class="actions"></th></tr></thead>
  <tbody>
  <?php foreach ($list as $c): $s = $c['stats']; [$sl, $sc] = $statusLabel[$c['status']]; $ruptures = count(array_filter($s['rows'], static fn ($r) => $r['found'] && !$r['in_stock'])); ?>
    <tr>
      <td><a href="<?= e(url('/admin/campaigns/results', ['id' => $c['id']])) ?>"><?= e($c['name']) ?></a> <span class="badge <?= e($sc) ?>"><?= e($sl) ?></span>
        <?php if (\DeepFind\Rules\Campaigns::isLive($c)): ?><span class="badge badge-info" title="Produits mis en avant dans la recherche et / ou les carrousels">mis en avant</span><?php endif; ?>
        <?php if ($ruptures > 0): ?><span class="badge badge-ko"><?= $ruptures ?> en rupture</span><?php endif; ?>
        <?php if ($c['objective'] !== ''): ?><br><small class="muted"><?= e(mb_strimwidth($c['objective'], 0, 90, '…', 'UTF-8')) ?></small><?php endif; ?></td>
      <td><?= e(date('d/m/Y', strtotime($s['period']['from']))) ?> → <?= $c['ends_at'] ? e(date('d/m/Y', strtotime($c['ends_at']))) : '<span class="muted">sans fin</span>' ?></td>
      <td class="num"><?= fmt_n(count($c['items']) + ($c['stats']['rule']['count'] ?? 0)) ?><?= $c['conditions'] !== [] ? '<br><small class="muted">dont ' . fmt_n($c['stats']['rule']['count'] ?? 0) . ' par règles</small>' : '' ?></td>
      <td class="num"><?= fmt_n($s['total']['clicks']) ?></td>
      <td class="num"><?= fmt_n($s['total']['carts']) ?></td>
      <td class="num"><?= fmt_n($s['total']['orders']) ?></td>
      <td class="num"><strong><?= $euro($s['total']['revenue']) ?></strong></td>
      <td class="num"><?= $delta($s['total']['revenue'], $s['prev']['revenue']) ?></td>
      <td class="actions"><a class="btn btn-sm btn-light" href="<?= e(url('/admin/campaigns/edit', ['id' => $c['id']])) ?>"><?= admin_icon('pencil') ?> Modifier</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($list === []): ?><tr><td colspan="9" class="muted">Aucune campagne. Créez-en une pour suivre les produits que vous voulez pousser.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted small">Ventes et paniers : envoyés par le site (voir l'aide, Analyse › Parcours d'achat). Totaux de la mesure d'audience, sans donnée personnelle.</p>
