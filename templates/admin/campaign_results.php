<?php
/** Recommandations › Campagnes › résultats d'une campagne (tableau de bord). @var array $c @var array $stats @var string $status */
$euro = static fn (int $cents) => fmt_n($cents / 100, 2) . ' €';
$delta = static function (int $now, int $before): string {
    if ($before === 0) {
        return $now > 0 ? '<span class="cp-up">nouveau</span>' : '<span class="muted">—</span>';
    }
    $pct = round(100 * ($now - $before) / $before);
    return '<span class="' . ($pct >= 0 ? 'cp-up' : 'cp-down') . '">' . ($pct >= 0 ? '+' : '') . $pct . ' %</span>';
};
$statusLabel = ['current' => 'en cours', 'upcoming' => 'à venir', 'ended' => 'terminée', 'off' => 'désactivée'];
?>
<p class="cp-nav"><a href="<?= e(url('/admin/campaigns')) ?>" class="cr-back">‹ Retour aux campagnes</a>
  <a class="btn btn-light btn-sm" href="<?= e(url('/admin/campaigns/edit', ['id' => $c['id']])) ?>"><?= admin_icon('pencil') ?> Modifier la campagne</a></p>
<?php if ($c['objective'] !== ''): ?><p><strong>Objectif</strong> : <?= e($c['objective']) ?></p><?php endif; ?>
<?php if ($c['highlight_search'] || $c['highlight_carousels']): $hlLive = \DeepFind\Rules\Campaigns::isLive($c); ?>
  <p class="cp-hl-status"><span class="badge <?= $hlLive ? 'badge-ok' : 'badge-warn' ?>"><?= $hlLive ? 'Mise en avant en cours' : ($c['active'] && $c['starts_at'] !== null && $c['starts_at'] > \DeepFind\Rules\Campaigns::today() ? 'Mise en avant à venir' : 'Mise en avant arrêtée') ?></span>
    <?= $c['highlight_search'] ? 'recherche ×' . e(rtrim(rtrim(number_format($c['search_boost'], 2, ',', ''), '0'), ',')) : '' ?><?= $c['highlight_search'] && $c['highlight_carousels'] ? ' · ' : '' ?><?= $c['highlight_carousels'] ? 'carrousels : ' . (int) $c['carousel_slots'] . ' place(s) en tête' : '' ?>
    <span class="muted small">(du <?= $c['starts_at'] ? e(date('d/m/Y', strtotime($c['starts_at']))) : 'début' ?> à 0 h <?= $c['ends_at'] ? 'au ' . e(date('d/m/Y', strtotime($c['ends_at']))) . ' à 23 h 59' : ', sans fin' ?>)</span></p>
