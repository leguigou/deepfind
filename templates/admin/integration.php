<?php
/** @var string $tab @var string $lang @var array $layers @var array $filters @var array $addable @var array $sorts @var string $firstKey @var string $appUrl */
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
  <?php foreach ($layers as $l): ?>
    <div class="card ly-card<?= $l['active'] ? ' ly-active' : '' ?>">
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
        <a class="btn btn-sm" href="<?= e(url('/admin/integration/layer', ['id' => $l['id']])) ?>">Configuration</a>
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
<p class="ly-buttons">
  <button type="button" class="btn btn-light" data-dialog-open="ly-dialog">Créer un nouveau layer</button>
  <button type="button" class="btn btn-light" data-dialog-open="test-dialog"><?= admin_icon('eye') ?> Tester DeepFind sur le site</button>
</p>

<dialog id="test-dialog" class="modal test-dialog" aria-labelledby="test-title" data-test-bookmark
  data-base="<?= e($base) ?>" data-version="<?= e((string) \DeepFind\Widget\WidgetConfig::WIDGET_VERSION) ?>" data-lang="<?= e($lang) ?>">
  <div class="modal-head">
    <div class="modal-title"><h2 id="test-title">Tester DeepFind sur le site</h2>
      <p class="muted small">Un favori à ajouter dans Chrome : sur une page du site, un clic affiche le widget avec le layer choisi.
        Seul votre navigateur voit le test ; rien ne change pour les visiteurs.</p></div>
    <button type="button" class="icon-btn modal-close" data-dialog-close aria-label="Fermer">✕</button>
  </div>
  <div class="modal-body">
    <?php if (!$testKeys): ?>
      <p class="flash flash-warn">Aucune clé active : créez-en une dans <a href="<?= e(url('/admin/keys')) ?>">Clés</a>, avec le domaine du site à tester.</p>
    <?php else: ?>
    <div class="grid2">
      <label>Layer à tester
        <select data-test-layer>
          <option value="">Layer actif du site (sans aperçu)</option>
          <?php foreach ($layers as $l): ?>
            <option value="<?= e($previewTokens[$l['id']] ?? '') ?>" <?= $l['active'] ? 'selected' : '' ?>><?= e($l['name']) ?> — <?= e($types[$l['type']][0]) ?><?= $l['active'] ? ' (actif)' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <small class="muted">Un layer inactif peut être testé : il n'est montré qu'à vous.</small>
      </label>
      <label>Clé utilisée
        <select data-test-key>
          <?php foreach ($testKeys as $k): ?>
            <option value="<?= e($k['public_key']) ?>"><?= e($k['name']) ?> — <?= e($k['allowed_origins'] !== '' ? $k['allowed_origins'] : 'tous les domaines') ?></option>
          <?php endforeach; ?>
        </select>
        <small class="muted">Le site testé doit faire partie des domaines autorisés de la clé.</small>
      </label>
    </div>

    <div class="test-drag">
      <a href="#" class="btn test-bookmark" data-test-link draggable="true" title="Glissez ce bouton dans la barre de favoris"><?= admin_icon('eye') ?> Tester DeepFind</a>
      <button type="button" class="btn btn-sm btn-light" data-test-copy><?= admin_icon('copy', 'key-copy') ?><?= admin_icon('check', 'key-copied') ?> Copier le lien du favori</button>
    </div>

    <ol class="test-steps">
      <li><strong>Affichez la barre de favoris de Chrome</strong> : <kbd>Ctrl</kbd> + <kbd>Maj</kbd> + <kbd>B</kbd> (sur Mac : <kbd>⌘</kbd> + <kbd>Maj</kbd> + <kbd>B</kbd>).</li>
      <li><strong>Glissez le bouton « Tester DeepFind »</strong> ci-dessus jusque dans la barre de favoris, puis relâchez.
        <br><span class="muted small">Si le glisser ne marche pas : cliquez sur « Copier le lien du favori », puis clic droit dans la barre de favoris › <em>Ajouter une page…</em>,
          nom « Tester DeepFind », et collez le lien dans le champ <em>URL</em>.</span></li>
      <li><strong>Ouvrez le site</strong> dans un onglet, par exemple <code>https://www.cabesto.com</code>.</li>
      <li><strong>Cliquez sur le favori « Tester DeepFind »</strong>. Un bandeau « Aperçu DeepFind » apparaît en bas à gauche.
        <br><span class="muted small">Si DeepFind est déjà installé sur le site, la page se recharge d'abord toute seule en mode aperçu.</span></li>
      <li><strong>Tapez une recherche</strong> dans la barre de recherche du site : le widget s'affiche avec le layer choisi.</li>
      <li><strong>Pour voir le rendu mobile</strong> : <kbd>F12</kbd>, puis l'icône téléphone (<kbd>Ctrl</kbd> + <kbd>Maj</kbd> + <kbd>M</kbd>) ; choisissez un modèle de téléphone, rechargez la page et cliquez de nouveau sur le favori.</li>
      <li><strong>Pour arrêter</strong> : bouton « Quitter l'aperçu » du bandeau, ou fermez l'onglet.</li>
    </ol>
    <p class="muted small">Le favori reste valable <?= \DeepFind\Widget\WidgetConfig::PREVIEW_DAYS ?> jours pour le layer choisi ; recréez-le ensuite (ou après un changement de layer à tester).
      Les modifications enregistrées dans le layer sont visibles en rechargeant la page du site. Si le site est déjà en aperçu, changer de page garde l'aperçu ;
      sinon, recliquez sur le favori après chaque changement de page. Si rien ne s'affiche, le site bloque peut-être les scripts extérieurs (politique de sécurité) :
      dites-le à l'équipe technique.</p>
    <?php endif; ?>
  </div>
  <div class="modal-foot"><button type="button" class="btn btn-light" data-dialog-close>Fermer</button></div>
