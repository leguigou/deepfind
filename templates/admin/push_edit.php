<?php
/** Recommandations › Envoi de notifications › message. @var array $c @var array $audience @var array $counts @var string $icon @var string $host */
use DeepFind\Push\Push;
use DeepFind\Rules\Segments;

$when = $c['status'] === 'scheduled' ? 'at' : 'draft';
?>
<p><a href="<?= e(url('/admin/push')) ?>" class="cr-back">‹ Retour aux messages</a></p>
<div class="pe-layout">
<form method="post" action="<?= e(url('/admin/push/save')) ?>" class="card form" data-push-edit data-audience="<?= e(\DeepFind\Text::json($audience)) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
  <label>Titre <small class="muted">(80 caractères au plus ; 40 environ restent visibles sur un téléphone)</small>
    <input name="title" maxlength="80" required value="<?= e($c['title']) ?>" placeholder="ex. -20 % sur les palmes ce week-end" data-pe="title"></label>
  <label>Texte <small class="muted">(250 au plus)</small>
    <textarea name="body" rows="3" maxlength="250" placeholder="ex. Jusqu'à dimanche minuit, sur toute la gamme." data-pe="body"><?= e($c['body']) ?></textarea></label>
  <div class="grid2">
    <label>Lien ouvert au clic <input name="url" value="<?= e($c['url']) ?>" placeholder="https://www.example.com/promotions.html ou /promotions.html"></label>
    <label>Image <small class="muted">(facultatif ; affichée par Android et Windows)</small><input name="image" value="<?= e($c['image']) ?>" placeholder="https://…" data-pe="image"></label>
  </div>
  <h3>Destinataires</h3>
  <div class="grid2">
    <label>Appareils
      <select name="devices" data-pe-devices><?php foreach (Push::DEVICES as $k => $l): ?><option value="<?= e($k) ?>"<?= $c['devices'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    <p class="muted small pe-count" data-pe-count></p>
  </div>
  <p class="muted small">Provenance au moment de l'abonnement (facultatif, aucune case cochée : tous les abonnés) :</p>
  <div class="pp-seg-channels">
    <?php foreach (Segments::CHANNELS as $k => $label): ?>
      <label class="check"><input type="checkbox" name="channels[]" value="<?= e($k) ?>"<?= in_array($k, $c['channels'], true) ? ' checked' : '' ?>><span><?= e(explode(' (', $label)[0]) ?></span></label>
    <?php endforeach; ?>
  </div>
  <h3>Envoi</h3>
  <label class="check"><input type="radio" name="when" value="draft"<?= $when === 'draft' ? ' checked' : '' ?>><span>Garder en brouillon</span></label>
  <label class="check"><input type="radio" name="when" value="now"><span>Envoyer maintenant (dans la minute)</span></label>
  <label class="check"><input type="radio" name="when" value="at"<?= $when === 'at' ? ' checked' : '' ?>><span>Programmer le</span>
    <input type="datetime-local" name="scheduled_at" value="<?= e($c['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($c['scheduled_at'])) : '') ?>" class="pe-at"></label>
  <p class="muted small">Heure de Paris. L'envoi est fait par la tâche planifiée <code>schedule:run</code> (chaque minute). Un message envoyé ne peut plus être modifié.</p>
  <div class="cr-foot">
    <a class="btn btn-light" href="<?= e(url('/admin/push')) ?>">Annuler</a>
    <button class="btn" data-pe-submit>Enregistrer</button>
  </div>
</form>
<aside class="pe-preview" aria-label="Aperçu de la notification">
  <strong class="muted small">Aperçu (l'apparence exacte dépend du téléphone)</strong>
  <div class="pe-notif">
    <div class="pe-notif-head"><?php if ($icon !== ''): ?><img src="<?= e($icon) ?>" alt="" class="pe-icon"><?php else: ?><span class="pe-icon pe-icon-empty"></span><?php endif; ?>
      <span class="muted small"><?= e($host !== '' ? $host : 'votre site') ?> · maintenant</span></div>
    <strong data-pe-out="title"><?= e($c['title'] !== '' ? $c['title'] : 'Titre du message') ?></strong>
    <p data-pe-out="body"><?= e($c['body']) ?></p>
    <img alt="" class="pe-img" data-pe-out="image"<?= $c['image'] !== '' ? ' src="' . e($c['image']) . '"' : ' hidden' ?>>
  </div>
</aside>
</div>
<script src="<?= e(asset('/assets/push-admin.js')) ?>"></script>
