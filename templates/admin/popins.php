<?php
/** @var array $list @var array $stats @var int $days @var array $dayChoices @var string $lang */
use DeepFind\Rules\CustomResults;
use DeepFind\Rules\PageRules;
use DeepFind\Rules\Popins;

// résumé court (le détail est dans la fiche) : appareils · délai, puis fréquence · mémorisation après fermeture
$devices = ['all' => 'Tous appareils', 'desktop' => 'Ordinateur', 'mobile' => 'Mobile'];
$freq = static function (array $p): string {
    if ($p['repeat_mode'] === 'always') {
        return 'Toujours (chaque page)';
    }
    $out = $p['repeat_mode'] === 'page' ? 'Chaque page' : '1×/visite';
    return $out . ' · fermée : ' . ($p['remember_days'] > 0 ? $p['remember_days'] . ' j' : 'visite');
};
?>
<div class="page-head">
  <p class="muted br-intro">Langue <?= e(strtoupper($lang)) ?>. Une popin s'ouvre sur les <strong>pages du site</strong> choisies (toutes, la page d'accueil,
    ou d'après l'adresse comme les <a href="<?= e(url('/admin/page-rules')) ?>">règles selon la page</a>), après un délai.
    <strong>Une seule popin par page : la première de la liste qui correspond</strong> et que le visiteur n'a pas déjà fermée. Faites glisser les lignes pour changer l'ordre.</p>
  <a class="btn" href="<?= e(url('/admin/popins/edit')) ?>">+ Nouvelle popin</a>
</div>

<form method="get" class="form inline cr-period">
  <label>Statistiques sur <select name="days" data-autosubmit><?php foreach ($dayChoices as $d => $l): ?><option value="<?= $d ?>" <?= $d === $days ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <span class="muted small">Affichage = popin ouverte sur une page · Clic = clic sur un lien de la popin · CTR = clics / affichages · Fermeture = croix, touche Échap ou clic à côté</span>
</form>

<form method="post" action="<?= e(url('/admin/popins/reorder')) ?>" data-pr-order>
  <?= csrf_field() ?><input type="hidden" name="ids" value="">
</form>
<div class="table-scroll">
<table class="table cr-table pp-table">
  <thead><tr><th class="ly-grip" aria-label="Ordre"></th><th class="num">N°</th><th>Statut</th><th>Popin</th><th>Pages</th><th>Affichage</th>
    <th class="num">Affichages</th><th class="num">Clics</th><th class="num">CTR</th><th class="num" title="Fermetures">Ferm.</th><th></th></tr></thead>
  <tbody data-pr-list>
  <?php foreach ($list as $i => $p): $s = $stats[$p['id']] ?? ['views' => 0, 'clicks' => 0, 'closes' => 0, 'ctr' => null]; $per = CustomResults::period($p); ?>
    <tr draggable="true" data-row data-id="<?= (int) $p['id'] ?>" class="<?= $p['active'] && $per === 'current' ? '' : 'dim-soft' ?>">
      <td class="ly-grip"><span class="ly-handle" title="Faire glisser pour changer l'ordre de priorité"><?= admin_icon('grip-vertical') ?></span></td>
      <td class="num muted" data-rank><?= $i + 1 ?></td>
      <td>
        <form method="post" action="<?= e(url('/admin/popins/toggle')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <label class="toggle toggle-sm"><input type="checkbox" role="switch" data-autosubmit <?= $p['active'] ? 'checked' : '' ?> aria-label="Activer « <?= e($p['name']) ?> »"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
        </form>
      </td>
      <td class="bn-cell">
        <?php if ($p['mode'] === 'visual' && $p['image_desktop'] !== ''): ?><img class="bn-thumb" src="<?= e($p['image_desktop']) ?>" alt="" loading="lazy"><?php else: ?><span class="bn-code">HTML</span><?php endif; ?>
        <span><a href="<?= e(url('/admin/popins/edit', ['id' => $p['id']])) ?>"><strong><?= e($p['name']) ?></strong></a>
          <br><small class="muted nowrap"><?= $p['starts_at'] ? e(date('d/m/Y', (int) strtotime($p['starts_at'])) . ' → ' . date('d/m/Y', (int) strtotime((string) $p['ends_at']))) : 'En continu' ?></small>
          <?= $per === 'scheduled' ? '<span class="badge badge-info">programmée</span>' : ($per === 'expired' ? '<span class="badge badge-warn">terminée</span>' : '') ?></span>
      </td>
      <td>
        <?php if ($p['pages'] !== 'custom'): ?><span class="muted">Toutes les pages</span><?php else: ?>
          <div class="chips"><?php if ($p['home']): ?><span class="chip">Accueil</span><?php endif; ?><?php foreach ($p['urls'] as $u): ?><span class="chip"><span class="muted"><?= e(PageRules::URL_OPS[$u['match']] ?? $u['match']) ?></span> <?= e($u['text']) ?></span><?php endforeach; ?></div>
        <?php endif; ?>
        <?php if ($p['exclude_urls']): ?><small class="muted pp-except">sauf <?= e(implode(', ', array_column($p['exclude_urls'], 'text'))) ?></small><?php endif; ?>
      </td>
      <td class="nowrap" title="<?= e(Popins::DEVICES[$p['device']] . ' · ' . Popins::REPEATS[$p['repeat_mode']]) ?>"><small><?= e($devices[$p['device']]) ?> · <?= (int) $p['delay_seconds'] ?> s<br><?= e($freq($p)) ?></small></td>
      <td class="num"><?= fmt_n($s['views']) ?></td>
      <td class="num"><?= fmt_n($s['clicks']) ?></td>
      <td class="num"><?= $s['ctr'] === null ? '—' : fmt_n($s['ctr'], 1) . ' %' ?></td>
      <td class="num"><?= fmt_n($s['closes']) ?></td>
      <td class="actions">
        <a class="btn btn-sm btn-light" href="<?= e(url('/admin/popins/edit', ['id' => $p['id']])) ?>">Modifier</a>
        <form method="post" action="<?= e(url('/admin/popins/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button class="btn btn-sm btn-danger" data-confirm="Supprimer la popin « <?= e($p['name']) ?> » et ses statistiques ?">Supprimer</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$list): ?><tr><td colspan="11" class="muted">Aucune popin. Exemple : « Soldes d'hiver » sur la page d'accueil et les pages dont l'adresse contient <code>/nautisme/</code>, après 5 secondes, une fois par visite.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted small">Une modification est visible sur le site d'ici quelques minutes (configuration gardée en cache par les navigateurs). Pour tester : le favori « Tester DeepFind »
  (Paramètres › Intégration) affiche les popins à chaque page, sans les compter ni mémoriser leur fermeture.</p>
<script src="<?= e(asset('/assets/page-rules.js')) ?>"></script>