</dialog>


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
<div class="page-actions">
  <p class="muted">Ajoutez les filtres proposés aux visiteurs à côté des résultats, choisissez leur nom, leur ordre (faites glisser les lignes)
    et le nombre de valeurs affichées. Langue : <strong><?= e(strtoupper($lang)) ?></strong>.</p>
  <button type="button" class="btn" data-dialog-open="flt-dialog">+ Ajouter un filtre</button>
</div>
<form method="post" action="<?= e(url('/admin/integration/filters')) ?>" class="card" data-order-form="filters">
  <?= csrf_field() ?><input type="hidden" name="payload" value=""><input type="hidden" name="removed" value="">
  <table class="table ly-table">
    <thead><tr><th class="ly-grip-col"></th><th>Nom affiché</th><th>Champ</th><th>Apparence</th><th>Valeurs affichées</th><th>Déplié</th><th>Statut</th><th></th></tr></thead>
    <tbody data-order-list>
    <?php foreach ($filters as $f): ?>
      <tr draggable="true" data-row data-code="<?= e($f['code']) ?>">
        <td class="ly-grip"><span class="ly-handle" title="Faire glisser pour déplacer"><?= admin_icon('grip-vertical') ?></span></td>
        <td><input data-f="label" value="<?= e($f['label']) ?>" placeholder="<?= e($f['default_label']) ?>" maxlength="60" aria-label="Nom affiché"></td>
        <td><code><?= e($f['code']) ?></code></td>
        <td><?= e(WidgetConfig::APPEARANCES[$f['appearance']]) ?></td>
        <td><?php if ($f['appearance'] === 'terms'): ?>
          <input data-f="limit" type="number" min="1" max="100" value="<?= (int) $f['limit'] ?>" class="flt-limit" aria-label="Valeurs affichées pour « <?= e($f['label']) ?> »">
        <?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><?php if ($f['appearance'] !== 'toggle'): ?>
          <label class="toggle toggle-sm" title="Bloc déplié par défaut (le visiteur peut le replier)"><input type="checkbox" role="switch" data-f="open" <?= $f['open'] ? 'checked' : '' ?> aria-label="« <?= e($f['label']) ?> » déplié par défaut"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
        <?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><label class="toggle toggle-sm"><input type="checkbox" role="switch" data-f="visible" <?= $f['visible'] ? 'checked' : '' ?> aria-label="Afficher « <?= e($f['label']) ?> »"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label></td>
        <td><button type="button" class="icon-btn icon-btn-sm" data-remove data-remove-save title="Retirer" aria-label="Retirer le filtre « <?= e($f['label']) ?> »"
          data-remove-confirm="Retirer le filtre « <?= e($f['label']) ?> » ? Il ne sera plus proposé aux visiteurs (vous pourrez l'ajouter de nouveau)."><?= admin_icon('trash') ?></button></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$filters): ?><tr><td colspan="8" class="muted">Aucun filtre : les visiteurs ne voient que les résultats. Ajoutez-en avec « Ajouter un filtre ».</td></tr><?php endif; ?>
    </tbody>
  </table>
  <p class="muted small">« Valeurs affichées » : nombre de valeurs visibles dans le filtre (catégories, marques…), les suivantes derrière « + N ». « Déplié » : bloc ouvert à l'affichage des résultats ; le visiteur peut toujours le replier ou le déplier.
    « En stock » et « En promotion » s'affichent en haut des résultats (ordinateur). Un filtre désactivé ou retiré n'est plus proposé.</p>
  <div class="form-foot"><button class="btn">Enregistrer</button></div>
