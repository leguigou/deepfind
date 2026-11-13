<?php
/** @var array $list @var array $stats @var int $days @var array $dayChoices @var bool $logging @var string $lang */
use DeepFind\Rules\CustomResults;

$fmtPeriod = static function (array $c): string {
    if (!$c['starts_at'] && !$c['ends_at']) {
        return 'En continu';
    }
    return date('d/m/Y', (int) strtotime((string) $c['starts_at'])) . ' → ' . date('d/m/Y', (int) strtotime((string) $c['ends_at']));
};
?>
<div class="page-head">
  <p class="muted br-intro">Langue <?= e(strtoupper($lang)) ?>. Pour certains termes de recherche, mettez des produits <strong>en premier</strong>
    (ou affichez <strong>uniquement</strong> ces produits) et <strong>excluez</strong>-en d'autres, en continu ou sur une période.
    Les produits se choisissent un par un ou par règle (marque, catégorie, attribut, prix…). Effet immédiat, en tri « pertinence ».</p>
  <a class="btn" href="<?= e(url('/admin/custom-results/edit')) ?>">+ Nouveau résultat</a>
</div>

<form method="get" class="form inline cr-period">
  <label>Statistiques sur <select name="days" data-autosubmit><?php foreach ($dayChoices as $d => $l): ?><option value="<?= $d ?>" <?= $d === $days ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <span class="muted small">Impression = une recherche du site où le résultat s'est appliqué · Clic = clic sur un de ses produits · CTR = clics / impressions</span>
</form>
<?php if (!$logging): ?><div class="flash flash-warn">Le journal des recherches est désactivé (Paramètres) : les impressions ne sont pas comptées.</div><?php endif; ?>

<div class="table-scroll">
<table class="table cr-table">
  <thead><tr><th>Statut</th><th>Nom</th><th>Termes de recherche</th><th>Affichage</th><th>Durée</th><th class="num">Impressions</th><th class="num">Clics</th><th class="num">CTR</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($list as $c): $s = $stats[$c['id']] ?? ['impressions' => 0, 'clicks' => 0, 'ctr' => null]; $per = CustomResults::period($c); ?>
    <tr class="<?= $c['active'] && $per === 'current' ? '' : 'dim-soft' ?>">
      <td>
        <form method="post" action="<?= e(url('/admin/custom-results/toggle')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <label class="toggle toggle-sm"><input type="checkbox" role="switch" data-autosubmit <?= $c['active'] ? 'checked' : '' ?> aria-label="Activer « <?= e($c['name']) ?> »"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
        </form>
      </td>
      <td><a href="<?= e(url('/admin/custom-results/edit', ['id' => $c['id']])) ?>"><strong><?= e($c['name']) ?></strong></a>
        <br><small class="muted"><?= count($c['include_items']) ?> produit(s) et <?= count($c['include_rules']) ?> règle(s) inclus · <?= count($c['exclude_items']) + count($c['exclude_rules']) ?> exclusion(s)</small></td>
      <td><div class="chips"><?php foreach ($c['terms'] as $t): ?><span class="chip" title="<?= $t['match'] === 'broad' ? 'Correspondance large' : 'Correspondance exacte' ?>"><span class="chip-ico"><?= $t['match'] === 'broad' ? '≈' : '=' ?></span><?= e($t['term']) ?></span><?php endforeach; ?></div></td>
      <td><?= $c['mode'] === 'only' ? 'Uniquement les sélectionnés' : 'Sélectionnés en premier' ?></td>
      <td class="nowrap"><?= e($fmtPeriod($c)) ?><?= $per === 'scheduled' ? '<br><span class="badge badge-info">programmé</span>' : ($per === 'expired' ? '<br><span class="badge badge-warn">terminé</span>' : '') ?></td>
      <td class="num"><?= fmt_n($s['impressions']) ?></td>
      <td class="num"><?= fmt_n($s['clicks']) ?></td>
      <td class="num"><?= $s['ctr'] === null ? '—' : fmt_n($s['ctr'], 1) . ' %' ?></td>
      <td class="actions">
        <a class="btn btn-sm btn-light" href="<?= e(url('/admin/custom-results/edit', ['id' => $c['id']])) ?>">Modifier</a>
        <form method="post" action="<?= e(url('/admin/custom-results/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <button class="btn btn-sm btn-danger" data-confirm="Supprimer le résultat personnalisé « <?= e($c['name']) ?> » ?">Supprimer</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$list): ?><tr><td colspan="9" class="muted">Aucun résultat personnalisé. Exemple : pour « palme », afficher d'abord 3 palmes choisies, puis les résultats habituels.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<details class="card cr-import">
  <summary><strong>Importer un fichier CSV</strong> <span class="muted">(anciennes règles de résultats)</span></summary>
  <form method="post" action="<?= e(url('/admin/custom-results/import')) ?>" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?>
    <p class="muted">Colonnes : <code>requete;sku;action;position_ou_poids;debut;fin</code> avec action = pin (position), boost (en tête) ou hide (exclure).
      Chaque requête (et période) devient un résultat personnalisé « Recherche « … » » en correspondance exacte. Exemple : <code>samples/boosts.csv</code>.</p>
    <label>Fichier <input type="file" name="csv" accept=".csv,text/csv" required></label>
    <label class="check"><input type="checkbox" name="replace" value="1"> Remplacer tous les résultats personnalisés de cette langue</label>
    <button class="btn btn-light">Importer</button>
  </form>
</details>
