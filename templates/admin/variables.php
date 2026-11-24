<?php
/** @var string $lang @var array $defaults @var array $overrides @var array $groups @var array $system @var array $links @var array $custom @var array $values @var array $reserved */
?>
<div class="page-head">
  <p class="muted br-intro">Langue <?= e(strtoupper($lang)) ?> (changez-la en bas du menu). Trois sortes de variables pour le widget du site :
    les <strong>variables système</strong>, remplies automatiquement pour chaque produit ; les <strong>textes du widget</strong>, modifiables pour chaque langue ;
    vos <strong>variables personnalisées</strong>, à placer où vous voulez sous la forme <code>{{code}}</code>.</p>
</div>

<form method="post" action="<?= e(url('/admin/variables/save')) ?>" class="form var-form" data-var-form>
  <?= csrf_field() ?>

  <section class="card var-card">
    <h2>Variables système <small class="muted">(non modifiables)</small></h2>
    <p class="muted small">Remplies par le widget pour chaque produit, dans les modèles de carte (Paramètres › Intégration, carrousels). Une variable peut être
      <strong>remplacée par un texte fixe</strong> : gardez alors la classe <code>df-if-</code><em>variable</em> sur l'élément pour qu'il ne s'affiche que si la variable a une valeur
      (ex. <code>&lt;span class="df-if-from"&gt;À partir de :&lt;/span&gt;</code>).</p>
    <div class="table-scroll"><table class="table var-table">
      <thead><tr><th>Variable</th><th>Contenu</th><th>Affichage conditionnel</th></tr></thead>
      <tbody>
      <?php foreach ($system as $code => $help): ?>
        <tr><td><button type="button" class="var-code" data-copy-var="{{<?= e($code) ?>}}" title="Copier"><?= e('{{' . $code . '}}') ?></button></td>
          <td><?= e($help) ?></td><td><code>df-if-<?= e($code) ?></code></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="muted small">Dans le <strong>lien de la carte</strong> (ligne <code>&lt;a class="df-card" href="…"&gt;</code> du modèle) :
      <?php $i = 0; foreach ($links as $code => $help): ?><?= $i++ ? ' · ' : '' ?><code><?= e('{{' . $code . '}}') ?></code> <?= e($help) ?><?php endforeach; ?>.</p>
  </section>

  <section class="card var-card">
    <h2>Textes du widget <small class="muted">— langue <?= e(strtoupper($lang)) ?></small></h2>
    <p class="muted small">Champ vide = texte d'origine (affiché en gris). <code>%s</code> est remplacé par la recherche ou le nom du filtre. Texte brut, sans balise.</p>
    <?php foreach ($groups as $groupTitle => $keys): // pas $title : c'est le titre de la page (gabarit commun) ?>
      <h3 class="var-group"><?= e($groupTitle) ?></h3>
      <div class="var-texts">
        <?php foreach ($keys as $k => $help): ?>
          <label class="var-text<?= isset($overrides[$k]) ? ' is-changed' : '' ?>">
            <span class="var-text-head"><span><?= e($help) ?></span><?php if (isset($overrides[$k])): ?><span class="badge badge-info">modifié</span><?php endif; ?></span>
            <input name="texts[<?= e($k) ?>]" value="<?= e($overrides[$k] ?? '') ?>" placeholder="<?= e($defaults[$k] ?? '') ?>" maxlength="300" autocomplete="off">
          </label>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </section>

  <section class="card var-card">
    <h2>Variables personnalisées <small class="muted">— valeurs en <?= e(strtoupper($lang)) ?></small></h2>
    <p class="muted small">Un code (ex. <code>livraison</code>) et sa valeur pour chaque langue (ex. « Livraison offerte dès 50 € »). Utilisable sous la forme <code>{{livraison}}</code>
      dans les <strong>modèles de carte</strong> (avec <code>df-if-livraison</code> si besoin), le <strong>contenu HTML des popins et bannières</strong> et le <strong>titre des carrousels</strong>.
      Les codes sont communs à toutes les langues ; changez de langue en bas du menu pour saisir les autres valeurs. Une variable sans valeur dans une langue n'affiche rien.</p>
    <div class="table-scroll"><table class="table var-table var-custom">
      <thead><tr><th>Code</th><th>Valeur (<?= e(strtoupper($lang)) ?>)</th><th>Description interne</th><th></th></tr></thead>
      <tbody data-var-rows data-reserved="<?= e(\DeepFind\Text::json($reserved)) ?>">
      <?php foreach ($custom as $i => $v): ?>
        <tr data-var-row>
          <td><span class="var-code-wrap"><span class="var-brace">{{</span><input name="vars[<?= $i ?>][code]" value="<?= e($v['code']) ?>" class="var-code-input" maxlength="31" pattern="[a-z][a-z0-9_]{1,30}" aria-label="Code" required><span class="var-brace">}}</span></span></td>
          <td><input name="vars[<?= $i ?>][value]" value="<?= e($values[$v['code']] ?? '') ?>" maxlength="300" aria-label="Valeur"></td>
          <td><input name="vars[<?= $i ?>][description]" value="<?= e($v['description']) ?>" maxlength="300" aria-label="Description"></td>
          <td class="actions"><button type="button" class="icon-btn" data-var-remove aria-label="Supprimer la variable <?= e($v['code']) ?>">✕</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <button type="button" class="btn btn-light btn-sm var-add" data-var-add>+ Ajouter une variable</button>
  </section>

  <div class="cr-foot">
    <button class="btn">Enregistrer</button>
  </div>
</form>
<script src="<?= e(asset('/assets/variables.js')) ?>"></script>
