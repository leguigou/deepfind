<?php
/** @var string $tab @var string $lang @var array $layers @var ?array $edit @var array $filters @var array $sorts @var string $firstKey @var string $appUrl */
use DeepFind\Admin\IntegrationController;
use DeepFind\Widget\WidgetConfig;

$base = $appUrl !== '' ? $appUrl : 'https://search.cabesto.com';
$types = WidgetConfig::TYPES;
?>
<nav class="tabs" aria-label="Rubriques de l'intégration">
  <?php foreach (IntegrationController::TABS as $k => $label): ?>
    <a href="<?= e(url('/admin/integration', $k === 'layers' ? [] : ['tab' => $k])) ?>" class="tab<?= $tab === $k ? ' tab-on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'layers'): ?>
<p class="muted">Définissez votre layer par défaut et configurez son apparence et son comportement dans votre boutique.
  Un seul layer est actif à la fois ; sans layer actif, le widget s'affiche sous la barre de recherche (réglages par défaut).</p>

<div class="ly-list">
  <?php foreach ($layers as $l): $isEdit = $edit && $edit['id'] === $l['id']; ?>
    <div class="card ly-card<?= $l['active'] ? ' ly-active' : '' ?><?= $isEdit ? ' ly-edit' : '' ?>">
      <span class="ly-check" aria-hidden="true"><?= $l['active'] ? admin_icon('circle-check') : '' ?></span>
      <div class="ly-info">
        <strong><?= e($l['name']) ?></strong> <?= $l['active'] ? '<span class="badge badge-ok">Layer actif</span>' : '' ?>
        <br><span class="muted small"><?= e($types[$l['type']][0]) ?></span>
      </div>
      <div class="ly-actions">
        <form method="post" action="<?= e(url('/admin/integration/layer/active')) ?>"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><input type="hidden" name="active" value="<?= $l['active'] ? '0' : '1' ?>">
          <?php if ($l['active']): ?>
            <button class="btn btn-sm btn-light" data-confirm="Désactiver « <?= e($l['name']) ?> » ? Le widget reprendra l'affichage par défaut (sous la barre de recherche).">Désactiver le layer</button>
          <?php else: ?>
            <button class="btn btn-sm btn-light" data-confirm="Activer « <?= e($l['name']) ?> » ? Il remplacera le layer actif sur le site.">Activer le layer</button>
          <?php endif; ?>
        </form>
        <a class="btn btn-sm<?= $isEdit ? '' : ' btn-light' ?>" href="<?= e(url('/admin/integration', ['layer' => $l['id']])) ?>#ly-config"<?= $isEdit ? ' aria-current="true"' : '' ?>>Configuration</a>
        <?php if (!$l['active']): ?>
          <form method="post" action="<?= e(url('/admin/integration/layer/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
            <button class="icon-btn" title="Supprimer" aria-label="Supprimer « <?= e($l['name']) ?> »" data-confirm="Supprimer le layer « <?= e($l['name']) ?> » ?"><?= admin_icon('trash') ?></button></form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$layers): ?>
    <div class="card muted">Aucun layer : le widget s'affiche sous la barre de recherche, avec les réglages du code du site.</div>
  <?php endif; ?>
</div>
<p><button type="button" class="btn btn-light" data-dialog-open="ly-dialog">Créer un nouveau layer</button></p>

