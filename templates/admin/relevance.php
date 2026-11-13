<?php
use DeepFind\Search\Relevance;
$w = static fn (string $k) => e($values[$k] ?? '');
$pending = $weightsChangedAt !== '' && ($lastFull === '' || $lastFull < $weightsChangedAt);
$toggle = static function (string $name, bool $on, string $title, string $help): void { ?>
    <label class="toggle toggle-block">
      <input type="checkbox" role="switch" name="<?= e($name) ?>" value="1" <?= $on ? 'checked' : '' ?>>
      <span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span>
      <span class="toggle-text"><strong><?= e($title) ?></strong><small class="muted"><?= e($help) ?></small></span>
    </label>
<?php };
$radios = static function (string $name, array $options, string $current): void {
    foreach ($options as $k => [$label, $help]): ?>
      <label class="choice">
        <input type="radio" name="<?= e($name) ?>" value="<?= e($k) ?>" <?= $current === $k ? 'checked' : '' ?>>
        <span class="choice-text"><strong><?= e($label) ?></strong><small class="muted"><?= e($help) ?></small></span>
      </label>
    <?php endforeach;
};
?>
<div class="card explain">
  <h3>Comment un produit est classé</h3>
  <ol>
    <li><strong>Correspondance des mots</strong> : tous les mots de la recherche doivent être trouvés (réglage 1).</li>
    <li><strong>Tri choisi par le visiteur</strong> (prix, nouveautés) ; sinon, si l'option est activée, les <strong>ruptures passent en fin de liste</strong>.</li>
    <li><strong>Fautes de frappe</strong> (moins il y en a, mieux c'est) et <strong>proximité</strong> des mots entre eux.</li>
    <li><strong>Champ où le mot a été trouvé</strong> : l'ordre du réglage 2 (un mot dans le nom compte plus que dans la description).</li>
    <li><strong>Exactitude</strong> (mot complet plutôt que début de mot).</li>
    <li><strong>Score métier</strong> : stock, marge, popularité, clics, nouveauté, boosts (réglages 3 et 4).</li>
  </ol>
  <p class="muted">Les produits mis en avant par un <a href="<?= e(url('/admin/custom-results')) ?>">résultat personnalisé</a> passent avant tout cela. Vérifiez l'effet de vos réglages dans l'<a href="<?= e(url('/admin/preview')) ?>">aperçu interne</a> (pertinence et score de chaque produit).</p>
</div>

<form method="post" action="<?= e(url('/admin/relevance/save')) ?>" class="form has-fixed-actions">
  <?= csrf_field() ?>

  <h2>1. Correspondance des mots</h2>
  <div class="card">
    <?php $radios('matching_mode', Relevance::MATCHING, (string) ($values['matching_mode'] ?? 'exact_then_approx')); ?>
    <div class="grid4">
      <label>Pertinence minimum (0 à 1)
        <input name="min_relevance" value="<?= $w('min_relevance') ?>" inputmode="decimal">
        <small class="muted">Écarte les produits trop peu pertinents. 0 = désactivé. Réglez-le en regardant la colonne « Pertinence » de l'aperçu interne (ex. 0,5).</small>
      </label>
    </div>
    <p class="muted small">Appliqué immédiatement.</p>
  </div>

  <h2>2. Importance des champs dans la recherche texte</h2>
  <p class="muted">Numérotez de 1 (le plus important) au dernier ; désactivez un champ pour qu'il ne soit plus cherchable.
    <strong>Pas de poids chiffré par champ</strong> : Meilisearch classe uniquement par <em>ordre</em> des champs. Pour que cet ordre pèse davantage, activez l'option ci-dessous. Appliqué immédiatement.</p>
  <table class="table compact rel-fields">
    <thead><tr><th class="num">Rang</th><th>Champ</th><th>Rôle</th><th>Cherchable</th></tr></thead>
    <tbody>
    <?php foreach ($fields as $i => $r): [$label, $help, $canDisable] = Relevance::FIELDS[$r['field']]; ?>
      <tr class="<?= $r['enabled'] ? '' : 'dim' ?>">
        <td class="num"><input type="number" name="fields[<?= e($r['field']) ?>][pos]" value="<?= $i + 1 ?>" min="1" max="20" class="rank" aria-label="Rang de <?= e($label) ?>"></td>
        <td><strong><?= e($label) ?></strong><br><code><?= e($r['field']) ?></code></td>
        <td class="muted"><?= e($help) ?></td>
        <td><?php if ($canDisable): ?><label class="toggle toggle-sm" data-dim-row><input type="checkbox" role="switch" name="fields[<?= e($r['field']) ?>][enabled]" value="1" <?= $r['enabled'] ? 'checked' : '' ?> aria-label="<?= e($label) ?> cherchable"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label><?php else: ?><label class="toggle toggle-sm" title="Toujours cherchable"><input type="checkbox" role="switch" checked disabled aria-label="<?= e($label) ?> toujours cherchable"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text muted">toujours</span></label><input type="hidden" name="fields[<?= e($r['field']) ?>][enabled]" value="1"><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="card">
    <div class="grid4">
      <label>Bonus « mot recherché au début du nom »
        <input name="name_position_weight" value="<?= $w('name_position_weight') ?>" inputmode="decimal">
        <small class="muted">0 = désactivé, 0,5 recommandé, 1 = fort. Un mot de la recherche en 1re position du nom vaut 1, en 2e 1/2, en 3e 1/3…
          Ex. « pompe paddle » : « Pompe électrique… » passe devant « Pack Paddle … (Pompe…) ».</small>
      </label>
      <label>Résultats réordonnés
        <input name="rerank_window" value="<?= $w('rerank_window') ?>" inputmode="numeric">
        <small class="muted">Le bonus s'applique aux N premiers résultats (100 = 5 pages du widget).</small>
      </label>
    </div>
    <?php $toggle('attribute_first', ($values['attribute_first'] ?? '0') === '1', 'Priorité au champ où le mot est trouvé',
        'Un produit dont le NOM contient le mot passe devant un produit qui ne le contient que dans sa description, même avec moins de fautes de frappe. Recommandé si des produits « hors sujet » remontent à cause de leur description.'); ?>
  </div>

  <h2>3. Score métier : ce que vous voulez mettre en avant</h2>
  <?php if ($pending): ?><div class="flash flash-warn">Des poids ont été modifiés le <?= fmt_dt($weightsChangedAt) ?> mais les scores n'ont pas encore été recalculés.</div><?php endif; ?>
  <p class="muted">Score = stock × poids + marge (0 à 1) × poids + popularité (0 à 1) × poids + clics (0 à 1) × poids + nouveauté × poids + boost (-10 à +10) × poids.
    Mettez 0 pour ignorer un critère. Pour mettre en avant des produits, utilisez les <a href="<?= e(url('/admin/boost-rules')) ?>">règles de boost</a>.</p>
  <div class="card grid4 weights">
    <label>En stock <input name="w_stock" value="<?= $w('w_stock') ?>" inputmode="decimal"><small class="muted">points si le produit est en stock</small></label>
    <label>Rentabilité <input name="w_margin" value="<?= $w('w_margin') ?>" inputmode="decimal"><small class="muted">× taux de marge (0,40 → 40 % du poids)</small></label>
    <label>Popularité (ventes) <input name="w_popularity" value="<?= $w('w_popularity') ?>" inputmode="decimal"><small class="muted">× ventes 30 j du flux (échelle logarithmique)</small></label>
    <label>Clics dans la recherche <input name="w_clicks" value="<?= $w('w_clicks') ?>" inputmode="decimal"><small class="muted">× clics 30 j sur le produit depuis le widget</small></label>
    <label>Nouveauté <input name="w_new" value="<?= $w('w_new') ?>" inputmode="decimal"><small class="muted">points si créé il y a moins de N jours</small></label>
    <label>Boost (par niveau) <input name="w_manual_boost" value="<?= $w('w_manual_boost') ?>" inputmode="decimal"><small class="muted">× boost du flux + boost de l'extranet</small></label>
    <label>Ventes 30 j = popularité max. <input name="popularity_ref" value="<?= $w('popularity_ref') ?>" inputmode="decimal"><small class="muted">au-delà, plafonnée à 1</small></label>
    <label>Clics 30 j = max. <input name="clicks_ref" value="<?= $w('clicks_ref') ?>" inputmode="decimal"><small class="muted">au-delà, plafonnés à 1</small></label>
    <label>« Nouveau » pendant (jours) <input name="new_days" value="<?= $w('new_days') ?>" inputmode="numeric"><small class="muted">aussi le badge « Nouveau » du widget</small></label>
  </div>
  <div class="card">
    <?php $toggle('scores_nightly', ($values['scores_nightly'] ?? '1') === '1', 'Recalcul automatique des scores chaque nuit (4 h 30)',
        'Prend en compte les clics des 30 derniers jours, les boosts datés et les poids modifiés, sans retélécharger les flux.'); ?>
  </div>

  <h2>4. Importance du score métier face à la pertinence</h2>
  <div class="card">
    <?php $radios('biz_importance', Relevance::IMPORTANCE, $importance); ?>
    <?php $toggle('out_of_stock_last', ($values['out_of_stock_last'] ?? '1') === '1', 'Ruptures en fin de liste',
        'À pertinence égale (mêmes mots trouvés), les produits en stock passent toujours devant.'); ?>
    <p class="muted small">Appliqué immédiatement.</p>
  </div>

  <div class="fixed-actions sticky-actions">
    <button class="btn">Enregistrer</button>
    <?php if ($sources): ?>
    <button class="btn btn-light" name="reindex" value="1">Enregistrer et recalculer les scores</button>
    <?php endif; ?>
  </div>
</form>
