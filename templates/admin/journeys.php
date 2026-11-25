<?php
/** Analyse › Parcours : liste des parcours conservés.
 *  @var bool $enabled @var string $filter @var string $q @var string $day @var list<string> $days @var int $page @var list<array> $rows @var int $total */
use DeepFind\Logging\Audience;
use DeepFind\Logging\Journeys;

$perPage = 50;
$euro = static fn (int $cents) => fmt_n($cents / 100, 2) . ' €';
$query = array_filter(['f' => $filter !== 'all' ? $filter : '', 'q' => $q, 'day' => $day, 'page' => $page > 1 ? $page : ''], static fn ($v) => $v !== '');
$back = '/admin/journeys' . ($query ? '?' . http_build_query($query) : '');
?>
<p class="muted">Chronologie de la journée des visiteurs qui ont <strong>cherché</strong> dans la barre de recherche ou <strong>cliqué sur un carrousel</strong>.
  Aucun cookie, rien n'est stocké sur l'appareil : un visiteur est reconnu pendant une journée de mesure seulement, par une empreinte anonyme (adresse IP et
  navigateur mélangés à une clé détruite chaque jour). Les recherches sont masquées (e-mails, téléphones, numéros de carte). Parcours supprimés
  <?= (int) Journeys::RETENTION_HOURS ?> h après leur dernier événement.</p>

<?php if (!$enabled): ?>
  <div class="flash flash-warn">Les parcours sont désactivés : rien n'est enregistré. Activez-les dans <a href="<?= e(url('/admin/audience')) ?>#aud-settings">Analyse › Audience › Réglages</a>
    après validation par le DPO.</div>
<?php endif; ?>

<form method="get" action="<?= e(url('/admin/journeys')) ?>" class="card form jr-filters">
  <div class="jr-chips" role="group" aria-label="Filtrer les parcours">
    <?php foreach (Journeys::FILTERS as $k => $label): ?>
      <a class="btn btn-sm <?= $filter === $k ? '' : 'btn-light' ?>" href="<?= e(url('/admin/journeys', array_filter(['f' => $k !== 'all' ? $k : '', 'q' => $q, 'day' => $day], static fn ($v) => $v !== ''))) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($filter !== 'all'): ?><input type="hidden" name="f" value="<?= e($filter) ?>"><?php endif; ?>
  <label>Journée
    <select name="day"><option value="">Toutes (conservées)</option>
      <?php foreach ($days as $d): ?><option value="<?= e($d) ?>"<?= $d === $day ? ' selected' : '' ?>><?= e(date('d/m/Y', strtotime($d))) ?></option><?php endforeach; ?>
    </select></label>
  <label>Contient <input type="search" name="q" value="<?= e($q) ?>" placeholder="recherche, produit, page…"></label>
  <button class="btn btn-sm">Filtrer</button>
</form>

<p><strong><?= fmt_n($total) ?></strong> parcours</p>
<div class="table-wrap">
<table class="table jr-table">
  <thead><tr><th>Visiteur</th><th>Début</th><th>Durée</th><th>Appareil</th><th>Recherches</th><th class="num">Pages</th><th class="num">Clics</th><th class="num">Panier</th><th class="num">Commande</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
    $dur = max(0, strtotime($r['last_at']) - strtotime($r['started_at']));
    $link = url('/admin/journeys/view', ['id' => (int) $r['id'], 'back' => $back]); ?>
    <tr>
      <td><a href="<?= e($link) ?>"><?= e($r['label']) ?></a>
        <?php if ($r['trigger_type'] === 'carousel'): ?><span class="badge">carrousel</span><?php endif; ?>
        <?php if ((int) $r['zero_searches'] > 0): ?><span class="badge badge-warn">sans résultat</span><?php endif; ?></td>
      <td><?= e(date('d/m H:i', strtotime($r['started_at']))) ?><?php if ($r['entry_path'] !== ''): ?><br><small class="muted"><?= e(mb_strimwidth($r['entry_path'], 0, 50, '…', 'UTF-8')) ?></small><?php endif; ?></td>
      <td><?= $dur < 60 ? $dur . ' s' : ($dur < 3600 ? intdiv($dur, 60) . ' min' : intdiv($dur, 3600) . ' h ' . sprintf('%02d', intdiv($dur % 3600, 60))) ?></td>
      <td><?= e(Audience::DEVICES[$r['device']] ?? $r['device']) ?></td>
      <td><?= $r['queries'] ? e(implode(' · ', $r['queries'])) : '<span class="muted">—</span>' ?><?= (int) $r['searches'] > count($r['queries']) ? ' <small class="muted">(' . (int) $r['searches'] . ')</small>' : '' ?></td>
      <td class="num"><?= fmt_n((int) $r['pageviews']) ?></td>
      <td class="num"><?= fmt_n((int) $r['clicks'] + (int) $r['carousel_clicks']) ?></td>
      <td class="num"><?= (int) $r['carts'] > 0 ? fmt_n((int) $r['carts']) . '<br><small class="muted">' . $euro((int) $r['cart_value']) . '</small>' : '—' ?></td>
      <td class="num"><?= (int) $r['orders'] > 0 ? '<strong>' . $euro((int) $r['revenue']) . '</strong>' : ((int) $r['carts'] > 0 ? '<span class="badge badge-ko">abandon</span>' : '—') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="9" class="muted"><?= $enabled ? 'Aucun parcours pour ces critères.' : 'Aucun parcours enregistré.' ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php $pages = (int) ceil($total / $perPage); if ($pages > 1): ?>
  <nav class="pager" aria-label="Pages">
    <?php if ($page > 1): ?><a class="btn btn-sm btn-light" href="<?= e(url('/admin/journeys', ['page' => $page - 1] + $query)) ?>">‹ Précédents</a><?php endif; ?>
    <span class="muted small">Page <?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn btn-sm btn-light" href="<?= e(url('/admin/journeys', ['page' => $page + 1] + $query)) ?>">Suivants ›</a><?php endif; ?>
  </nav>
<?php endif; ?>
