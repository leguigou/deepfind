<?php
/** @var array $cr @var array $fields @var array $products @var array $ruleText @var ?array $stats */
?>
<p><a href="<?= e(url('/admin/custom-results')) ?>" class="cr-back">‹ Retour aux résultats personnalisés</a></p>

<form method="post" action="<?= e(url('/admin/custom-results/save')) ?>" class="form cr-form" data-cr-form
      data-cr="<?= e(\DeepFind\Text::json($cr)) ?>" data-products="<?= e(\DeepFind\Text::json((object) $products)) ?>"
      data-fields="<?= e(\DeepFind\Text::json($fields)) ?>" data-values-url="<?= e(url('/admin/boost-rules/values')) ?>"
      data-search-url="<?= e(url('/admin/custom-results/search')) ?>">
  <?= csrf_field() ?><input type="hidden" name="payload" value="">

  <div class="cr-head">
    <input class="cr-name" data-f="name" maxlength="190" placeholder="Écrire le nom interne" aria-label="Nom interne" required>
    <?php if ($stats): ?>
      <div class="cr-kpis" title="30 derniers jours">
        <div><span class="kpi-label">Impressions</span><span class="kpi-value"><?= fmt_n($stats['impressions']) ?></span></div>
        <div><span class="kpi-label">Clics</span><span class="kpi-value"><?= fmt_n($stats['clicks']) ?></span></div>
        <div><span class="kpi-label">CTR</span><span class="kpi-value"><?= $stats['ctr'] === null ? '—' : fmt_n($stats['ctr'], 1) . ' %' ?></span></div>
        <small class="muted">30 derniers jours</small>
      </div>
    <?php endif; ?>
  </div>

  <div class="card cr-card">
    <div class="cr-row">
      <strong>Statut</strong>
      <label class="toggle"><input type="checkbox" role="switch" data-f="active" aria-label="Statut actif"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
    </div>

    <div class="cr-section">
      <strong>Durée</strong>
      <label class="check"><input type="radio" name="cr_duration" value="always"><span>Appliquer en continu à partir d'aujourd'hui</span></label>
      <label class="check"><input type="radio" name="cr_duration" value="period"><span>Définir une période</span></label>
      <div class="cr-range-wrap">
        <button type="button" class="cr-range" data-range aria-haspopup="dialog">
          <span data-range-start class="muted">Date de début</span><span class="cr-range-sep" aria-hidden="true">▸</span><span data-range-end class="muted">Date de fin</span>
          <span class="cr-cal-ico" aria-hidden="true">📅</span>
        </button>
        <div class="cr-cal" data-cal hidden></div>
      </div>
    </div>

    <div class="cr-section">
      <strong>Afficher les résultats</strong>
      <label class="check"><input type="radio" name="cr_mode" value="only"><span>Afficher <strong>uniquement</strong> les résultats sélectionnés</span></label>
      <label class="check"><input type="radio" name="cr_mode" value="first"><span>Afficher <strong>d'abord</strong> les résultats sélectionnés, puis les résultats naturels</span></label>
    </div>

    <div class="cr-section">
      <strong>Termes de recherche</strong>
      <div class="br-term-input">
        <select data-f="term_match" aria-label="Type de correspondance">
          <option value="exact">= Correspondance exacte</option>
          <option value="broad">≈ Correspondance large</option>
        </select>
        <input data-f="term" placeholder="Saisir un terme de recherche" aria-label="Terme de recherche">
        <button type="button" class="btn" data-add-term>Ajouter un terme</button>
      </div>
      <small class="muted">Exacte : la recherche est exactement ce terme. Large : la recherche contient tous les mots du terme.</small>
      <div class="chips" data-terms></div>
    </div>

    <div class="grid2 cr-boxes">
      <?php foreach (['include' => 'Résultats inclus', 'exclude' => 'Résultats exclus'] as $kind => $boxTitle): ?>
        <section class="cr-box" data-box="<?= $kind ?>">
          <div class="cr-box-head">
            <h3><?= e($boxTitle) ?></h3>
            <div class="cr-menu">
              <button type="button" class="btn btn-sm btn-light" data-menu-toggle aria-haspopup="menu">+ Ajouter des résultats</button>
              <div class="cr-menu-list" role="menu" hidden>
                <button type="button" role="menuitem" data-add-items>Éléments individuels</button>
                <button type="button" role="menuitem" data-add-rule>Règles</button>
              </div>
            </div>
          </div>
          <ol class="cr-items" data-items></ol>
          <ul class="cr-rules" data-rules></ul>
          <p class="muted small" data-empty><?= $kind === 'include' ? 'Aucun produit mis en avant.' : 'Aucun produit exclu.' ?></p>
        </section>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="cr-foot">
    <a class="btn btn-light" href="<?= e(url('/admin/custom-results')) ?>">Annuler</a>
    <button class="btn">Enregistrer</button>
  </div>
</form>
<?php if ($cr['id']): ?>
  <form method="post" action="<?= e(url('/admin/custom-results/delete')) ?>" class="cr-delete"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $cr['id'] ?>">
    <button class="btn btn-sm btn-danger" data-confirm="Supprimer le résultat personnalisé « <?= e($cr['name']) ?> » ?">Supprimer ce résultat</button></form>
<?php endif; ?>

<dialog id="cr-items-dialog" class="modal" aria-labelledby="cr-items-title">
  <div class="modal-head"><div class="modal-title"><h2 id="cr-items-title">Ajouter des produits</h2></div>
    <button type="button" class="icon-btn modal-close" data-dialog-close aria-label="Fermer">✕</button></div>
  <div class="modal-body">
    <input type="search" class="cr-search" data-item-search placeholder="Rechercher un produit (nom, marque, SKU…)" aria-label="Rechercher un produit">
    <div class="cr-results" data-item-results></div>
  </div>
  <div class="modal-foot">
    <button type="button" class="btn btn-light" data-dialog-close>Fermer</button>
    <button type="button" class="btn" data-items-confirm disabled>Ajouter 0 élément</button>
  </div>
</dialog>

<dialog id="cr-rule-dialog" class="modal" aria-labelledby="cr-rule-title">
  <div class="modal-head"><div class="modal-title"><h2 id="cr-rule-title">Ajouter une règle</h2></div>
    <button type="button" class="icon-btn modal-close" data-dialog-close aria-label="Fermer">✕</button></div>
  <div class="modal-body form" data-rule-root>
    <p class="muted small">Les produits qui remplissent ces conditions sont ajoutés à la liste.</p>
    <label class="check"><input type="radio" name="cr_rule_match" value="all" checked><span>Les produits remplissent <strong>toutes</strong> les conditions</span></label>
    <label class="check"><input type="radio" name="cr_rule_match" value="any"><span>Les produits remplissent <strong>au moins une</strong> condition</span></label>
    <div class="br-conds" data-rule-conds></div>
    <button type="button" class="btn btn-light br-add-cond" data-rule-add-cond>+ Ajouter une condition</button>
  </div>
  <div class="modal-foot">
    <button type="button" class="btn btn-light" data-dialog-close>Annuler</button>
    <button type="button" class="btn" data-rule-confirm>Enregistrer la règle</button>
  </div>
</dialog>
<script src="<?= e(asset('/assets/conditions.js')) ?>"></script>
<script src="<?= e(asset('/assets/custom-results.js')) ?>"></script>
