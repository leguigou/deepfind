<?php
/** @var array $list @var array $stats @var int $days @var array $dayChoices @var bool $logging @var string $lang */
use DeepFind\Rules\CustomResults;
?>
<div class="page-head">
  <p class="muted br-intro">Langue <?= e(strtoupper($lang)) ?>. Une bannière s'affiche <strong>en haut des résultats</strong> du widget pour certains termes de recherche,
    ou <strong>par défaut</strong> quand aucune autre bannière ne correspond. Une seule bannière par recherche : la plus récente qui correspond.</p>
  <a class="btn" href="<?= e(url('/admin/banners/edit')) ?>">+ Nouvelle bannière</a>
</div>

<form method="get" class="form inline cr-period">
  <label>Statistiques sur <select name="days" data-autosubmit><?php foreach ($dayChoices as $d => $l): ?><option value="<?= $d ?>" <?= $d === $days ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <span class="muted small">Affichage = une recherche du site où la bannière est montrée · Clic = clic sur la bannière · CTR = clics / affichages</span>
</form>
<?php if (!$logging): ?><div class="flash flash-warn">Le journal des recherches est désactivé (Paramètres) : les affichages ne sont pas comptés.</div><?php endif; ?>

<div class="table-scroll">
<table class="table cr-table">
  <thead><tr><th>Statut</th><th>Bannière</th><th>Termes de recherche</th><th>Durée</th><th class="num">Affichages</th><th class="num">Clics</th><th class="num">CTR</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($list as $b): $s = $stats[$b['id']] ?? ['impressions' => 0, 'clicks' => 0, 'ctr' => null]; $per = CustomResults::period($b); ?>
    <tr class="<?= $b['active'] && $per === 'current' ? '' : 'dim-soft' ?>">
      <td>
        <form method="post" action="<?= e(url('/admin/banners/toggle')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
          <label class="toggle toggle-sm"><input type="checkbox" role="switch" data-autosubmit <?= $b['active'] ? 'checked' : '' ?> aria-label="Activer « <?= e($b['name']) ?> »"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
        </form>
      </td>
      <td class="bn-cell">
        <?php if ($b['mode'] === 'visual' && $b['image_desktop'] !== ''): ?><img class="bn-thumb" src="<?= e($b['image_desktop']) ?>" alt="" loading="lazy"><?php else: ?><span class="bn-code">HTML</span><?php endif; ?>
        <span><a href="<?= e(url('/admin/banners/edit', ['id' => $b['id']])) ?>"><strong><?= e($b['name']) ?></strong></a>
          <?= $b['is_default'] ? '<br><span class="badge badge-info">par défaut</span>' : '' ?></span>
      </td>
      <td><?php if ($b['terms']): ?><div class="chips"><?php foreach ($b['terms'] as $t): ?><span class="chip" title="<?= $t['match'] === 'broad' ? 'Correspondance large' : 'Correspondance exacte' ?>"><span class="chip-ico"><?= $t['match'] === 'broad' ? '≈' : '=' ?></span><?= e($t['term']) ?></span><?php endforeach; ?></div>
        <?php else: ?><span class="muted">Toutes les recherches sans autre bannière</span><?php endif; ?></td>
      <td class="nowrap"><?= $b['starts_at'] ? e(date('d/m/Y', (int) strtotime($b['starts_at'])) . ' → ' . date('d/m/Y', (int) strtotime((string) $b['ends_at']))) : 'En continu' ?>
        <?= $per === 'scheduled' ? '<br><span class="badge badge-info">programmée</span>' : ($per === 'expired' ? '<br><span class="badge badge-warn">terminée</span>' : '') ?></td>
      <td class="num"><?= fmt_n($s['impressions']) ?></td>
      <td class="num"><?= fmt_n($s['clicks']) ?></td>
      <td class="num"><?= $s['ctr'] === null ? '—' : fmt_n($s['ctr'], 1) . ' %' ?></td>
      <td class="actions">
        <a class="btn btn-sm btn-light" href="<?= e(url('/admin/banners/edit', ['id' => $b['id']])) ?>">Modifier</a>
        <form method="post" action="<?= e(url('/admin/banners/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
          <button class="btn btn-sm btn-danger" data-confirm="Supprimer la bannière « <?= e($b['name']) ?> » ?">Supprimer</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$list): ?><tr><td colspan="8" class="muted">Aucune bannière. Exemple : une bannière « Soldes palmes -30 % » pour les recherches contenant « palme ».</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
