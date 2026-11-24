<?php
/** @var array $c @var array $fields @var array $urlOps @var array $items @var ?array $stats @var array $top @var string $personal @var array $searchTpl */
use DeepFind\Rules\Carousels;
use DeepFind\Rules\Popins;
?>
<p><a href="<?= e(url('/admin/carousels')) ?>" class="cr-back">‹ Retour aux carrousels</a></p>

<form method="post" action="<?= e(url('/admin/carousels/save')) ?>" class="form cr-form ca-form" data-carousel-form
      data-carousel="<?= e(\DeepFind\Text::json($c)) ?>" data-items="<?= e(\DeepFind\Text::json($items)) ?>" data-fields="<?= e(\DeepFind\Text::json($fields)) ?>"
      data-url-ops="<?= e(\DeepFind\Text::json($urlOps)) ?>" data-values-url="<?= e(url('/admin/boost-rules/values')) ?>"
      data-search-url="<?= e(url('/admin/custom-results/search')) ?>" data-frame-url="<?= e(url('/admin/live-preview', ['type' => 'carousel'])) ?>" data-preview-url="<?= e(url('/admin/carousels/preview')) ?>"
      data-csrf="<?= e(\DeepFind\Admin\Auth::csrf()) ?>">
  <?= csrf_field() ?><input type="hidden" name="payload" value="">

  <div class="cr-head">
    <input class="cr-name" data-f="name" maxlength="190" placeholder="Écrire le nom interne (ex. Accueil – meilleures ventes)" aria-label="Nom interne" required>
    <?php if ($stats): ?>
      <div class="cr-kpis" title="30 derniers jours">
        <div><span class="kpi-label">Vues</span><span class="kpi-value"><?= fmt_n($stats['views']) ?></span></div>
        <div><span class="kpi-label">Clics</span><span class="kpi-value"><?= fmt_n($stats['clicks']) ?></span></div>
        <div><span class="kpi-label">CTR</span><span class="kpi-value"><?= $stats['ctr'] === null ? '—' : fmt_n($stats['ctr'], 1) . ' %' ?></span></div>
        <small class="muted">30 derniers jours</small>
      </div>
    <?php endif; ?>
  </div>

  <div class="card cr-card">
    <div class="cr-row">
      <strong>Statut</strong>
      <label class="toggle"><input type="checkbox" role="switch" data-f="active" aria-label="Carrousel actif"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
    </div>

    <?php $withTerms = false; $withPeriod = true; include __DIR__ . '/_terms_period.php'; ?>

    <div class="cr-section">
      <?php $pagesNoun = 'de carrousel'; $pagesWho = 'Le carrousel s\'affiche'; include __DIR__ . '/_pages_fields.php'; ?>
    </div>

    <div class="cr-section">
      <strong>2. Emplacement sur la page</strong>
      <p class="muted small">Le bloc de la page où insérer le carrousel, désigné par son <strong>identifiant</strong> (<code>#accueil-selection</code>) ou sa <strong>classe</strong>
        (<code>.category-products</code>). Pour le trouver : clic droit sur la zone de la page › <em>Inspecter</em>, puis relevez l'attribut <code>id</code> ou <code>class</code>.
        Le plus fiable : demandez à ajouter dans le thème un bloc vide dédié, ex. <code>&lt;div id="deepfind-carrousel"&gt;&lt;/div&gt;</code>.</p>
      <div class="grid2 ca-grid">
        <label>Sélecteur CSS du bloc
          <input data-f="selector" maxlength="300" placeholder="#deepfind-carrousel" spellcheck="false" autocomplete="off"></label>
        <label>Position
          <select data-f="insert_mode"><?php foreach (Carousels::INSERTS as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label>Appareils
          <select data-f="device"><?php foreach (Popins::DEVICES as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
      </div>
      <small class="muted">Si le bloc n'existe pas sur une page, le carrousel n'y est pas affiché (le widget l'attend 10 secondes, le temps que la page se construise).</small>
    </div>

    <div class="cr-section">
      <strong>3. Titre et lien</strong>
      <div class="grid2 ca-grid">
        <label>Titre affiché <small class="muted">(facultatif)</small>
          <input data-f="title" maxlength="190" placeholder="ex. Nos meilleures ventes"></label>
        <label>Lien « Voir tout » <small class="muted">(facultatif)</small>
          <input data-f="more_url" maxlength="500" placeholder="https://www.example.com/promotions.html ou /promotions.html"></label>
        <label>Texte du lien
          <input data-f="more_label" maxlength="60" placeholder="Voir tout"></label>
      </div>
    </div>

    <div class="cr-section" data-rule-root>
      <strong>4. Produits</strong>
      <?php foreach (Carousels::SOURCES as $v => $l): ?>
        <label class="check"><input type="radio" name="ca_source" value="<?= e($v) ?>"><span><?= e($l) ?></span></label>
      <?php endforeach; ?>

      <div class="ca-sub" data-show="similar">
        <label>Produits similaires : <select data-f="similar_by"><?php foreach (Carousels::SIMILAR as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
        <small class="muted">Sur une fiche produit : produits de sa catégorie la plus précise et/ou de sa marque. Le produit de la page est reconnu par son adresse ;
          pour plus de fiabilité, le site peut l'indiquer : <code>&lt;meta name="deepfind:sku" content="SKU-DU-PRODUIT"&gt;</code>. Ailleurs (pas de produit dans la page) :
          sélection automatique ci-dessous. Nécessite un import complet après la mise à jour (adresse des produits indexée).</small>
      </div>
      <div class="ca-sub" data-show="personal">
        <?php foreach (Carousels::PERSONAL as $v => $l): ?>
          <label class="check"><input type="radio" name="ca_personal" value="<?= e($v) ?>"><span><?= e($l) ?></span></label>
        <?php endforeach; ?>
        <small class="muted">Historique gardé dans le navigateur du visiteur (30 jours, 20 produits), jamais enregistré par DeepFind. Réglage actuel :
          <strong><?= e(Carousels::PERSONAL_MODES[$personal]) ?></strong> (<a href="<?= e(url('/admin/carousels')) ?>#ca-personal">modifier</a>).
          Sans historique ou sans consentement : sélection automatique ci-dessous.</small>
      </div>

      <div class="ca-block">
        <strong class="ca-block-title" data-items-title>Produits épinglés en tête <small class="muted">(facultatif, dans cet ordre)</small></strong>
        <div class="ca-picker">
          <input type="search" data-item-search placeholder="Rechercher un produit (nom, SKU, marque…)" aria-label="Rechercher un produit à ajouter" autocomplete="off">
          <div class="ca-results" data-item-results hidden></div>
        </div>
        <ol class="ca-items" data-items-list></ol>
        <small class="muted">Faites glisser les produits pour changer leur ordre. Un produit en rupture n'est pas affiché si l'option « en stock uniquement » est cochée.</small>
      </div>

      <div class="ca-block" data-hide="manual">
        <strong class="ca-block-title">Limiter aux produits qui… <small class="muted">(facultatif)</small></strong>
        <label class="check"><input type="radio" name="pr_match" value="all"><span>remplissent <strong>toutes</strong> les conditions</span></label>
        <label class="check"><input type="radio" name="pr_match" value="any"><span>remplissent <strong>au moins une</strong> condition</span></label>
        <div class="br-conds" data-conds></div>
        <button type="button" class="btn btn-light br-add-cond" data-add-cond>+ Ajouter une condition</button>
        <div class="grid2 ca-grid">
          <label>Tri
            <select data-f="sort_by"><?php foreach (Carousels::SORTS as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
        </div>
        <label class="check ca-learning"><input type="checkbox" data-f="learning"><span><strong>Apprentissage</strong> : faire remonter les produits les plus cliqués <em>dans ce carrousel</em>
          par rapport à leur nombre d'affichages (30 derniers jours). Un produit encore peu montré garde sa chance.</span></label>
      </div>
      <div class="grid2 ca-grid">
        <label>Nombre de produits (2 à 48)
          <input data-f="max_items" type="number" min="2" max="48" step="1" inputmode="numeric"></label>
      </div>
      <label class="check"><input type="checkbox" data-f="in_stock_only"><span>Produits en stock uniquement</span></label>
    </div>

    <div class="cr-section">
      <strong>5. Affichage</strong>
      <div class="grid2 ca-grid">
        <label>Produits visibles à la fois sur ordinateur (1 à 8)
          <input data-f="per_view_desktop" type="number" min="1" max="8" step="1" inputmode="numeric"></label>
        <label>Produits visibles à la fois sur mobile (1 à 4)
          <input data-f="per_view_mobile" type="number" min="1" max="4" step="1" inputmode="numeric">
          <small class="muted">Le bord du produit suivant dépasse, pour montrer qu'on peut faire défiler.</small></label>
        <label>Défilement automatique (secondes, 0 = non)
          <input data-f="autoplay" type="number" min="0" max="60" step="1" inputmode="numeric">
          <small class="muted">En pause au survol, au toucher et hors de l'écran ; désactivé pour les visiteurs qui limitent les animations.</small></label>
      </div>
      <p class="muted small">Les cartes produit reprennent le style de la fenêtre de recherche (modèle de carte et couleurs du layer actif, Paramètres › Intégration).
        Les visiteurs font défiler au doigt, à la souris (glisser) ou avec les flèches.</p>
      <div class="ca-tpl" data-card-tpl data-default-desktop="<?= e($searchTpl[0]) ?>" data-default-mobile="<?= e($searchTpl[1]) ?>">
        <div class="bn-code-head">
          <span>Modèle de carte produit <small>(HTML)</small></span>
          <span class="ca-tpl-tools">
            <span class="lv-switch" role="group" aria-label="Version du modèle">
              <button type="button" class="lv-sw" data-tpl-tab="desktop" aria-pressed="true">Ordinateur</button>
              <button type="button" class="lv-sw" data-tpl-tab="mobile" aria-pressed="false">Mobile</button>
            </span>
            <button type="button" class="btn btn-sm btn-light" data-tpl-reset title="Remettre le modèle de la fenêtre de recherche dans l'onglet ouvert">↻ Revenir au modèle par défaut</button>
          </span>
        </div>
        <div class="tpl-wrap" aria-hidden="true"><code>&lt;a class="df-card" href="{{url}}"&gt;</code><span>ajouté automatiquement : toute la carte mène à la page produit</span></div>
        <textarea data-f="card_template" class="bn-code-area" rows="14" spellcheck="false" data-tpl-device="desktop" aria-label="Modèle de carte produit, ordinateur"></textarea>
        <textarea data-f="card_template_mobile" class="bn-code-area" rows="14" spellcheck="false" data-tpl-device="mobile" hidden aria-label="Modèle de carte produit, mobile"
          placeholder="Vide : le modèle ordinateur s'applique aussi sur mobile."></textarea>
        <div class="tpl-wrap tpl-wrap-end" aria-hidden="true"><code>&lt;/a&gt;</code></div>
        <small class="muted">Par défaut : le modèle de la fenêtre de recherche (Paramètres › Intégration, layer actif). Modifiez-le pour ce carrousel seulement,
          puis vérifiez avec « Aperçu ordinateur / mobile ». HTML simple (div, span, p, strong, img…), sans script ni lien (la carte entière mène au produit) ;
          un élément de classe <code>df-if-</code><em>variable</em> n'est affiché que si la variable n'est pas vide.</small>
        <details class="ca-vars"><summary>Variables disponibles</summary>
          <ul class="lp-vars"><?php foreach (\DeepFind\Widget\WidgetConfig::CARD_VARS as $v => $help): ?><li><code>{{<?= e($v) ?>}}</code> <span class="muted"><?= e($help) ?></span></li><?php endforeach; ?></ul>
        </details>
      </div>
      <div class="bn-code-head"><span>CSS personnalisé <small>(facultatif)</small></span></div>
      <textarea data-f="custom_css" class="bn-code-area" rows="6" spellcheck="false" placeholder=".df-carousel-<?= $c['id'] ?: 'N' ?> .df-car-title { font-size: 26px; }&#10;.df-carousel-<?= $c['id'] ?: 'N' ?> { --df-accent: #e4003a; background: #f6f7fb; padding: 16px; }"></textarea>
      <small class="muted">Visez <code>.df-carousel-<?= $c['id'] ?: 'N' ?></code> (ce carrousel) ou <code>.df-carousel</code> (tous) ; variables : <code>--df-accent</code>, <code>--df-text</code>, <code>--df-gap</code>.
        Inséré tel quel sur le site (balises et imports retirés).</small>
    </div>
  </div>

  <div class="card ca-preview">
    <div class="ca-preview-head">
      <strong>Liste des produits sélectionnés (contrôle)</strong>
      <button type="button" class="btn btn-sm btn-light" data-live-preview-alt><?= admin_icon('eye') ?> Voir le rendu réel (ordinateur / mobile)</button>
    </div>
    <p class="muted small">Vérifiez <strong>quels produits</strong> sortent et <strong>dans quel ordre</strong> (numéro = position, SKU pour les identifier), avec les réglages ci-dessus
      même non enregistrés. Ce n'est pas le rendu du site : pour voir les cartes telles que le visiteur les verra (modèle de carte, couleurs, format ordinateur ou mobile),
      utilisez « Voir le rendu réel ». La page, le SKU et l'historique saisis ici servent aussi à ce rendu.</p>
    <div class="ca-preview-form">
      <label>Page du site <input data-preview-page value="/" placeholder="/fr/palmes/c4-predator.html"></label>
      <label>SKU du produit de la page <small class="muted">(facultatif)</small> <input data-preview-sku placeholder="C4-PREDATOR"></label>
      <label>Historique de test <small class="muted">(SKU séparés par des virgules)</small> <input data-preview-history placeholder="C4-PREDATOR, CRESSI-F1"></label>
      <button type="button" class="btn btn-light" data-preview-run>Voir les produits</button>
    </div>
    <p class="muted small" data-preview-note></p>
    <div class="ca-preview-list" data-preview-list></div>
  </div>

  <?php if ($top): ?>
    <div class="card ca-top">
      <strong>Produits les plus vus dans ce carrousel</strong> <small class="muted">(30 derniers jours)</small>
      <div class="table-scroll"><table class="table">
        <thead><tr><th>Produit</th><th class="num">Vues</th><th class="num">Clics</th><th class="num">CTR</th></tr></thead>
        <tbody><?php foreach ($top as $t): ?>
          <tr><td><?= e($t['name'] !== '' ? $t['name'] : $t['product_id']) ?><?php if ($t['sku'] !== ''): ?> <small class="muted"><?= e($t['sku']) ?></small><?php endif; ?></td>
            <td class="num"><?= fmt_n($t['views']) ?></td><td class="num"><?= fmt_n($t['clicks']) ?></td><td class="num"><?= $t['ctr'] === null ? '—' : fmt_n($t['ctr'], 1) . ' %' ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </div>
  <?php endif; ?>

  <div class="cr-foot">
    <?php if ($c['id']): ?><button class="btn btn-danger cr-delete-btn" form="cr-delete-form" data-confirm="Supprimer le carrousel « <?= e($c['name']) ?> » et ses statistiques ?"><?= admin_icon('trash') ?> Supprimer ce carrousel</button><?php endif; ?>
    <button type="button" class="btn btn-light lv-open" data-live-preview><?= admin_icon('eye') ?> Aperçu ordinateur / mobile</button>
    <a class="btn btn-light" href="<?= e(url('/admin/carousels')) ?>">Annuler</a>
    <button class="btn">Enregistrer</button>
  </div>
</form>
<?php if ($c['id']): ?>
  <form method="post" action="<?= e(url('/admin/carousels/delete')) ?>" id="cr-delete-form" hidden><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"></form>
<?php endif; ?>
<script src="<?= e(asset('/assets/editor-tools.js')) ?>"></script>
<script src="<?= e(asset('/assets/live-preview.js')) ?>"></script>
<script src="<?= e(asset('/assets/conditions.js')) ?>"></script>
<script src="<?= e(asset('/assets/carousels.js')) ?>"></script>
