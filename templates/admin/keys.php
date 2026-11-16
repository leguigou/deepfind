<p class="muted">La clé publique est visible dans le code des pages du site : c'est normal. Elle est protégée par la liste des domaines autorisés et par les seuils anti-robots.</p>
<form method="post" action="<?= e(url('/admin/keys/create')) ?>" class="card form inline">
  <?= csrf_field() ?>
  <label>Nom <input name="name" required placeholder="Site cabesto.com"></label>
  <label>Domaines autorisés (séparés par des virgules ; sous-domaines inclus) <input name="origins" placeholder="cabesto.com"></label>
  <label>Langues <input name="langs" value="fr" size="8"></label>
  <button class="btn">Créer une clé</button>
</form>

<table class="table">
  <thead><tr><th>Nom</th><th>Clé publique</th><th>Domaines autorisés</th><th>Langues</th><th>Active</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($keys as $k): ?>
    <tr>
      <td><strong><?= e($k['name']) ?></strong><br><span class="muted"><?= fmt_dt($k['created_at']) ?></span></td>
      <td><code class="select"><?= e($k['public_key']) ?></code></td>
      <td colspan="3">
        <form method="post" action="<?= e(url('/admin/keys/update')) ?>" class="inline-form">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
          <input name="origins" value="<?= e($k['allowed_origins']) ?>" placeholder="(tous les domaines : déconseillé)">
          <input name="langs" value="<?= e($k['langs']) ?>" size="6">
          <label class="toggle toggle-sm"><input type="checkbox" role="switch" name="active" <?= $k['active'] ? 'checked' : '' ?>><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">active</span></label>
          <button class="btn btn-sm btn-light">Enregistrer</button>
        </form>
      </td>
      <td class="actions">
        <a class="btn btn-sm btn-light" href="<?= e(url('/admin/demo', ['key' => $k['public_key']])) ?>" target="_blank" rel="noopener">Démo</a>
        <form method="post" action="<?= e(url('/admin/keys/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $k['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="Supprimer cette clé ? Le widget cessera de fonctionner sur les sites qui l'utilisent.">Supprimer</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$keys): ?><tr><td colspan="7" class="muted">Aucune clé.</td></tr><?php endif; ?>
  </tbody>
</table>

<p class="muted">Le code à ajouter sur le site et l'affichage du widget (layers, filtres, options de tri) se règlent dans <a href="<?= e(url('/admin/integration')) ?>">Intégration</a>.</p>
