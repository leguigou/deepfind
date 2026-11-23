<?php
/** @var array $p @var int $maxKb @var array $urlOps @var ?array $stats */
use DeepFind\Rules\Popins;
?>
<p><a href="<?= e(url('/admin/popins')) ?>" class="cr-back">‹ Retour aux popins</a></p>

<form method="post" action="<?= e(url('/admin/popins/save')) ?>" class="form cr-form pp-form" data-popin-form data-max-kb="<?= $maxKb ?>"
      data-popin="<?= e(\DeepFind\Text::json($p)) ?>" data-url-ops="<?= e(\DeepFind\Text::json($urlOps)) ?>"
      data-upload-url="<?= e(url('/admin/popins/upload')) ?>" data-csrf="<?= e(\DeepFind\Admin\Auth::csrf()) ?>">
  <?= csrf_field() ?><input type="hidden" name="payload" value="">

  <div class="cr-head">
    <input class="cr-name" data-f="name" maxlength="190" placeholder="Écrire le nom interne (ex. Soldes d'hiver)" aria-label="Nom interne" required>
    <?php if ($stats): ?>
      <div class="cr-kpis" title="30 derniers jours">
        <div><span class="kpi-label">Affichages</span><span class="kpi-value"><?= fmt_n($stats['views']) ?></span></div>
        <div><span class="kpi-label">Clics</span><span class="kpi-value"><?= fmt_n($stats['clicks']) ?></span></div>
        <div><span class="kpi-label">CTR</span><span class="kpi-value"><?= $stats['ctr'] === null ? '—' : fmt_n($stats['ctr'], 1) . ' %' ?></span></div>
        <div><span class="kpi-label">Fermetures</span><span class="kpi-value"><?= fmt_n($stats['closes']) ?></span></div>
        <small class="muted">30 derniers jours</small>
      </div>
    <?php endif; ?>
  </div>

  <div class="card cr-card">
    <div class="cr-row">
      <strong>Statut</strong>
      <label class="toggle"><input type="checkbox" role="switch" data-f="active" aria-label="Popin active"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
    </div>

    <?php $withTerms = false; $withPeriod = true; include __DIR__ . '/_terms_period.php'; ?>

    <div class="cr-section">
      <strong>1. Pages concernées</strong>
      <label class="check"><input type="radio" name="pp_pages" value="all"><span>Toutes les pages du site</span></label>
      <label class="check"><input type="radio" name="pp_pages" value="custom"><span>Seulement certaines pages</span></label>
      <div class="pp-pages" data-pages-custom>
        <label class="check"><input type="checkbox" data-f="home"><span>Page d'accueil <small class="muted">(« / » ou la racine d'une langue, ex. « /fr »)</small></span></label>
        <p class="muted small">Adresse de la page, sans le nom de domaine (ex. <code>/fr/nautisme/kayak.html</code>). Majuscules ignorées.
          La popin s'affiche si <strong>au moins une</strong> de ces conditions est remplie.</p>
        <div class="pr-urls" data-urls="urls"></div>
        <button type="button" class="btn btn-light btn-sm" data-add-url="urls">+ Ajouter une adresse</button>
      </div>
      <p class="muted small pp-excl-help"><strong>Pages exclues</strong> (facultatif) : jamais de popin sur ces pages, même si elles correspondent ci-dessus. Ex. contient <code>/checkout</code>, contient <code>/customer/</code>.</p>
      <div class="pr-urls" data-urls="exclude_urls"></div>
      <button type="button" class="btn btn-light btn-sm" data-add-url="exclude_urls">+ Ajouter une page exclue</button>
    </div>

    <div class="cr-section">
      <strong>2. Affichage</strong>
      <div class="grid2 pp-grid">
        <label>Appareils
          <select data-f="device"><?php foreach (Popins::DEVICES as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label>Position
          <select data-f="placement"><?php foreach (Popins::PLACEMENTS as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label>Délai avant l'affichage (secondes)
          <input data-f="delay_seconds" type="number" min="0" max="600" step="1" inputmode="numeric">
          <small class="muted">Compté à partir de l'ouverture de la page. 0 = tout de suite. Si la fenêtre de recherche est ouverte à ce moment-là, la popin attend qu'elle se referme.</small></label>
        <label>Largeur maximale (pixels)
          <input data-f="max_width" type="number" min="280" max="1200" step="10" inputmode="numeric">
          <small class="muted">Sur mobile, la popin prend au plus la largeur de l'écran.</small></label>
        <label>Hauteur minimale (pixels)
          <input data-f="min_height" type="number" min="0" max="1200" step="10" inputmode="numeric">
          <small class="muted">0 = selon le contenu. Utile pour un contenu HTML court. Jamais plus haute que l'écran.</small></label>
      </div>
    </div>

    <div class="cr-section">
      <strong>3. Fréquence</strong>
      <?php foreach (Popins::REPEATS as $v => $l): ?>
        <label class="check"><input type="radio" name="pp_repeat" value="<?= e($v) ?>"><span><?= e($l) ?></span></label>
      <?php endforeach; ?>
      <label class="pp-remember">Une fois fermée (ou cliquée), ne plus l'afficher au visiteur pendant
        <span class="pp-inline"><input data-f="remember_days" type="number" min="0" max="365" step="1" inputmode="numeric" aria-label="Nombre de jours"> jour(s)</span>
        <small class="muted">0 = jusqu'à la fin de sa visite seulement. Mémorisé dans le navigateur du visiteur (sans cookie ni donnée personnelle) : un autre navigateur, une navigation privée ou un effacement des données du site la feront réapparaître.</small></label>
      <?php if ($p['id']): ?>
        <label class="check"><input type="checkbox" data-f="reset_memory"><span>À l'enregistrement, la <strong>reproposer aux visiteurs qui l'ont déjà fermée</strong> <small class="muted">(utile après un changement de contenu)</small></span></label>
      <?php endif; ?>
    </div>

    <div class="cr-section">
      <strong>4. Contenu de la popin</strong>
      <?php $mediaSizes = ['desktop' => ['Ordinateur', '500 à 800 px de large'], 'mobile' => ['Mobile', '600 à 750 px de large, format portrait possible']];
      include __DIR__ . '/_media_fields.php'; ?>
    </div>
  </div>

  <div class="cr-foot">
    <a class="btn btn-light" href="<?= e(url('/admin/popins')) ?>">Annuler</a>
    <button class="btn">Enregistrer</button>
  </div>
</form>
<?php if ($p['id']): ?>
  <form method="post" action="<?= e(url('/admin/popins/delete')) ?>" class="cr-delete"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
    <button class="btn btn-sm btn-danger" data-confirm="Supprimer la popin « <?= e($p['name']) ?> » et ses statistiques ?">Supprimer cette popin</button></form>
<?php endif; ?>
<script src="<?= e(asset('/assets/editor-tools.js')) ?>"></script>
<script src="<?= e(asset('/assets/popins.js')) ?>"></script>
