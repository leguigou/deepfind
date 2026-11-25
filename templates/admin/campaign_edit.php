<?php
/** Recommandations › Campagnes › fiche : produits, période, objectif ; tableau de bord des résultats.
 *  @var array $c @var list<array> $items @var ?array $stats @var string $status */
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
<p><a href="<?= e(url('/admin/campaigns')) ?>" class="cr-back">‹ Retour aux campagnes</a></p>

<?php if ($stats): $t = $stats['total']; $pv = $stats['prev']; $per = $stats['period']; ?>
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
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/campaigns/save')) ?>" class="card form cp-form" data-campaign-form data-items="<?= e(\DeepFind\Text::json($items)) ?>"
      data-search-url="<?= e(url('/admin/custom-results/search')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
  <input type="hidden" name="items" value="" data-items-field>
  <h3><?= $c['id'] ? 'Réglages de la campagne' : 'Nouvelle campagne' ?></h3>
  <div class="grid2">
    <label>Nom <input name="name" maxlength="190" required value="<?= e($c['name']) ?>" placeholder="ex. Soldes d'été – palmes"></label>
    <label class="toggle cp-active"><input type="checkbox" role="switch" name="active" value="1"<?= $c['active'] ? ' checked' : '' ?>><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">Campagne active</span></label>
    <label>Début <small class="muted">(vide : date de création)</small><input type="date" name="starts_at" value="<?= e((string) $c['starts_at']) ?>"></label>
    <label>Fin <small class="muted">(vide : sans fin)</small><input type="date" name="ends_at" value="<?= e((string) $c['ends_at']) ?>"></label>
  </div>
  <label>Objectif <small class="muted">(facultatif)</small><textarea name="objective" rows="2" maxlength="500" placeholder="ex. écouler le stock de palmes 2025 avant l'arrivée de la nouvelle collection"><?= e($c['objective']) ?></textarea></label>
  <strong class="ca-block-title">Produits à pousser</strong>
  <div class="ca-picker">
    <input type="search" data-item-search placeholder="Rechercher un produit (nom, SKU, marque…)" aria-label="Rechercher un produit à ajouter" autocomplete="off">
    <div class="ca-results" data-item-results hidden></div>
  </div>
  <ol class="ca-items" data-items-list></ol>
  <details class="cp-bulk"><summary>Coller une liste de SKU</summary>
    <textarea rows="3" data-bulk placeholder="Un SKU par ligne, ou séparés par des virgules"></textarea>
    <button type="button" class="btn btn-light btn-sm" data-bulk-add>Ajouter ces SKU</button>
  </details>
  <div class="cr-foot">
    <?php if ($c['id']): ?><button class="btn btn-danger cr-delete-btn" form="cp-delete-form" data-confirm="Supprimer la campagne « <?= e($c['name']) ?> » ?"><?= admin_icon('trash') ?> Supprimer cette campagne</button><?php endif; ?>
    <a class="btn btn-light" href="<?= e(url('/admin/campaigns')) ?>">Annuler</a>
    <button class="btn">Enregistrer</button>
  </div>
</form>
<?php if ($c['id']): ?>
  <form method="post" action="<?= e(url('/admin/campaigns/delete')) ?>" id="cp-delete-form" hidden><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"></form>
<?php endif; ?>
<script src="<?= e(asset('/assets/campaigns.js')) ?>"></script>
