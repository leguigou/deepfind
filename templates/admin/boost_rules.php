<?php
/** @var array $rules @var array $fields @var string $lang */
use DeepFind\Rules\BoostRules;

$fmtBoost = static fn (float $b) => '×' . rtrim(rtrim(number_format($b, 2, ',', ''), '0'), ',');
?>
<div class="page-head">
  <p class="muted br-intro">Langue <?= e(strtoupper($lang)) ?>. Une règle <strong>multiplie la pertinence</strong> des produits qui remplissent ses conditions :
    <strong>1</strong> = neutre, <strong>1,5</strong> ou <strong>2</strong> = plus haut, <strong>0,5</strong> = plus bas, <strong>0</strong> = en fin de liste.
    Sans terme de recherche, elle s'applique à toutes les recherches. Effet immédiat, en tri « pertinence », sur les
    <a href="<?= e(url('/admin/relevance')) ?>">premiers résultats réordonnés</a>. Vérifiez le résultat dans l'<a href="<?= e(url('/admin/preview')) ?>">aperçu interne</a>.</p>
  <button type="button" class="btn" data-br-open>+ Ajouter une règle</button>
</div>

<div class="table-scroll">
<table class="table br-table">
  <thead><tr><th>Statut</th><th>Nom</th><th class="num">Boost</th><th>Termes de recherche</th><th>Conditions</th><th>Période</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rules as $r): ?>
    <tr class="<?= $r['active'] ? '' : 'dim-soft' ?>">
      <td>
        <form method="post" action="<?= e(url('/admin/boost-rules/toggle')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <label class="toggle toggle-sm"><input type="checkbox" role="switch" data-autosubmit <?= $r['active'] ? 'checked' : '' ?> aria-label="Activer la règle <?= e($r['name']) ?>"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
          <noscript><button class="btn btn-sm">Basculer</button></noscript>
        </form>
      </td>
      <td><strong><?= e($r['name']) ?></strong></td>
      <td class="num"><span class="badge <?= $r['boost'] > 1 ? 'badge-ok' : ($r['boost'] < 1 ? 'badge-ko' : 'badge-info') ?>"><?= e($fmtBoost($r['boost'])) ?></span></td>
      <td><?php if ($r['terms'] === []): ?><span class="muted">Toutes les recherches</span><?php else: ?>
        <div class="chips"><?php foreach ($r['terms'] as $t): ?><span class="chip" title="<?= $t['match'] === 'broad' ? 'Correspondance large' : 'Correspondance exacte' ?>"><span class="chip-ico"><?= $t['match'] === 'broad' ? '≈' : '=' ?></span><?= e($t['term']) ?></span><?php endforeach; ?></div>
      <?php endif; ?></td>
      <td class="br-desc"><?= e(BoostRules::describe($r, $lang)) ?></td>
      <td class="br-period"><?php $per = BoostRules::period($r); ?>
        <?= $r['starts_at'] || $r['ends_at'] ? e(($r['starts_at'] ? date('d/m/Y', strtotime($r['starts_at'])) : '…') . ' → ' . ($r['ends_at'] ? date('d/m/Y', strtotime($r['ends_at'])) : '…')) : '<span class="muted">Permanente</span>' ?>
        <?= $per === 'scheduled' ? '<br><span class="badge badge-info">programmée</span>' : ($per === 'expired' ? '<br><span class="badge badge-warn">terminée</span>' : '') ?></td>
      <td class="actions">
        <button type="button" class="btn btn-sm btn-light" data-br-open data-rule="<?= e(\DeepFind\Text::json($r)) ?>">Modifier</button>
        <form method="post" action="<?= e(url('/admin/boost-rules/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button class="btn btn-sm btn-danger" data-confirm="Supprimer la règle « <?= e($r['name']) ?> » ?">Supprimer</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rules): ?><tr><td colspan="7" class="muted">Aucune règle. Exemple : « Plongée en avant sur palme » — boost 1,5, terme ≈ palme, condition Catégorie : Plongée.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<dialog id="br-dialog" class="modal" aria-labelledby="br-title">
  <form method="post" action="<?= e(url('/admin/boost-rules/save')) ?>" class="form modal-form" data-boost-rule-form
        data-fields="<?= e(\DeepFind\Text::json($fields)) ?>" data-values-url="<?= e(url('/admin/boost-rules/values')) ?>">
    <?= csrf_field() ?><input type="hidden" name="payload" value="">
    <div class="modal-head">
      <div class="modal-title"><h2 id="br-title">Ajouter une règle de boost</h2></div>
      <button type="button" class="icon-btn modal-close" data-dialog-close aria-label="Fermer">✕</button>
    </div>
    <div class="modal-body">
      <label class="toggle br-status"><span class="toggle-text">Statut</span><input type="checkbox" role="switch" data-f="active" checked><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>

      <label>Nom de la règle
        <input data-f="name" maxlength="190" placeholder="Saisissez un nom" required>
        <small class="muted">Pour retrouver la règle dans la liste (ex. « Plongée en avant sur palme »).</small></label>

      <label>Boost
        <input data-f="boost" type="number" step="0.1" min="0" max="10" value="1" class="br-boost" required>
        <small class="muted">Coefficient appliqué à la pertinence : 1 = neutre, 2 = deux fois plus, 0,5 = moitié moins, 0 = en fin de liste.</small></label>

      <div class="grid2 br-dates">
        <label>Date de début <small class="muted">(facultative)</small><input type="date" data-f="starts_at"></label>
        <label>Date de fin <small class="muted">(facultative, incluse)</small><input type="date" data-f="ends_at"></label>
      </div>

      <div class="br-section">
        <div class="br-row">
          <div><strong>Termes de recherche</strong><br><small class="muted">Appliquer cette règle uniquement à ces recherches :</small></div>
          <label class="toggle"><input type="checkbox" role="switch" data-f="terms_enabled" aria-label="Limiter à des termes de recherche"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
        </div>
        <div class="br-terms" data-terms-box hidden>
          <div class="br-term-input">
            <select data-f="term_match" aria-label="Type de correspondance">
              <option value="exact">= Correspondance exacte</option>
              <option value="broad">≈ Correspondance large</option>
            </select>
            <input data-f="term" placeholder="Saisir un terme de recherche" aria-label="Terme de recherche">
            <button type="button" class="btn" data-add-term>Ajouter un terme</button>
          </div>
          <small class="muted">Exacte : la recherche est exactement ce terme. Large : la recherche contient tous les mots du terme (« palme » couvre « palmes de plongée »).</small>
          <div class="chips" data-terms></div>
        </div>
      </div>

      <div class="br-section">
        <strong>Conditions</strong>
        <label class="check"><input type="radio" name="br_match" value="all" checked><span>Les produits remplissent <strong>toutes</strong> les conditions</span></label>
        <label class="check"><input type="radio" name="br_match" value="any"><span>Les produits remplissent <strong>au moins une</strong> condition</span></label>
        <div data-conds class="br-conds"></div>
        <button type="button" class="btn btn-light br-add-cond" data-add-cond>+ Ajouter une condition</button>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-light" data-dialog-close>Annuler</button>
      <button class="btn">Enregistrer</button>
    </div>
  </form>
</dialog>
<script src="<?= e(asset('/assets/boost-rules.js')) ?>"></script>
