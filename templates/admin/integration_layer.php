<?php
/** @var array $layer */
use DeepFind\Widget\WidgetConfig;

$s = $layer['settings'];
$type = $layer['type'];
$back = '/admin/integration/layer?id=' . (int) $layer['id'];
$colors = [
    'color_primary' => ['Primaire', '#1d2b4f', 'Couleur principale de la marque pour les boutons et les éléments en surbrillance.'],
    'color_contrast' => ['Contraste primaire', '#ffffff', 'Utilisée par-dessus la couleur primaire (texte des boutons, filtre sélectionné).'],
    'color_text' => ['Texte', '#1d2b4f', 'Couleur de texte par défaut dans tout le layer.'],
];
?>
<p><a href="<?= e(url('/admin/integration')) ?>" class="cr-back">‹ Retour aux paramètres du layer</a></p>

<form method="post" action="<?= e(url('/admin/integration/layer/save')) ?>" class="form lp">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $layer['id'] ?>">

  <div class="lp-head">
    <label class="lp-name">
      <span class="sr-only">Nom du layer</span>
      <input name="name" value="<?= e($layer['name']) ?>" maxlength="100" required aria-label="Nom du layer" data-lp-name readonly>
      <button type="button" class="icon-btn" data-lp-rename title="Renommer" aria-label="Renommer le layer"><?= admin_icon('pencil') ?></button>
    </label>
    <?php if ($layer['active']): ?>
      <span class="badge badge-ok lp-badge">Layer actif</span>
    <?php else: ?>
      <span class="badge lp-badge">Layer inactif</span>
    <?php endif; ?>
  </div>

  <section class="card lp-card">
    <h2 class="lp-title">Options du layer</h2>
    <div class="grid2">
      <label>Mise en page
        <input value="<?= e(WidgetConfig::TYPES[$type][0]) ?>" readonly disabled>
        <small class="muted">La mise en page choisie lors de la création ne peut pas être modifiée.</small>
      </label>
      <label>Caractères pour afficher les résultats
        <input name="min_chars" type="number" min="1" max="10" value="<?= (int) $s['min_chars'] ?>" class="lp-num">
        <small class="muted">Nombre de caractères dans le champ de recherche pour commencer à afficher les résultats.</small>
      </label>
    </div>
    <div class="lp-row">
      <div><strong>Conservation de la requête de recherche</strong>
        <small class="muted">Si activé, la recherche, les filtres et le tri sont gardés dans l'adresse de la page (<code>#df=…</code>) :
          la fenêtre se rouvre à l'identique si la page est rechargée. Si une fonctionnalité de votre site dépend des ancres d'URL, laissez ce réglage désactivé.</small></div>
      <label class="toggle"><input type="checkbox" role="switch" name="url_state" value="1" <?= $s['url_state'] ? 'checked' : '' ?> aria-label="Conservation de la requête de recherche"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
    </div>
    <label>Sélecteur CSS du champ de recherche
      <input name="input" value="<?= e($s['input']) ?>" placeholder="#search" class="code">
      <small class="muted">Par exemple <code>#search</code> ou <code>input[name="q"]</code>. Vide : celui indiqué dans le code du site (<code>data-input</code>).</small>
    </label>
    <?php if ($type === 'embedded'): ?>
      <label>Sélecteur CSS du bloc d'affichage
        <input name="container" value="<?= e($s['container']) ?>" placeholder="main#maincontent" class="code" required>
        <small class="muted">Bloc de votre page dans lequel les résultats s'affichent à la place de son contenu, tant qu'une recherche est en cours.</small>
      </label>
    <?php endif; ?>
    <?php if ($type === 'below'): ?>
      <label>Écart sous la barre de recherche (pixels)
        <input name="gap" type="number" min="0" max="80" value="<?= (int) $s['gap'] ?>" class="lp-num">
      </label>
    <?php endif; ?>
  </section>

  <section class="card lp-card">
    <h2 class="lp-title">Identité de marque</h2>
    <p class="muted lp-intro">Personnalisez le logo et les couleurs de votre marque pour ce layer.</p>
    <label>Logo
      <input name="logo" type="url" value="<?= e($s['logo']) ?>" placeholder="https://www.cabesto.com/logo.png">
      <small class="muted">Adresse de l'image du logo (https). PNG ou SVG avec fond transparent recommandé.
        <?= in_array($type, ['fullscreen', 'floating'], true) ? 'Affiché à gauche de la barre de recherche du layer.' : 'Affiché en haut des résultats.' ?></small>
    </label>
    <div class="lp-colors">
      <?php foreach ($colors as $name => [$label, $default, $help]): ?>
        <label><?= e($label) ?>
          <span class="lp-color">
            <input type="color" value="<?= e($s[$name] ?: $default) ?>" data-color-for="<?= e($name) ?>" aria-label="Choisir la couleur « <?= e($label) ?> »">
            <input name="<?= e($name) ?>" value="<?= e($s[$name]) ?>" placeholder="<?= e(strtoupper($default)) ?>" pattern="#[0-9a-fA-F]{6}" maxlength="7" class="code" data-color-text>
          </span>
          <small class="muted"><?= e($help) ?> Vide : <?= e(strtoupper($default)) ?>.</small>
        </label>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card lp-card">
    <h2 class="lp-title">Personnalisation avancée</h2>
    <div class="lp-row">
      <div><strong>CSS</strong>
        <small class="muted">Ajoutez du CSS pour personnaliser votre layer. Il s'applique à toute la page du site : ciblez les classes <code>.df-…</code> du widget.
          <?= $s['css'] !== '' ? '<br><span class="badge badge-info">CSS personnalisé actif</span>' : '' ?></small></div>
      <button type="button" class="btn" data-dialog-open="lp-css">Modifier</button>
    </div>
    <div class="lp-row">
      <div><strong>Modèle de carte produit</strong>
        <small class="muted">Modifiez le modèle HTML de la carte produit. Vide : carte par défaut.
          <?= $s['card_template'] !== '' ? '<br><span class="badge badge-info">Modèle personnalisé actif</span>' : '' ?></small></div>
      <button type="button" class="btn" data-dialog-open="lp-card-tpl">Modifier</button>
    </div>
  </section>

  <dialog id="lp-css" class="modal" aria-labelledby="lp-css-title">
    <div class="modal-head">
      <div class="modal-title"><h2 id="lp-css-title">CSS personnalisé</h2></div>
      <button type="button" class="icon-btn modal-close" data-dialog-close aria-label="Fermer">✕</button>
    </div>
    <div class="modal-body">
      <p class="muted small">Exemple : <code>.df-card { border-radius: 0; }</code> ou <code>.df-title { text-transform: uppercase; }</code>. Pas de balise &lt;style&gt;.</p>
      <textarea name="css" rows="16" class="code lp-code" spellcheck="false" aria-label="CSS personnalisé"><?= e($s['css']) ?></textarea>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-light" data-dialog-close>Fermer</button>
      <button class="btn">Enregistrer</button>
    </div>
  </dialog>

  <dialog id="lp-card-tpl" class="modal" aria-labelledby="lp-card-title">
    <div class="modal-head">
      <div class="modal-title"><h2 id="lp-card-title">Modèle de carte produit</h2></div>
      <button type="button" class="icon-btn modal-close" data-dialog-close aria-label="Fermer">✕</button>
    </div>
    <div class="modal-body">
      <p class="muted small">HTML simple (div, span, p, strong, img…) ; pas de script ni de lien : la carte entière mène déjà au produit.
        Variables disponibles :</p>
      <ul class="lp-vars">
        <?php foreach (WidgetConfig::CARD_VARS as $v => $help): ?><li><code>{{<?= e($v) ?>}}</code> <span class="muted"><?= e($help) ?></span></li><?php endforeach; ?>
      </ul>
      <textarea name="card_template" rows="12" class="code lp-code" spellcheck="false" aria-label="Modèle de carte produit"
        placeholder='<div class="df-media"><img src="{{image}}" alt=""></div>