</form>

<dialog id="flt-dialog" class="modal" aria-labelledby="flt-title">
  <form method="post" action="<?= e(url('/admin/integration/filters/add')) ?>" class="form modal-form" data-filter-add>
    <?= csrf_field() ?>
    <div class="modal-head">
      <div class="modal-title"><h2 id="flt-title">Ajouter un filtre</h2></div>
      <?php if ($addable): ?>
      <label class="toggle"><span class="toggle-text">Statut</span><input type="checkbox" role="switch" name="visible" value="1" checked><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
      <?php endif; ?>
      <button type="button" class="icon-btn modal-close" data-dialog-close aria-label="Fermer">✕</button>
    </div>
    <div class="modal-body">
      <?php if (!$addable): ?>
      <p class="flash flash-info">Tous les champs disponibles sont déjà dans la liste des filtres : il n'y a rien à ajouter.
        Pour proposer un nouvel attribut, cochez « filtrable » dans le <a href="<?= e(url('/admin/sources')) ?>">mapping des flux</a> puis lancez un import ;
        pour remettre un filtre retiré, il réapparaîtra ici.</p>
      <?php else: ?>
      <label>Champ
        <select name="code" required data-filter-code>
          <option value="" disabled selected>Sélectionner un champ</option>
          <?php
          $groups = ['Champs principaux' => static fn ($c) => !str_starts_with($c, 'attr.'), 'Attributs' => static fn ($c) => str_starts_with($c, 'attr.')];
          foreach ($groups as $glabel => $keep):
              $opts = array_filter($addable, $keep, ARRAY_FILTER_USE_KEY);
              if (!$opts) { continue; } ?>
            <optgroup label="<?= e($glabel) ?>">
              <?php foreach ($opts as $code => [$label, $appearance]): ?>
                <option value="<?= e($code) ?>" data-label="<?= e($label) ?>" data-appearance="<?= e($appearance) ?>" data-limit="<?= WidgetConfig::defaultLimit($code) ?>"><?= e($label) ?> (<?= e($code) ?>)</option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
        <small class="muted" data-filter-appearance>Les attributs disponibles sont ceux marqués « filtrables » dans le mapping des flux (après un import).</small>
      </label>
      <label>Nom affiché <input name="label" maxlength="60" placeholder="Nom vu par les visiteurs" data-filter-label></label>
      <label data-filter-limit-row hidden>Valeurs affichées
        <input name="limit" type="number" min="1" max="100" value="8" class="flt-limit" data-filter-limit>
        <small class="muted">Nombre de valeurs visibles ; les suivantes derrière « + N ».</small>
      </label>
      <div class="toggle-row" data-filter-open-row hidden>
        <label class="toggle"><input type="checkbox" role="switch" name="open" value="1" checked><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span>
          <span class="toggle-text"><strong>Déplié par défaut</strong><small class="muted">Désactivé : seul le titre du filtre s'affiche, le visiteur clique pour le déplier.</small></span></label>
      </div>
      <?php endif; ?>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-light" data-dialog-close><?= $addable ? 'Annuler' : 'Fermer' ?></button>
      <?php if ($addable): ?><button class="btn">Enregistrer</button><?php endif; ?>
    </div>
  </form>
</dialog>

