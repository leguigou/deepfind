<?php
/** @var array $rule @var array $fields */
use DeepFind\Rules\PageRules;
?>
<p><a href="<?= e(url('/admin/page-rules')) ?>" class="cr-back">‹ Retour aux règles</a></p>

<form method="post" action="<?= e(url('/admin/page-rules/save')) ?>" class="form pr-form" data-pr-form
      data-rule="<?= e(\DeepFind\Text::json($rule)) ?>" data-fields="<?= e(\DeepFind\Text::json($fields)) ?>"
      data-url-ops="<?= e(\DeepFind\Text::json(PageRules::URL_OPS)) ?>" data-values-url="<?= e(url('/admin/boost-rules/values')) ?>">
  <?= csrf_field() ?><input type="hidden" name="payload" value="">

  <div class="cr-head">
    <input class="cr-name" data-f="name" maxlength="190" placeholder="Écrire le nom interne (ex. Nautisme)" aria-label="Nom interne" required>
  </div>

  <div class="card cr-card">
    <div class="cr-row">
      <strong>Statut</strong>
      <label class="toggle"><input type="checkbox" role="switch" data-f="active" aria-label="Règle active"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
    </div>

    <div class="cr-section">
      <strong>1. Pages concernées</strong>
      <p class="muted small">Adresse de la page où se trouve le visiteur, sans le nom de domaine (ex. <code>/fr/nautisme/kayak.html</code>). Majuscules ignorées.
        La règle s'applique si <strong>au moins une</strong> de ces conditions est remplie.</p>
      <div class="pr-urls" data-urls></div>
      <button type="button" class="btn btn-light btn-sm" data-add-url>+ Ajouter une adresse</button>
    </div>

    <div class="cr-section">
      <strong>2. Action sur ces pages</strong>
      <?php foreach (PageRules::ACTIONS as $v => $label): ?>
        <label class="check"><input type="radio" name="pr_action" value="<?= e($v) ?>"><span><?= e($label) ?></span></label>
      <?php endforeach; ?>
      <label class="pr-label" data-label-row>Texte du bandeau dans le widget
        <input data-f="label" maxlength="100" placeholder="ex. Nautisme (vide : nom de la règle)">
        <small class="muted">Affiché sous la forme « Résultats limités à <strong>Nautisme ✕</strong> » ; un clic sur ✕ élargit la recherche à tout le catalogue.</small></label>
    </div>

    <div class="cr-section" data-rule-root>
      <strong>3. Produits concernés</strong>
      <label class="check"><input type="radio" name="pr_match" value="all"><span>Les produits remplissent <strong>toutes</strong> les conditions</span></label>
      <label class="check"><input type="radio" name="pr_match" value="any"><span>Les produits remplissent <strong>au moins une</strong> condition</span></label>
      <div class="br-conds" data-conds></div>
      <button type="button" class="btn btn-light br-add-cond" data-add-cond>+ Ajouter une condition</button>
    </div>
  </div>

  <div class="cr-foot">
    <a class="btn btn-light" href="<?= e(url('/admin/page-rules')) ?>">Annuler</a>
    <button class="btn">Enregistrer</button>
  </div>
</form>
<?php if ($rule['id']): ?>
  <form method="post" action="<?= e(url('/admin/page-rules/delete')) ?>" class="cr-delete"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
    <button class="btn btn-sm btn-danger" data-confirm="Supprimer la règle « <?= e($rule['name']) ?> » ?">Supprimer cette règle</button></form>
<?php endif; ?>
<script src="<?= e(asset('/assets/conditions.js')) ?>"></script>
<script src="<?= e(asset('/assets/page-rules.js')) ?>"></script>
