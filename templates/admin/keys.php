<?php
/** @var array $keys */
// Clé masquée : début et fin visibles (pk_64a0***aa94) ; la clé complète reste publique (code du site), affichée sur demande
$mask = static fn (string $k): string => strlen($k) > 12 ? substr($k, 0, 7) . '***' . substr($k, -4) : $k;
?>
<div class="page-actions">
  <p class="muted">La clé publique est visible dans le code des pages du site : c'est normal. Elle est protégée par la liste des domaines autorisés et par les seuils anti-robots.</p>
  <button type="button" class="btn" data-dialog-open="key-dialog">+ Ajouter une clé</button>
</div>

<div class="table-scroll">
<table class="table keys-table">
  <thead><tr><th>Nom</th><th>Clé publique</th><th>Domaines autorisés</th><th>Langues</th><th>Active</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($keys as $k): $fid = 'key-' . (int) $k['id']; ?>
    <tr>
      <td><input name="name" form="<?= $fid ?>" value="<?= e($k['name']) ?>" required maxlength="100" aria-label="Nom de la clé" class="key-name"
        title="Créée le <?= e(fmt_dt($k['created_at'])) ?>"></td>
      <td class="nowrap">
        <span class="key-value" data-key-full="<?= e($k['public_key']) ?>" data-key-masked="<?= e($mask($k['public_key'])) ?>"><code><?= e($mask($k['public_key'])) ?></code></span>
        <button type="button" class="icon-btn icon-btn-sm" data-key-reveal aria-pressed="false" title="Afficher la clé" aria-label="Afficher la clé « <?= e($k['name']) ?> »">
          <?= admin_icon('eye', 'key-eye') ?><?= admin_icon('eye-off', 'key-eye-off') ?></button>
        <button type="button" class="icon-btn icon-btn-sm" data-key-copy title="Copier la clé" aria-label="Copier la clé « <?= e($k['name']) ?> »">
          <?= admin_icon('copy', 'key-copy') ?><?= admin_icon('check', 'key-copied') ?></button>
      </td>
      <td><input name="origins" form="<?= $fid ?>" value="<?= e($k['allowed_origins']) ?>" placeholder="(tous les domaines : déconseillé)" aria-label="Domaines autorisés" class="key-origins"></td>
      <td><input name="langs" form="<?= $fid ?>" value="<?= e($k['langs']) ?>" aria-label="Langues" class="key-langs"></td>
      <td><label class="toggle toggle-sm"><input type="checkbox" role="switch" name="active" form="<?= $fid ?>" <?= $k['active'] ? 'checked' : '' ?> aria-label="Clé « <?= e($k['name']) ?> » active"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label></td>
      <td class="nowrap"><div class="key-actions">
        <form method="post" action="<?= e(url('/admin/keys/update')) ?>" id="<?= $fid ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
          <button class="btn btn-sm">Enregistrer</button></form>
        <a class="btn btn-sm btn-light" href="<?= e(url('/admin/demo', ['key' => $k['public_key']])) ?>" target="_blank" rel="noopener">Démo</a>
        <form method="post" action="<?= e(url('/admin/keys/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
          <button class="icon-btn icon-btn-sm key-delete" title="Supprimer" aria-label="Supprimer la clé « <?= e($k['name']) ?> »" data-confirm="Supprimer la clé « <?= e($k['name']) ?> » ? Le widget cessera de fonctionner sur les sites qui l'utilisent."><?= admin_icon('trash') ?></button></form>
      </div></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$keys): ?><tr><td colspan="6" class="muted">Aucune clé. Ajoutez-en une pour installer le widget sur le site.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted">Le code à ajouter sur le site et l'affichage du widget (layers, filtres, options de tri) se règlent dans <a href="<?= e(url('/admin/integration')) ?>">Intégration</a>.</p>

<dialog id="key-dialog" class="modal" aria-labelledby="key-dialog-title">
  <form method="post" action="<?= e(url('/admin/keys/create')) ?>" class="form modal-form">
    <?= csrf_field() ?>
    <div class="modal-head">
      <div class="modal-title"><h2 id="key-dialog-title">Ajouter une clé</h2></div>
      <button type="button" class="icon-btn modal-close" data-dialog-close aria-label="Fermer">✕</button>
    </div>
    <div class="modal-body">
      <label>Nom <input name="name" required maxlength="100" placeholder="Site www.example.com">
        <small class="muted">Pour retrouver la clé dans la liste (ex. « Site — Production »).</small></label>
      <label>Domaines autorisés <input name="origins" placeholder="example.com, www.example.com">
        <small class="muted">Séparés par des virgules ; sous-domaines inclus. Vide : tous les domaines (déconseillé).</small></label>
      <label>Langues <input name="langs" value="fr" class="key-langs">
        <small class="muted">Codes séparés par des virgules (ex. <code>fr,en</code>).</small></label>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-light" data-dialog-close>Annuler</button>
      <button class="btn">Créer la clé</button>
    </div>
  </form>
</dialog>
