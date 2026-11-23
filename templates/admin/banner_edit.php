<?php
/** @var array $b @var int $maxKb @var ?array $stats */
?>
<p><a href="<?= e(url('/admin/banners')) ?>" class="cr-back">‹ Retour aux bannières</a></p>

<form method="post" action="<?= e(url('/admin/banners/save')) ?>" class="form cr-form" data-banner-form data-max-kb="<?= $maxKb ?>"
      data-banner="<?= e(\DeepFind\Text::json($b)) ?>" data-upload-url="<?= e(url('/admin/banners/upload')) ?>" data-csrf="<?= e(\DeepFind\Admin\Auth::csrf()) ?>">
  <?= csrf_field() ?><input type="hidden" name="payload" value="">

  <div class="cr-head">
    <input class="cr-name" data-f="name" maxlength="190" placeholder="Écrire le nom interne" aria-label="Nom interne" required>
    <?php if ($stats): ?>
      <div class="cr-kpis" title="30 derniers jours">
        <div><span class="kpi-label">Affichages</span><span class="kpi-value"><?= fmt_n($stats['impressions']) ?></span></div>
        <div><span class="kpi-label">Clics</span><span class="kpi-value"><?= fmt_n($stats['clicks']) ?></span></div>
        <div><span class="kpi-label">CTR</span><span class="kpi-value"><?= $stats['ctr'] === null ? '—' : fmt_n($stats['ctr'], 1) . ' %' ?></span></div>
        <small class="muted">30 derniers jours</small>
      </div>
    <?php endif; ?>
  </div>

  <div class="card cr-card">
    <div class="cr-row">
      <strong>Statut</strong>
      <label class="toggle"><input type="checkbox" role="switch" data-f="active" aria-label="Statut actif"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
    </div>
    <div class="cr-row">
      <div><strong>Bannière par défaut</strong><br><small class="muted">Affichée lorsqu'aucune autre bannière ne correspond aux termes de recherche.</small></div>
      <label class="toggle"><input type="checkbox" role="switch" data-f="is_default" aria-label="Bannière par défaut"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
    </div>

    <?php $withTerms = false; include __DIR__ . '/_terms_period.php'; ?>
    <?php $withTerms = true; $withPeriod = false; $termsHelp = 'Exacte : la recherche est exactement ce terme. Large : la recherche contient tous les mots du terme. Facultatif pour une bannière par défaut.'; include __DIR__ . '/_terms_period.php'; ?>

    <div class="cr-section">
      <strong>Bannière</strong>
      <?php $mediaSizes = ['desktop' => ['Ordinateur', '1200 à 1600 px de large, 150 à 300 px de haut'], 'mobile' => ['Mobile', '640 à 800 px de large, 100 à 200 px de haut']];
      include __DIR__ . '/_media_fields.php'; ?>
    </div>
  </div>

  <div class="cr-foot">
    <a class="btn btn-light" href="<?= e(url('/admin/banners')) ?>">Annuler</a>
    <button class="btn">Enregistrer</button>
  </div>
</form>
<?php if ($b['id']): ?>
  <form method="post" action="<?= e(url('/admin/banners/delete')) ?>" class="cr-delete"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
    <button class="btn btn-sm btn-danger" data-confirm="Supprimer la bannière « <?= e($b['name']) ?> » ?">Supprimer cette bannière</button></form>
<?php endif; ?>
<script src="<?= e(asset('/assets/editor-tools.js')) ?>"></script>
<script src="<?= e(asset('/assets/banners.js')) ?>"></script>
