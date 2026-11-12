<?php $base = $appUrl !== '' ? $appUrl : 'https://search.cabesto.com'; ?>
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
          <label class="toggle toggle-sm"><input type="checkbox" role="switch" name="active" <?= $k['active'] ? 'checked' : '' ?><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">> active</span></label>
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

<h2>Intégration sur le site (Magento)</h2>
<p>Ajoutez ce code avant <code>&lt;/body&gt;</code>, par exemple dans <em>Contenu › Design › Configuration › HTML Head › Scripts et feuilles de style</em> ou dans un bloc du thème :</p>
<pre class="code">&lt;script src="<?= e($base) ?>/widget/deepfind.js?v=2" defer
  data-key="<?= e($keys[0]['public_key'] ?? 'pk_VOTRE_CLE') ?>"
  data-lang="fr"
  data-input="#search, input[name=q]"
  data-anchor="header.page-header"
  data-results-url="https://www.cabesto.com/catalogsearch/result/?q="&gt;&lt;/script&gt;</pre>
<table class="table compact">
  <thead><tr><th>Attribut</th><th>Rôle</th></tr></thead>
  <tbody>
    <tr><td><code>data-key</code></td><td>Clé publique (obligatoire)</td></tr>
    <tr><td><code>data-lang</code></td><td>Langue des résultats (fr par défaut)</td></tr>
    <tr><td><code>data-input</code></td><td>Sélecteur CSS du ou des champs de recherche du site</td></tr>
    <tr><td><code>data-anchor</code></td><td>Élément sous lequel s'ouvre le panneau (en-tête du site). Par défaut : sous le champ de recherche</td></tr>
    <tr><td><code>data-results-url</code></td><td>Page « Tout voir » (la requête est ajoutée à la fin). Vide = bouton masqué</td></tr>
    <tr><td><code>data-currency</code>, <code>data-locale</code></td><td>Format des prix (EUR, fr-FR par défaut)</td></tr>
    <tr><td><code>data-min-chars</code>, <code>data-page-size</code></td><td>Caractères avant recherche (2), produits par page (20)</td></tr>
  </tbody>
</table>
