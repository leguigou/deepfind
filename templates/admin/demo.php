<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Démo du widget · DeepFind</title>
<link rel="icon" href="<?= e(url('/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(url('/assets/demo.css')) ?>?v=1">
</head>
<body>
<header class="demo-header" id="demo-header">
  <div class="demo-logo">CABESTO</div>
  <form class="demo-search" action="#" role="search">
    <input id="search" name="q" type="search" placeholder="Rechercher un produit, une marque, un SKU…" autocomplete="off">
  </form>
  <nav class="demo-links"><span>Nous contacter</span><span>Nos magasins</span><span>Mon compte</span><span>Mon panier</span></nav>
</header>
<main class="demo-main">
  <h1>Page de démonstration</h1>
  <p>Tapez une recherche dans le champ ci-dessus (ex. <em>palme</em>, <em>plame</em>, <em>masque</em>, un SKU…).</p>
  <?php if (!$keys): ?>
    <p><strong>Aucune clé publique active.</strong> Créez-en une dans l'extranet, rubrique « Clés &amp; intégration ».</p>
  <?php else: ?>
    <form method="get" action="<?= e(url('/admin/demo')) ?>">
      Clé utilisée : <select name="key" data-autosubmit><?php foreach ($keys as $k): ?><option value="<?= e($k['public_key']) ?>" <?= $k['public_key'] === $key ? 'selected' : '' ?>><?= e($k['name']) ?></option><?php endforeach; ?></select>
    </form>
  <?php endif; ?>
  <p><a href="<?= e(url('/admin')) ?>">← Retour à l'extranet</a></p>
</main>
<script src="<?= e(url('/assets/admin.js')) ?>?v=3"></script>
<?php if ($key !== ''): ?>
<script src="<?= e(url('/widget/deepfind.js')) ?>?v=1" defer
  data-key="<?= e($key) ?>" data-lang="<?= e($lang) ?>" data-input="#search" data-anchor="#demo-header"
  data-api="<?= e(url('/api/v1')) ?>" data-results-url=""></script>
<?php endif; ?>
</body>
</html>
