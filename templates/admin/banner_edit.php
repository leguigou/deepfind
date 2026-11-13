<?php
/** @var array $b @var int $maxKb @var ?array $stats */
?>
<p><a href="<?= e(url('/admin/banners')) ?>" class="cr-back">‹ Retour aux bannières</a></p>

<form method="post" action="<?= e(url('/admin/banners/save')) ?>" class="form cr-form" data-banner-form
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
      <div class="bn-panel">
        <label class="bn-mode">Mode
          <select data-f="mode">
            <option value="visual">Configuration visuelle</option>
            <option value="code">Configuration par code</option>
          </select>
        </label>

        <div data-mode-panel="visual">
          <div class="grid2">
            <?php foreach (['desktop' => ['Ordinateur', '1200 à 1600 px de large, 150 à 300 px de haut'], 'mobile' => ['Mobile', '640 à 800 px de large, 100 à 200 px de haut']] as $k => [$label, $size]): ?>
              <div class="bn-slot" data-slot="<?= $k ?>">
                <strong><?= e($label) ?></strong>
                <div class="bn-drop" data-drop>
                  <img data-preview alt="" hidden>
                  <div class="bn-empty" data-empty>
                    <span class="bn-ico" aria-hidden="true">🖼</span>
                    <label class="btn btn-light bn-upload">Téléverser une image<input type="file" accept="image/jpeg,image/png,image/gif,image/webp" data-file hidden></label>
                    <small class="muted">ou glissez-déposez le fichier ici</small>
                  </div>
                  <button type="button" class="icon-btn bn-remove" data-remove aria-label="Retirer l'image" hidden>🗑</button>
                </div>
                <small class="muted">Formats : JPG, PNG, GIF, WebP. Taille conseillée : <?= e($size) ?>. Maximum : <?= $maxKb ?> Ko.<?= $k === 'mobile' ? ' Facultative : à défaut, l\'image ordinateur est utilisée.' : '' ?></small>
                <input data-f="image_<?= $k ?>" type="url" placeholder="… ou saisissez l'adresse de l'image (https://…)" aria-label="Adresse de l'image <?= e(mb_strtolower($label)) ?>">
              </div>
            <?php endforeach; ?>
          </div>
          <label>Texte alternatif <small class="muted">(lu par les lecteurs d'écran, affiché si l'image ne charge pas)</small>
            <input data-f="alt" maxlength="190" placeholder="ex. Soldes : -30 % sur les palmes de chasse"></label>
          <label>Lien cible
            <input data-f="link_url" type="url" placeholder="Adresse de la page vers laquelle envoyer le visiteur (https://…)"></label>
          <div class="cr-row">
            <strong>Ouvrir le lien dans une nouvelle fenêtre</strong>
            <label class="toggle"><input type="checkbox" role="switch" data-f="new_window" aria-label="Nouvelle fenêtre"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
          </div>
        </div>

        <div data-mode-panel="code" hidden>
          <div class="bn-code-head"><span>HTML</span><button type="button" class="icon-btn" data-copy aria-label="Copier le code">⧉</button></div>
          <textarea data-f="html" class="bn-code-area" rows="10" spellcheck="false" placeholder="&lt;a href=&quot;https://www.cabesto.com/…&quot;&gt;&lt;img src=&quot;https://…&quot; alt=&quot;…&quot;&gt;&lt;/a&gt;"></textarea>
          <small class="muted">Balises autorisées : liens, images (picture / source), textes et blocs. Les scripts, iframes, formulaires, événements (onclick…) et liens javascript: sont retirés à l'enregistrement.</small>
        </div>
      </div>
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
