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

<form method="post" action="<?= e(url('/admin/integration/layer/save')) ?>" class="form lp" id="lp-form">
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
      <div class="lp-field">
        <span class="lp-field-label">Mise en page</span>
        <div class="lp-type-current">
          <span class="ly-pic ly-pic-<?= e($type) ?>" aria-hidden="true" data-layer-type-pic><i></i><i></i><i></i><i></i><i></i><i></i></span>
          <span class="lp-type-text"><strong data-layer-type-name><?= e(WidgetConfig::TYPES[$type][0]) ?></strong>
            <small class="muted" data-layer-type-help><?= e(WidgetConfig::TYPES[$type][1]) ?></small></span>
          <button type="button" class="btn btn-sm btn-light" data-dialog-open="lp-type-dialog">Modifier</button>
        </div>
      </div>
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
    <label data-layer-only="embedded"<?= $type === 'embedded' ? '' : ' hidden' ?>>Sélecteur CSS du bloc d'affichage
      <input name="container" value="<?= e($s['container']) ?>" placeholder="main#maincontent" class="code" <?= $type === 'embedded' ? 'required' : '' ?>>
      <small class="muted">Bloc de votre page dans lequel les résultats s'affichent à la place de son contenu, tant qu'une recherche est en cours.</small>
    </label>
    <div class="lp-field" data-layer-only="below floating embedded"<?= $type === 'fullscreen' ? ' hidden' : '' ?>>
      <label for="lp-max-width">Largeur maximale de la fenêtre</label>
      <span class="lp-unit-field">
        <input id="lp-max-width" name="max_width" type="number" step="1" value="<?= $s['max_width'] ?: '' ?>" class="lp-num" placeholder="Par défaut" data-width-input
          <?= $s['max_width_unit'] === 'px' ? 'min="320" max="3000"' : 'min="10" max="100"' ?>>
        <select name="max_width_unit" aria-label="Unité de la largeur" data-width-unit>
          <option value="pct" <?= $s['max_width_unit'] === 'pct' ? 'selected' : '' ?>>% de l'écran</option>
          <option value="px" <?= $s['max_width_unit'] === 'px' ? 'selected' : '' ?>>pixels</option>
        </select>
      </span>
      <small class="muted">Vide : largeur par défaut (Sous la barre : 1 440 px, Flottant : 1 200 px, Intégré : toute la largeur du bloc).
        En % : part de la largeur de l'écran (10 à 100 ; pour Intégré, part de la largeur du bloc). En pixels : 320 à 3 000.
        La fenêtre garde une marge de 16 px de chaque côté ; sur mobile (900 px ou moins), elle prend toute la largeur.</small>
    </div>
    <label data-layer-only="below"<?= $type === 'below' ? '' : ' hidden' ?>>Écart sous la barre de recherche (pixels)
      <input name="gap" type="number" min="0" max="80" value="<?= (int) $s['gap'] ?>" class="lp-num">
    </label>
  </section>

  <dialog id="lp-type-dialog" class="modal" aria-labelledby="lp-type-title">
    <div class="modal-head">
      <div class="modal-title"><h2 id="lp-type-title">Mise en page du layer</h2></div>
      <button type="button" class="icon-btn modal-close" data-dialog-close aria-label="Fermer">✕</button>
    </div>
    <div class="modal-body">
      <p><strong>Choisissez une mise en page pour votre boutique</strong><br><span class="muted">Chaque mise en page définit où la recherche apparaît et comment les acheteurs y accèdent.
        Les réglages propres à la mise en page (bloc d'affichage, écart, largeur) s'affichent ensuite dans « Options du layer ».</span></p>
      <div class="ly-types" role="radiogroup" aria-label="Mise en page">
        <?php foreach (WidgetConfig::TYPES as $k => [$tlabel, $thelp]): ?>
          <label class="ly-type">
            <input type="radio" name="type" value="<?= e($k) ?>" <?= $type === $k ? 'checked' : '' ?> data-layer-type data-name="<?= e($tlabel) ?>" data-help="<?= e($thelp) ?>">
            <span class="ly-pic ly-pic-<?= e($k) ?>" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></span>
            <strong><?= e($tlabel) ?></strong>
            <small class="muted"><?= e($thelp) ?></small>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="muted small">Le changement est appliqué au site après « Enregistrer ».</p>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn" data-dialog-close>Valider</button>
    </div>
  </dialog>

  <section class="card lp-card">
    <h2 class="lp-title">Identité de marque</h2>
    <p class="muted lp-intro">Personnalisez le logo et les couleurs de votre marque pour ce layer.</p>
    <label>Logo
      <input name="logo" type="url" value="<?= e($s['logo']) ?>" placeholder="https://www.example.com/logo.png">
      <small class="muted">Adresse de l'image du logo (https). PNG ou SVG avec fond transparent recommandé.
        Plein écran et Flottant : à gauche de la barre de recherche du layer ; autres mises en page : en haut des résultats.</small>
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
    <?php
    $adv = [
        'lp-css' => ['CSS', 'Ajoutez du CSS pour personnaliser votre layer. Il s\'applique à toute la page du site : ciblez les classes <code>.df-…</code> du widget.', 'css'],
        'lp-card-tpl' => ['Modèle de carte produit', 'Modifiez le modèle HTML de la carte produit (le code utilisé sur le site est affiché, ordinateur et mobile).', 'card_template'],
    ];
    foreach ($adv as $dlg => [$label, $help, $key]):
        $on = array_filter([$s[$key] !== '' ? 'ordinateur' : '', $s[$key . '_mobile'] !== '' ? 'mobile' : '']); ?>
    <div class="lp-row">
      <div><strong><?= e($label) ?></strong>
        <small class="muted"><?= $help ?><?= $on ? '<br><span class="badge badge-info">Personnalisé : ' . e(implode(' + ', $on)) . '</span>' : '' ?></small></div>
      <button type="button" class="btn lp-edit-btn" data-dialog-open="<?= e($dlg) ?>">Modifier</button>
    </div>
    <?php endforeach; ?>
    <?php $custom = $s['cols_desktop'] || $s['cols_mobile'] !== 2 || $s['per_page_desktop'] !== 20 || $s['per_page_mobile'] !== 20; ?>
    <div class="lp-row">
      <div><strong>Nombre de produits</strong>
        <small class="muted">Produits par ligne et produits chargés à la fois, sur ordinateur et sur mobile.
          <br><span class="badge <?= $custom ? 'badge-info' : '' ?>" data-products-summary>Ordinateur : <?= $s['cols_desktop'] ?: 'auto' ?> par ligne, <?= $s['per_page_desktop'] ?> à la fois · Mobile : <?= $s['cols_mobile'] ?> par ligne, <?= $s['per_page_mobile'] ?> à la fois</span></small></div>
      <button type="button" class="btn lp-edit-btn" data-dialog-open="lp-products">Modifier</button>
    </div>
  </section>

  <dialog id="lp-products" class="modal lp-products" aria-labelledby="lp-products-title" data-products>
    <div class="modal-head">
      <div class="modal-title"><h2 id="lp-products-title">Nombre de produits</h2></div>
      <button type="button" class="icon-btn modal-close" data-dialog-close aria-label="Fermer">✕</button>
    </div>
    <div class="modal-body">
      <div class="lp-products-grid">
        <?php foreach (['desktop' => ['Ordinateur', 'auto', 8], 'mobile' => ['Mobile', '', 3]] as $dev => [$dlabel, $auto, $maxCols]): ?>
          <fieldset class="lp-device">
            <legend><?= e($dlabel) ?></legend>
            <label>Produits par ligne
              <select name="cols_<?= $dev ?>" data-cols="<?= $dev ?>">
                <?php if ($auto): ?><option value="0" <?= $s['cols_desktop'] === 0 ? 'selected' : '' ?>>Automatique (selon la largeur)</option><?php endif; ?>
                <?php for ($i = 1; $i <= $maxCols; $i++): ?><option value="<?= $i ?>" <?= $s['cols_' . $dev] === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?>
              </select>
            </label>
            <div class="lp-preview lp-preview-<?= $dev ?>" aria-hidden="true" data-preview="<?= $dev ?>"></div>
            <label>Produits chargés à la fois
              <input name="per_page_<?= $dev ?>" type="number" min="4" max="48" value="<?= (int) $s['per_page_' . $dev] ?>" class="lp-num" data-per-page="<?= $dev ?>">
              <small class="muted">De 4 à 48. Les suivants se chargent quand le visiteur fait défiler les résultats.</small>
            </label>
          </fieldset>
        <?php endforeach; ?>
      </div>
      <p class="muted small">Mobile = écrans de 900 px de large ou moins. « Automatique » : autant de produits que la largeur le permet (cartes d'environ 220 px).
        L'attribut <code>data-page-size</code> du code du site, s'il est présent, reste prioritaire pour les produits chargés à la fois.</p>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-light" data-dialog-close>Fermer</button>
      <button class="btn">Enregistrer</button>
    </div>
  </dialog>

  <?php
  // Éditeur plein écran : onglets Ordinateur / Mobile, restaurer la valeur par défaut, copier
  $editor = static function (string $id, string $title, string $lang, string $key, array $s, string $default, string $intro): void { ?>
  <dialog id="<?= e($id) ?>" class="modal lp-editor" aria-labelledby="<?= e($id) ?>-title" data-editor>
    <div class="modal-head">
      <div class="modal-title"><h2 id="<?= e($id) ?>-title"><?= e($title) ?> – <span data-editor-device-label>Ordinateur</span></h2></div>
      <div class="lp-seg" role="tablist" aria-label="Appareil">
        <button type="button" role="tab" class="lp-seg-btn is-on" aria-selected="true" data-editor-tab="desktop">Ordinateur</button>
        <button type="button" role="tab" class="lp-seg-btn" aria-selected="false" data-editor-tab="mobile">Mobile</button>
      </div>
      <button type="button" class="icon-btn modal-close" data-dialog-close aria-label="Fermer">✕</button>
    </div>
    <div class="modal-body">
      <?= $intro ?>
      <div class="lp-code-box">
        <div class="lp-code-head">
          <span><?= e($lang) ?></span>
          <span class="lp-code-tools">
            <?php if ($default !== ''): ?>
              <button type="button" class="lp-code-tool" data-editor-restore title="Remettre le modèle d'origine dans l'onglet ouvert">↻ Réinitialiser (modèle d'origine)</button>
            <?php endif; ?>
            <button type="button" class="lp-code-tool" data-editor-copy title="Copier" aria-label="Copier le contenu"><?= admin_icon('copy', 'key-copy') ?><?= admin_icon('check', 'key-copied') ?></button>
          </span>
        </div>
        <?php
        // modèle de carte : le code réellement utilisé (modèle par défaut si rien n'est enregistré ; mobile = ordinateur s'il est vide)
        $desk = $s[$key] !== '' || $default === '' ? $s[$key] : $default;
        $mob = $s[$key . '_mobile'] !== '' || $default === '' ? $s[$key . '_mobile'] : $desk; ?>
        <textarea name="<?= e($key) ?>" rows="16" class="lp-code" spellcheck="false" data-editor-device="desktop" aria-label="<?= e($title) ?> ordinateur"><?= e($desk) ?></textarea>
        <textarea name="<?= e($key) ?>_mobile" rows="16" class="lp-code" spellcheck="false" data-editor-device="mobile" hidden aria-label="<?= e($title) ?> mobile"
          placeholder="Vide : la version ordinateur s'applique aussi sur mobile."><?= e($mob) ?></textarea>
        <?php if ($default !== ''): ?><template data-editor-default><?= e($default) ?></template><?php endif; ?>
      </div>
      <p class="muted small"><?= $default !== ''
          ? 'Le code affiché est celui utilisé sur le site : modifiez-le puis enregistrez. « Réinitialiser » remet le modèle d\'origine de l\'onglet ouvert. Mobile identique à l\'ordinateur : il suit les changements de l\'ordinateur.'
          : 'Mobile = écrans de 900 px de large ou moins. Mobile vide : la version ordinateur s\'applique partout.' ?></p>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-light" data-dialog-close>Fermer</button>
      <button class="btn">Enregistrer</button>
    </div>
  </dialog>
  <?php };
  $vars = '<ul class="lp-vars">';
  foreach (WidgetConfig::CARD_VARS as $v => $help) {
      $vars .= '<li><code>{{' . e($v) . '}}</code> <span class="muted">' . e($help) . '</span></li>';
  }
  $vars .= '</ul>';
  $editor('lp-css', 'CSS personnalisé', 'CSS', 'css', $s, '',
      '<p class="muted small">Exemple : <code>.df-card { border-radius: 0; }</code> ou <code>.df-title { text-transform: uppercase; }</code>. Pas de balise &lt;style&gt;.</p>');
  $editor('lp-card-tpl', 'Modèle de carte produit', 'HTML', 'card_template', $s, WidgetConfig::DEFAULT_CARD_TEMPLATE,
      '<p class="muted small">HTML simple (div, span, p, strong, img…) ; pas de script ni de lien : la carte entière mène déjà au produit.
        Un élément de classe <code>df-if-</code><em>variable</em> (ex. <code>df-if-discount</code>) n\'est affiché que si la variable n\'est pas vide. Variables disponibles :</p>' . $vars);
  ?>
</form>

<form method="post" action="<?= e(url('/admin/integration/layer/active')) ?>" id="lp-activate" hidden><?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $layer['id'] ?>"><input type="hidden" name="active" value="<?= $layer['active'] ? '0' : '1' ?>">
  <input type="hidden" name="back" value="<?= e($back) ?>">
</form>
<div class="lp-foot">
  <?php if ($layer['active']): ?>
    <button class="btn btn-light" form="lp-activate" data-confirm="Désactiver « <?= e($layer['name']) ?> » ? Le widget reprendra l'affichage par défaut (sous la barre de recherche).">Désactiver le layer</button>
  <?php else: ?>
    <button class="btn btn-light" form="lp-activate" data-confirm="Activer « <?= e($layer['name']) ?> » ? Il remplacera le layer actif sur le site.">Activer le layer</button>
  <?php endif; ?>
  <button class="btn" form="lp-form">Enregistrer</button>
</div>
<script src="<?= e(asset('/assets/integration.js')) ?>"></script>