<?php if ($edit): $s = $edit['settings']; ?>
<form method="post" action="<?= e(url('/admin/integration/layer/save')) ?>" class="card form" id="ly-config" data-layer-form>
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
  <h3>Configuration de « <?= e($edit['name']) ?> »</h3>
  <div class="grid2">
    <label>Nom <input name="name" value="<?= e($edit['name']) ?>" maxlength="100" required></label>
    <label>Type
      <select name="type" data-layer-type>
        <?php foreach ($types as $k => [$label, $help]): ?><option value="<?= e($k) ?>" data-help="<?= e($help) ?>" <?= $edit['type'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
      <small class="muted" data-layer-type-help><?= e($types[$edit['type']][1]) ?></small>
    </label>
  </div>
  <div class="toggle-row">
    <label class="toggle">
      <input type="checkbox" role="switch" name="url_state" value="1" <?= $s['url_state'] ? 'checked' : '' ?>>
      <span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span>
      <span class="toggle-text"><strong>Garder la recherche dans l'adresse de la page</strong>
        <small class="muted">Si la page est rechargée, la fenêtre se rouvre avec la même recherche, les mêmes filtres et le même tri (ajout de <code>#df=…</code> à l'adresse).
          Si une fonctionnalité de votre site dépend des ancres d'URL, laissez ce réglage désactivé.</small></span>
    </label>
  </div>
  <label>Sélecteur CSS du champ de recherche
    <input name="input" value="<?= e($s['input']) ?>" placeholder="#search" class="code">
    <small class="muted">Par exemple <code>#search</code> ou <code>input[name="q"]</code> : le ou les champs de votre site dans lesquels les visiteurs tapent leur recherche.
      Vide : celui indiqué dans le code du site (<code>data-input</code>).</small>
  </label>
  <label data-layer-only="embedded">Sélecteur CSS du bloc d'affichage
    <input name="container" value="<?= e($s['container']) ?>" placeholder="main#maincontent" class="code">
    <small class="muted">Bloc de votre page dans lequel les résultats s'affichent à la place de son contenu, tant qu'une recherche est en cours.</small>
  </label>
  <label data-layer-only="below">Écart sous la barre de recherche (pixels)
    <input name="gap" type="number" min="0" max="80" value="<?= (int) $s['gap'] ?>">
  </label>
  <div class="form-foot"><button class="btn">Enregistrer</button></div>
</form>
<?php endif; ?>

<dialog id="ly-dialog" class="modal" aria-labelledby="ly-title">
  <form method="post" action="<?= e(url('/admin/integration/layer/create')) ?>" class="form modal-form">
    <?= csrf_field() ?>
    <div class="modal-head">
      <div class="modal-title"><h2 id="ly-title">Créer un nouveau layer</h2></div>
      <button type="button" class="icon-btn modal-close" data-dialog-close aria-label="Fermer">✕</button>
    </div>
    <div class="modal-body">
      <p><strong>Choisissez un type de layer pour votre boutique</strong><br><span class="muted">Chaque type définit où la recherche apparaît et comment les acheteurs y accèdent.</span></p>
      <div class="ly-types" role="radiogroup" aria-label="Type de layer">
        <?php foreach ($types as $k => [$label, $help]): ?>
          <label class="ly-type">
            <input type="radio" name="type" value="<?= e($k) ?>" <?= $k === 'below' ? 'checked' : '' ?>>
            <span class="ly-pic ly-pic-<?= e($k) ?>" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></span>
            <strong><?= e($label) ?></strong>
            <small class="muted"><?= e($help) ?></small>
          </label>
        <?php endforeach; ?>
      </div>
      <label>Nom <input name="name" maxlength="100" placeholder="ex. : Cabesto"></label>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-light" data-dialog-close>Retour</button>
      <button class="btn">Créer le layer</button>
    </div>
  </form>
</dialog>

<?php elseif ($tab === 'filters'): ?>
<p class="muted">Choisissez les filtres proposés aux visiteurs à côté des résultats, leur nom et leur ordre (faites glisser les lignes, ou utilisez les flèches).
  Les filtres d'attributs apparaissent ici après un import. Langue : <strong><?= e(strtoupper($lang)) ?></strong>.</p>
<form method="post" action="<?= e(url('/admin/integration/filters')) ?>" class="card" data-order-form="filters">
  <?= csrf_field() ?><input type="hidden" name="payload" value="">
  <input type="search" class="ly-search" placeholder="Rechercher" aria-label="Rechercher un filtre" data-order-search>
  <table class="table ly-table">
    <thead><tr><th class="ly-grip-col"></th><th>Nom affiché</th><th>Champ</th><th>Apparence</th><th>Statut</th></tr></thead>
    <tbody data-order-list>
    <?php foreach ($filters as $f): ?>
      <tr draggable="true" data-row data-code="<?= e($f['code']) ?>">
        <td class="ly-grip"><span class="ly-handle" title="Faire glisser pour déplacer"><?= admin_icon('grip-vertical') ?></span>
          <button type="button" class="icon-btn icon-btn-xs" data-move="-1" aria-label="Monter « <?= e($f['label']) ?> »">↑</button><button type="button" class="icon-btn icon-btn-xs" data-move="1" aria-label="Descendre « <?= e($f['label']) ?> »">↓</button></td>
        <td><input data-f="label" value="<?= e($f['label']) ?>" placeholder="<?= e($f['default_label']) ?>" maxlength="60" aria-label="Nom affiché"></td>
        <td><code><?= e($f['code']) ?></code></td>
        <td><?= e(WidgetConfig::APPEARANCES[$f['appearance']]) ?></td>
        <td><label class="toggle toggle-sm"><input type="checkbox" role="switch" data-f="visible" <?= $f['visible'] ? 'checked' : '' ?> aria-label="Afficher « <?= e($f['label']) ?> »"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted small">« En stock » et « En promotion » s'affichent en haut des résultats (ordinateur) ; leur ordre ne change pas. Un filtre masqué n'est plus proposé.</p>
  <div class="form-foot"><button class="btn">Enregistrer</button></div>
</form>

<?php else: ?>
<p class="muted">Choisissez les tris proposés dans le menu « Trier » du widget, leur ordre et leur nom. « Pertinence » est toujours proposé en premier.
  Langue : <strong><?= e(strtoupper($lang)) ?></strong>.</p>
<form method="post" action="<?= e(url('/admin/integration/sorts')) ?>" class="card" data-order-form="sorts">
  <?= csrf_field() ?><input type="hidden" name="payload" value="">
  <table class="table ly-table">
    <thead><tr><th class="ly-grip-col"></th><th>Tri</th><th>Nom personnalisé</th><th></th></tr></thead>
    <tbody>
      <tr><td></td><td><strong>Pertinence</strong></td><td><input data-relevance value="<?= e($sorts['relevance']) ?>" maxlength="60" aria-label="Nom du tri par pertinence"></td><td></td></tr>
    </tbody>
    <tbody data-order-list>
    <?php foreach ($sorts['items'] as $s): ?>
      <tr draggable="true" data-row>
        <td class="ly-grip"><span class="ly-handle" title="Faire glisser pour déplacer"><?= admin_icon('grip-vertical') ?></span>
          <button type="button" class="icon-btn icon-btn-xs" data-move="-1" aria-label="Monter">↑</button><button type="button" class="icon-btn icon-btn-xs" data-move="1" aria-label="Descendre">↓</button></td>
        <td><select data-f="key" aria-label="Tri">
          <?php foreach (WidgetConfig::SORTS as $k => [$fr]): ?><option value="<?= e($k) ?>" <?= $s['key'] === $k ? 'selected' : '' ?>><?= e($fr) ?></option><?php endforeach; ?>
        </select></td>
        <td><input data-f="label" value="<?= e($s['label']) ?>" maxlength="60" aria-label="Nom personnalisé"></td>
        <td><button type="button" class="icon-btn" data-remove aria-label="Retirer ce tri" title="Retirer"><?= admin_icon('trash') ?></button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <template data-row-template>
    <tr draggable="true" data-row>
      <td class="ly-grip"><span class="ly-handle" title="Faire glisser pour déplacer"><?= admin_icon('grip-vertical') ?></span>
        <button type="button" class="icon-btn icon-btn-xs" data-move="-1" aria-label="Monter">↑</button><button type="button" class="icon-btn icon-btn-xs" data-move="1" aria-label="Descendre">↓</button></td>
      <td><select data-f="key" aria-label="Tri">
        <?php foreach (WidgetConfig::SORTS as $k => [$fr]): ?><option value="<?= e($k) ?>"><?= e($fr) ?></option><?php endforeach; ?>
      </select></td>
      <td><input data-f="label" maxlength="60" aria-label="Nom personnalisé"></td>
      <td><button type="button" class="icon-btn" data-remove aria-label="Retirer ce tri" title="Retirer"><?= admin_icon('trash') ?></button></td>
    </tr>
  </template>
  <p><button type="button" class="btn btn-sm btn-light" data-add-row>+ Ajouter un tri</button></p>
  <p class="muted small">Le tri par nom demande une mise à jour du moteur, lancée à l'enregistrement (quelques minutes sur un gros catalogue).</p>
  <div class="form-foot"><button class="btn">Enregistrer</button></div>
</form>
<?php endif; ?>

<details class="card ly-code"<?= $layers ? '' : ' open' ?>>
  <summary><strong>Code à ajouter sur le site (Magento)</strong></summary>
  <p>Ajoutez ce code avant <code>&lt;/body&gt;</code>, par exemple dans <em>Contenu › Design › Configuration › HTML Head › Scripts et feuilles de style</em> ou dans un bloc du thème.
    Les clés se gèrent dans <a href="<?= e(url('/admin/keys')) ?>">Clés</a>.</p>
  <pre class="code">&lt;script src="<?= e($base) ?>/widget/deepfind.js?v=8" defer
  data-key="<?= e($firstKey !== '' ? $firstKey : 'pk_VOTRE_CLE') ?>"
  data-lang="fr"
  data-input="#search"
  data-results-url="https://www.cabesto.com/fr/catalogsearch/result/?q="&gt;&lt;/script&gt;</pre>
  <p class="muted small">Le layer actif (type, champ de recherche, bloc d'affichage), les filtres et les tris s'appliquent sans modifier ce code.
    Le sélecteur du champ choisi dans le layer remplace <code>data-input</code>.</p>
  <table class="table compact">
    <thead><tr><th>Attribut</th><th>Rôle</th></tr></thead>
    <tbody>
      <tr><td><code>data-key</code></td><td>Clé publique (obligatoire)</td></tr>
      <tr><td><code>data-lang</code></td><td>Langue des résultats (fr par défaut)</td></tr>
      <tr><td><code>data-input</code></td><td>Sélecteur CSS du ou des champs de recherche du site (si le layer actif n'en indique pas)</td></tr>
      <tr><td><code>data-gap</code></td><td>Layer « Sous la barre » sans layer actif : écart en pixels entre la barre de recherche et la fenêtre (12 par défaut)</td></tr>
      <tr><td><code>data-position="anchor"</code> + <code>data-anchor</code></td><td>Layer « Sous la barre » : ouvrir plutôt la fenêtre sous un élément du site (ex. <code>header.page-header</code>)</td></tr>
      <tr><td><code>data-enter</code></td><td><code>off</code> : la touche Entrée laisse seulement la fenêtre DeepFind ; <code>on</code> : elle ouvre la page de résultats. Sans attribut : réglage <a href="<?= e(url('/admin/settings')) ?>">Paramètres › Widget sur le site</a></td></tr>
      <tr><td><code>data-results-url</code></td><td>Page « Tout voir » (la requête est ajoutée à la fin). Vide = bouton masqué</td></tr>
      <tr><td><code>data-currency</code>, <code>data-locale</code></td><td>Format des prix (EUR, fr-FR par défaut)</td></tr>
      <tr><td><code>data-min-chars</code>, <code>data-page-size</code></td><td>Caractères avant recherche (2), produits par page (20)</td></tr>
      <tr><td><code>data-native</code></td><td>Par défaut, l'autocomplétion d'origine du site (Magento…) est neutralisée pour ne pas s'ouvrir en même temps. <code>keep</code> = la laisser fonctionner</td></tr>
      <tr><td><code>data-hide</code></td><td>Sélecteurs CSS supplémentaires de panneaux d'origine à masquer (ex. <code>.mon-popup-recherche</code>)</td></tr>
    </tbody>
  </table>
</details>
<script src="<?= e(asset('/assets/integration.js')) ?>"></script>
