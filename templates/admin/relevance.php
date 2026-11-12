<?php
use DeepFind\Search\Relevance;
$w = static fn (string $k) => e($values[$k] ?? '');
$pending = $weightsChangedAt !== '' && ($lastFull === '' || $lastFull < $weightsChangedAt);
?>
<div class="card explain">
  <h3>Comment un produit est classé</h3>
  <ol>
    <li><strong>Nombre de mots de la recherche trouvés</strong> dans le produit (toujours en premier).</li>
    <li><strong>Tri choisi par le visiteur</strong> (prix, nouveautés) ; sinon, si l'option est cochée, les <strong>ruptures passent en fin de liste</strong>.</li>
    <li><strong>Fautes de frappe</strong> (moins il y en a, mieux c'est) et <strong>proximité</strong> des mots entre eux.</li>
    <li><strong>Champ où le mot a été trouvé</strong> : l'ordre ci-dessous (un mot dans le nom compte plus que dans la description).</li>
    <li><strong>Exactitude</strong> (mot complet plutôt que début de mot).</li>
    <li><strong>Score métier</strong> : stock, marge, popularité, nouveauté, boost manuel. Sa place dans cette liste se règle plus bas (« Importance du score métier »).</li>
  </ol>
  <p class="muted">Les produits épinglés ou remontés par une <a href="<?= e(url('/admin/rules')) ?>">règle</a> passent avant tout cela. Vérifiez l'effet de vos réglages dans l'<a href="<?= e(url('/admin/preview')) ?>">aperçu interne</a>, qui affiche le score de chaque produit.</p>
</div>

<form method="post" action="<?= e(url('/admin/relevance/save')) ?>" class="form">
  <?= csrf_field() ?>

  <h2>1. Importance des champs dans la recherche texte</h2>
  <p class="muted">Numérotez de 1 (le plus important) au dernier ; décochez un champ pour qu'il ne soit plus cherchable. Appliqué <strong>immédiatement</strong> au moteur.</p>
  <table class="table compact rel-fields">
    <thead><tr><th class="num">Rang</th><th>Champ</th><th>Rôle</th><th>Cherchable</th></tr></thead>
    <tbody>
    <?php foreach ($fields as $i => $r): [$label, $help, $canDisable] = Relevance::FIELDS[$r['field']]; ?>
      <tr class="<?= $r['enabled'] ? '' : 'dim' ?>">
        <td class="num"><input type="number" name="fields[<?= e($r['field']) ?>][pos]" value="<?= $i + 1 ?>" min="1" max="20" class="rank" aria-label="Rang de <?= e($label) ?>"></td>
        <td><strong><?= e($label) ?></strong><br><code><?= e($r['field']) ?></code></td>
        <td class="muted"><?= e($help) ?></td>
        <td><?php if ($canDisable): ?><input type="checkbox" name="fields[<?= e($r['field']) ?>][enabled]" value="1" <?= $r['enabled'] ? 'checked' : '' ?> aria-label="<?= e($label) ?> cherchable"><?php else: ?><span class="badge badge-ok">toujours</span><input type="hidden" name="fields[<?= e($r['field']) ?>][enabled]" value="1"><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h2>2. Score métier : ce que vous voulez mettre en avant</h2>
  <?php if ($pending): ?><div class="flash flash-warn">Des poids ont été modifiés le <?= fmt_dt($weightsChangedAt) ?> mais aucun import complet n'a eu lieu depuis : ils ne sont pas encore appliqués.</div><?php endif; ?>
  <p class="muted">Score = stock × poids + marge (0 à 1) × poids + popularité (0 à 1) × poids + nouveauté × poids + boost manuel (-10 à +10) × poids.
    Mettez 0 pour ignorer un critère. Calculé pendant l'import : appliqué au <strong>prochain import complet</strong>.</p>
  <div class="card grid4 weights">
    <label>En stock <input name="w_stock" value="<?= $w('w_stock') ?>" inputmode="decimal"><small class="muted">points si le produit est en stock</small></label>
    <label>Rentabilité <input name="w_margin" value="<?= $w('w_margin') ?>" inputmode="decimal"><small class="muted">× taux de marge (0,40 → 40 % du poids)</small></label>
    <label>Popularité <input name="w_popularity" value="<?= $w('w_popularity') ?>" inputmode="decimal"><small class="muted">× ventes 30 j (échelle logarithmique)</small></label>
    <label>Nouveauté <input name="w_new" value="<?= $w('w_new') ?>" inputmode="decimal"><small class="muted">points si créé il y a moins de N jours</small></label>
    <label>Boost manuel <input name="w_manual_boost" value="<?= $w('w_manual_boost') ?>" inputmode="decimal"><small class="muted">× champ « manual_boost » du flux</small></label>
    <label>Ventes 30 j = popularité max. <input name="popularity_ref" value="<?= $w('popularity_ref') ?>" inputmode="decimal"><small class="muted">au-delà, popularité plafonnée à 1</small></label>
    <label>« Nouveau » pendant (jours) <input name="new_days" value="<?= $w('new_days') ?>" inputmode="numeric"><small class="muted">aussi le badge « Nouveau » du widget</small></label>
  </div>

  <h2>3. Importance du score métier face à la pertinence</h2>
  <div class="card">
    <?php foreach (Relevance::IMPORTANCE as $k => [$label, $help]): ?>
      <label class="check radio-line"><input type="radio" name="biz_importance" value="<?= e($k) ?>" <?= $importance === $k ? 'checked' : '' ?>>
        <span><strong><?= e($label) ?></strong><br><span class="muted"><?= e($help) ?></span></span></label>
    <?php endforeach; ?>
    <label class="toggle">
      <input type="checkbox" role="switch" name="out_of_stock_last" value="1" <?= ($values['out_of_stock_last'] ?? '1') === '1' ? 'checked' : '' ?>>
      <span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span>
      <span class="toggle-text"><strong>Ruptures en fin de liste</strong>
        <small class="muted">À pertinence égale (même nombre de mots trouvés), les produits en stock passent toujours devant.</small></span>
    </label>
    <p class="muted small">Appliqué immédiatement au moteur.</p>
  </div>

  <div class="actions sticky-actions">
    <button class="btn">Enregistrer</button>
    <?php if ($sources): ?>
    <button class="btn btn-light" name="reindex" value="1" data-confirm="Enregistrer puis lancer un import complet des flux produits actifs pour recalculer le score métier ?">Enregistrer et recalculer maintenant (import complet)</button>
    <?php endif; ?>
  </div>
</form>