<div class="df-brand">{{brand}}</div>
<div class="df-name">{{name}}</div>
<div class="df-price"><span class="df-final">{{price}}</span> <span class="df-old">{{old_price}}</span></div>'><?= e($s['card_template']) ?></textarea>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-light" data-dialog-close>Fermer</button>
      <button class="btn">Enregistrer</button>
    </div>
  </dialog>

  <div class="lp-foot">
    <button class="btn">Enregistrer</button>
  </div>
</form>

<form method="post" action="<?= e(url('/admin/integration/layer/active')) ?>" class="lp-activate"><?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $layer['id'] ?>"><input type="hidden" name="active" value="<?= $layer['active'] ? '0' : '1' ?>">
  <input type="hidden" name="back" value="<?= e($back) ?>">
  <?php if ($layer['active']): ?>
    <button class="btn btn-sm btn-light" data-confirm="Désactiver « <?= e($layer['name']) ?> » ? Le widget reprendra l'affichage par défaut (sous la barre de recherche).">Désactiver le layer</button>
  <?php else: ?>
    <button class="btn btn-sm btn-light" data-confirm="Activer « <?= e($layer['name']) ?> » ? Il remplacera le layer actif sur le site.">Activer le layer</button>
  <?php endif; ?>
</form>
<script src="<?= e(asset('/assets/integration.js')) ?>"></script>
