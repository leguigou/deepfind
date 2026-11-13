<?php /** @var array $r */ ?>
<p><a href="<?= e(url('/admin/redirects')) ?>" class="cr-back">‹ Retour aux redirections</a></p>

<form method="post" action="<?= e(url('/admin/redirects/save')) ?>" class="form cr-form" data-redirect-form data-redirect="<?= e(\DeepFind\Text::json($r)) ?>">
  <?= csrf_field() ?><input type="hidden" name="payload" value="">
  <div class="cr-head">
    <input class="cr-name" data-f="name" maxlength="190" placeholder="Écrire le nom interne" aria-label="Nom interne" required>
  </div>

  <div class="card cr-card">
    <div class="cr-row">
      <strong>Statut</strong>
      <label class="toggle"><input type="checkbox" role="switch" data-f="active" aria-label="Statut actif"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
    </div>
    <div class="cr-row">
      <div><strong>Redirection automatique</strong><br><small class="muted">Dès que le visiteur valide ce terme (touche Entrée ou loupe), il est envoyé directement sur la page.
        Désactivée : un lien vers la page est proposé en haut des résultats, et la recherche reste possible.</small></div>
      <label class="toggle"><input type="checkbox" role="switch" data-f="auto" aria-label="Redirection automatique"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
    </div>
    <label>URL de destination
      <input data-f="url" type="url" placeholder="ex. : https://www.cabesto.com/fr/livraison" required></label>

    <?php $withTerms = true; $withPeriod = false; include __DIR__ . '/_terms_period.php'; ?>
    <?php $withTerms = false; $withPeriod = true; include __DIR__ . '/_terms_period.php'; ?>
  </div>

  <div class="cr-foot">
    <a class="btn btn-light" href="<?= e(url('/admin/redirects')) ?>">Annuler</a>
    <button class="btn">Enregistrer</button>
  </div>
</form>
<?php if ($r['id']): ?>
  <form method="post" action="<?= e(url('/admin/redirects/delete')) ?>" class="cr-delete"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <button class="btn btn-sm btn-danger" data-confirm="Supprimer la redirection « <?= e($r['name']) ?> » ?">Supprimer cette redirection</button></form>
<?php endif; ?>
<script src="<?= e(asset('/assets/editor-tools.js')) ?>"></script>
<script src="<?= e(asset('/assets/redirects.js')) ?>"></script>
