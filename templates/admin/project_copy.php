<?php use DeepFind\ConfigTransfer; ?>
<p><a href="<?= e(url('/admin/projects')) ?>">← Projets</a></p>
<p class="muted">Copiez la configuration d'un projet vers un autre, par exemple de « Test » vers « Production » une fois les réglages validés.
  <strong>Jamais copiés</strong> : statistiques, journaux, clics, historique des imports, clés publiques, produits indexés.
  Une <strong>sauvegarde de la cible</strong> est faite juste avant : vous pouvez revenir en arrière depuis « Projets ».</p>

<form method="get" action="<?= e(url('/admin/projects/copy')) ?>" class="card form">
  <input type="hidden" name="preview" value="1">
  <div class="grid2">
    <label>Copier depuis
      <select name="from"><?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $p['id'] === $from ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
    </label>
    <label>Vers
      <select name="to"><?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $p['id'] === $to ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
    </label>
  </div>
  <p><strong>Éléments à copier</strong> (ils remplacent ceux de la cible)</p>
  <div class="copy-sections">
  <?php foreach (ConfigTransfer::SECTIONS as $k => $label): ?>
    <label class="toggle toggle-sm"><input type="checkbox" role="switch" name="sections[]" value="<?= e($k) ?>" <?= in_array($k, $sections, true) ? 'checked' : '' ?>><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text"><?= e($label) ?></span></label>
  <?php endforeach; ?>
    <label class="toggle toggle-sm"><input type="checkbox" role="switch" name="include_urls" value="1" <?= $includeUrls ? 'checked' : '' ?>><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">Inclure aussi les URL et en-têtes d'authentification des flux <small class="muted">(sinon la cible garde ses propres URL)</small></span></label>
  </div>
  <button class="btn">Voir ce qui va changer</button>
</form>

<?php if ($summary !== null): $pf = $projects[$from]; $pt = $projects[$to]; ?>
  <h2>Changements dans « <?= e($pt['name']) ?> »</h2>
  <table class="table">
    <thead><tr><th>Élément</th><th>Depuis « <?= e($pf['name']) ?> »</th><th>Actuellement dans la cible</th><th>Détail</th></tr></thead>
    <tbody>
    <?php foreach ($summary as $k => $s): ?>
      <tr><td><strong><?= e(ConfigTransfer::SECTIONS[$k]) ?></strong></td><td><?= e((string) $s['from']) ?></td><td><?= e((string) $s['to']) ?></td>
        <td class="small"><?= $s['detail'] ? implode('<br>', array_map('e', array_slice($s['detail'], 0, 25))) . (count($s['detail']) > 25 ? '<br>…' : '') : '<span class="muted">remplacé à l\'identique de la source</span>' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <form method="post" action="<?= e(url('/admin/projects/copy')) ?>" class="actions">
    <?= csrf_field() ?>
    <input type="hidden" name="from" value="<?= (int) $from ?>"><input type="hidden" name="to" value="<?= (int) $to ?>">
    <?php foreach ($chosen as $c): ?><input type="hidden" name="sections[]" value="<?= e($c) ?>"><?php endforeach; ?>
    <?php if ($includeUrls): ?><input type="hidden" name="include_urls" value="1"><?php endif; ?>
    <button class="btn" data-confirm="Copier la configuration de « <?= e($pf['name']) ?> » vers « <?= e($pt['name']) ?> » ? Une sauvegarde de « <?= e($pt['name']) ?> » est faite avant.">Confirmer la copie vers « <?= e($pt['name']) ?> »</button>
  </form>
<?php elseif (isset($_GET['preview'])): ?>
  <div class="flash flash-warn">Choisissez deux projets différents et au moins un élément.</div>
<?php endif; ?>