<?php else:
  $sortRow = static function (?array $s) use ($lang): void {
      $f = $s['field'] ?? '';
      $type = $f !== '' ? WidgetConfig::SORT_FIELDS[$f][1] : '';
      $groups = ['Champs principaux' => true, 'Autres champs' => false]; ?>
      <tr draggable="true" data-row>
        <td class="ly-grip"><span class="ly-handle" title="Faire glisser pour déplacer"><?= admin_icon('grip-vertical') ?></span></td>
        <td><select data-f="field" aria-label="Champ" required>
          <option value="" disabled <?= $f === '' ? 'selected' : '' ?>>Sélectionner un champ</option>
          <?php foreach ($groups as $glabel => $main): ?>
            <optgroup label="<?= e($glabel) ?>">
            <?php foreach (WidgetConfig::SORT_FIELDS as $code => [$label, , $isMain]): if ($isMain !== $main) { continue; } ?>
              <option value="<?= e($code) ?>" <?= $f === $code ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select></td>
        <td><select data-f="dir" aria-label="Ordre" required <?= $type === '' ? 'disabled' : '' ?>>
          <option value="" disabled <?= $type === '' ? 'selected' : '' ?>>Sélectionner l'ordre</option>
          <?php foreach ($type !== '' ? WidgetConfig::SORT_DIRS[$type] : [] as $d => [$dlabel]): ?>
            <option value="<?= e($d) ?>" <?= ($s['dir'] ?? '') === $d ? 'selected' : '' ?>><?= e($dlabel) ?></option>
          <?php endforeach; ?>
        </select></td>
        <td><input data-f="label" value="<?= e($s['label'] ?? '') ?>" maxlength="60" aria-label="Nom affiché"
          placeholder="<?= e($f !== '' && isset($s['dir']) ? WidgetConfig::sortDefaultLabel($f, $s['dir'], $lang) : 'Saisir le nom d\'affichage') ?>"></td>
        <td><button type="button" class="icon-btn" data-remove aria-label="Retirer ce tri" title="Retirer"><?= admin_icon('trash') ?></button></td>
      </tr>
  <?php };
  $meta = ['fields' => array_map(static fn ($v) => [$v[0], $v[1]], WidgetConfig::SORT_FIELDS),
      'dirs' => array_map(static fn ($t) => array_map(static fn ($d) => [$d[0], $lang === 'en' ? $d[2] : $d[1]], $t), WidgetConfig::SORT_DIRS)];
?>
<p class="muted">Choisissez les tris proposés dans le menu « Trier » du widget : le champ, l'ordre, puis le nom affiché. Faites glisser les lignes pour changer leur ordre.
  « Pertinence » est toujours proposé en premier. Langue : <strong><?= e(strtoupper($lang)) ?></strong>.</p>
<form method="post" action="<?= e(url('/admin/integration/sorts')) ?>" class="card" data-order-form="sorts" data-sort-meta="<?= e(\DeepFind\Text::json($meta)) ?>">
  <?= csrf_field() ?><input type="hidden" name="payload" value="">
  <table class="table ly-table ly-sorts">
    <thead><tr><th class="ly-grip-col"></th><th>Champ</th><th>Ordre</th><th>Nom affiché</th><th></th></tr></thead>
    <tbody>
      <tr><td></td><td><strong>Pertinence</strong></td><td></td><td><input data-relevance value="<?= e($sorts['relevance']) ?>" maxlength="60" aria-label="Nom du tri par pertinence"></td><td></td></tr>
    </tbody>
    <tbody data-order-list>
    <?php foreach ($sortItems as $it) { $sortRow($it); } ?>
    </tbody>
  </table>
  <template data-row-template><?php $sortRow(null); ?></template>
  <p class="muted small" data-missing-label hidden>ⓘ Certains tris n'ont pas de nom personnalisé en <?= e(strtoupper($lang)) ?> : le nom grisé sera affiché.</p>
  <div class="ly-sorts-foot">
    <button type="button" class="btn btn-sm btn-light" data-add-row>+ Ajouter un champ</button>
    <button class="btn">Enregistrer</button>
  </div>
  <p class="muted small">Un nouveau champ de tri peut demander une mise à jour du moteur, lancée à l'enregistrement (quelques minutes sur un gros catalogue).</p>
</form>
<?php endif; ?>

<?php if ($tab === 'layers'): ?>
<details class="card ly-code"<?= $layers ? '' : ' open' ?>>
  <summary><strong>Code à ajouter sur le site</strong></summary>
  <p>Ajoutez ce code avant <code>&lt;/body&gt;</code>, par exemple dans <em>Contenu › Design › Configuration › HTML Head › Scripts et feuilles de style</em> ou dans un bloc du thème.
    Les clés se gèrent dans <a href="<?= e(url('/admin/keys')) ?>">Clés</a>.</p>
  <pre class="code">&lt;script src="<?= e($base) ?>/widget/deepfind.js?v=<?= \DeepFind\Widget\WidgetConfig::WIDGET_VERSION ?>" defer
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
      <tr><td><code>data-native</code></td><td>Autocomplétion d'origine du site (Magento…) : <code>keep</code> = la laisser fonctionner, <code>block</code> = la neutraliser. Sans attribut : réglage <a href="<?= e(url('/admin/settings')) ?>">Paramètres › Widget sur le site</a> (neutralisée par défaut)</td></tr>
      <tr><td><code>data-hide</code></td><td>Sélecteurs CSS supplémentaires de panneaux d'origine à masquer (ex. <code>.mon-popup-recherche</code>)</td></tr>
    </tbody>
  </table>
</details>
<?php endif; ?>
<script src="<?= e(asset('/assets/integration.js')) ?>"></script>
