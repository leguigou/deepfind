<?php
/** @var array $list @var string $lang */
use DeepFind\Rules\PageRules;

$actionLabels = ['prefilter' => 'Filtre pré-coché', 'only' => 'Uniquement ces produits', 'exclude' => 'Produits exclus'];
$condText = static function (array $rule) {
    $n = count($rule['conditions']);
    return $n . ' condition' . ($n > 1 ? 's' : '') . ($n > 1 ? ($rule['match_all'] ? ' (toutes)' : ' (au moins une)') : '');
};
?>
<div class="page-head">
  <p class="muted br-intro">Langue <?= e(strtoupper($lang)) ?>. Selon la <strong>page du site</strong> où se trouve le visiteur (son adresse), sans mot-clé :
    n'afficher que certains produits, les <strong>pré-sélectionner</strong> (le visiteur peut retirer le filtre) ou en <strong>exclure</strong>.
    Ex. sur les pages dont l'adresse contient <code>/nautisme/</code>, ne montrer que la catégorie Nautisme.
    <strong>Seule la première règle active de la liste qui correspond à la page s'applique</strong> : faites glisser les lignes pour changer l'ordre.</p>
  <a class="btn" href="<?= e(url('/admin/page-rules/edit')) ?>">+ Nouvelle règle</a>
</div>

<form method="post" action="<?= e(url('/admin/page-rules/reorder')) ?>" data-pr-order>
  <?= csrf_field() ?><input type="hidden" name="ids" value="">
</form>
<div class="table-scroll">
<table class="table pr-table">
  <thead><tr><th class="ly-grip" aria-label="Ordre"></th><th class="num">N°</th><th>Statut</th><th>Nom</th><th>Pages (adresse)</th><th>Action</th><th>Produits</th><th></th></tr></thead>
  <tbody data-pr-list>
  <?php foreach ($list as $i => $r): ?>
    <tr draggable="true" data-row data-id="<?= (int) $r['id'] ?>" class="<?= $r['active'] ? '' : 'dim-soft' ?>">
      <td class="ly-grip"><span class="ly-handle" title="Faire glisser pour changer l'ordre d'application"><?= admin_icon('grip-vertical') ?></span></td>
      <td class="num muted" data-rank><?= $i + 1 ?></td>
      <td>
        <form method="post" action="<?= e(url('/admin/page-rules/toggle')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <label class="toggle toggle-sm"><input type="checkbox" role="switch" data-autosubmit <?= $r['active'] ? 'checked' : '' ?> aria-label="Activer « <?= e($r['name']) ?> »"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
        </form>
      </td>
      <td><a href="<?= e(url('/admin/page-rules/edit', ['id' => $r['id']])) ?>"><strong><?= e($r['name']) ?></strong></a></td>
      <td><div class="chips"><?php foreach ($r['urls'] as $u): ?><span class="chip"><span class="muted"><?= e(PageRules::URL_OPS[$u['match']] ?? $u['match']) ?></span> <?= e($u['text']) ?></span><?php endforeach; ?></div></td>
      <td><?= e($actionLabels[$r['action']] ?? $r['action']) ?><?php if ($r['action'] === 'prefilter'): ?><br><small class="muted">bandeau « <?= e($r['label'] !== '' ? $r['label'] : $r['name']) ?> ✕ »</small><?php endif; ?></td>
      <td><small><?= e($condText($r)) ?></small></td>
      <td class="actions">
        <a class="btn btn-sm btn-light" href="<?= e(url('/admin/page-rules/edit', ['id' => $r['id']])) ?>">Modifier</a>
        <form method="post" action="<?= e(url('/admin/page-rules/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button class="btn btn-sm btn-danger" data-confirm="Supprimer la règle « <?= e($r['name']) ?> » ?">Supprimer</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$list): ?><tr><td colspan="8" class="muted">Aucune règle. Exemple : « Nautisme » — pages dont l'adresse contient <code>/nautisme/</code> → filtre pré-coché sur la catégorie Nautisme.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted small">Pour vérifier une règle avant de l'activer sur le site : <a href="<?= e(url('/admin/preview')) ?>">Aperçu interne</a>, champ « Page du site ».</p>
<script src="<?= e(asset('/assets/page-rules.js')) ?>"></script>