<?php endif; ?>
<?php $t = $stats['total']; $pv = $stats['prev']; $per = $stats['period']; ?>
  <p class="muted">Campagne <?= e($statusLabel[$status]) ?> · résultats du <?= e(date('d/m/Y', strtotime($per['from']))) ?> au <?= e(date('d/m/Y', strtotime($per['to']))) ?>
    (<?= (int) $per['days'] ?> jour<?= $per['days'] > 1 ? 's' : '' ?>), comparés au <?= e(date('d/m/Y', strtotime($per['prev_from']))) ?> – <?= e(date('d/m/Y', strtotime($per['prev_to']))) ?>.</p>
  <div class="cards">
    <div class="card kpi"><div class="kpi-label">Chiffre d'affaires</div><div class="kpi-value"><?= $euro($t['revenue']) ?></div><div class="muted small"><?= $delta($t['revenue'], $pv['revenue']) ?> · avant : <?= $euro($pv['revenue']) ?></div></div>
    <div class="card kpi"><div class="kpi-label">Commandes</div><div class="kpi-value"><?= fmt_n($t['orders']) ?></div><div class="muted small"><?= $delta($t['orders'], $pv['orders']) ?></div></div>
    <div class="card kpi"><div class="kpi-label">Ajouts au panier</div><div class="kpi-value"><?= fmt_n($t['carts']) ?></div><div class="muted small"><?= $delta($t['carts'], $pv['carts']) ?></div></div>
    <div class="card kpi"><div class="kpi-label">Clics</div><div class="kpi-value"><?= fmt_n($t['clicks']) ?></div><div class="muted small"><?= $delta($t['clicks'], $pv['clicks']) ?> · recherche et carrousels</div></div>
    <div class="card kpi"><div class="kpi-label">Vus dans les carrousels</div><div class="kpi-value"><?= fmt_n($t['car_views']) ?></div><div class="muted small">affichages à l'écran</div></div>
  </div>

  <h2>Produits</h2>
  <div class="table-wrap">
  <table class="table cp-table">
    <thead><tr><th>Produit</th><th class="num">Vus (carrousels)</th><th class="num">Clics</th><th class="num" title="Position moyenne du produit quand il est cliqué dans la recherche">Position (recherche)</th>
      <th class="num">Paniers</th><th class="num">Commandes</th><th class="num">CA</th><th class="num">vs avant</th></tr></thead>
    <tbody>
    <?php foreach ($stats['rows'] as $r): ?>
      <tr>
        <td><?php if (!$r['found']): ?><strong><?= e($r['sku']) ?></strong> <span class="badge badge-ko">introuvable dans le catalogue</span>
          <?php else: ?><a href="<?= e(url('/admin/preview', ['q' => $r['sku']])) ?>"><?= e($r['name']) ?></a> <small class="muted"><?= e($r['sku']) ?></small>
            <?php if ($r['rule']): ?> <span class="badge badge-info" title="Produit ajouté par les règles de la campagne">règle</span><?php endif; ?>
            <?php if (!$r['in_stock']): ?> <span class="badge badge-ko">rupture</span><?php endif; ?><?php endif; ?></td>
        <td class="num"><?= fmt_n($r['car_views']) ?></td>
        <td class="num"><?= fmt_n($r['clicks']) ?></td>
        <td class="num"><?= $r['avg_pos'] !== null ? fmt_n($r['avg_pos'], 1) . ' <small class="muted">(' . fmt_n($r['search_clicks']) . ')</small>' : '—' ?></td>
        <td class="num"><?= fmt_n($r['carts']) ?></td>
        <td class="num"><?= fmt_n($r['orders']) ?></td>
        <td class="num"><?= $euro($r['revenue']) ?></td>
        <td class="num"><?= $delta($r['revenue'], $r['prev']['revenue']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($stats['rule']['hidden'] > 0): ?><p class="muted small"><?= fmt_n($stats['rule']['hidden']) ?> autre(s) produit(s) des règles, moins actifs, ne sont pas affichés ; ils comptent dans les totaux.</p><?php endif; ?>

  <h2>Où les ventes ont été faites</h2>
  <div class="table-wrap">
  <table class="table table-compact">
    <thead><tr><th>Emplacement (dernier clic sur le produit dans la journée)</th><th class="num">Clics</th><th class="num">Paniers</th><th class="num">Commandes</th><th class="num">CA</th></tr></thead>
    <tbody>
    <?php foreach ($stats['placements'] as $pl): ?>
      <tr><td><?= e($pl['label']) ?></td><td class="num"><?= fmt_n($pl['clicks']) ?></td><td class="num"><?= fmt_n($pl['carts']) ?></td>
        <td class="num"><?= fmt_n($pl['orders']) ?></td><td class="num"><?= $euro($pl['revenue']) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($stats['placements'] === []): ?><tr><td colspan="5" class="muted">Pas encore de clic ni de vente sur la période.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small">« Sans clic DeepFind » : produit mis au panier ou acheté sans avoir été cliqué dans la recherche ou un carrousel le même jour (navigation du site, lien externe…).</p>
