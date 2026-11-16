<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Démo du widget · DeepFind</title>
<link rel="icon" href="<?= e(url('/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('/assets/demo.css')) ?>">
</head>
<body>
<header class="demo-header" id="demo-header">
  <div class="demo-logo">CABESTO</div>
  <form class="demo-search" action="#" role="search">
    <input id="search" name="q" type="search" placeholder="Rechercher un produit, une marque, un SKU…" autocomplete="off">
  </form>
  <nav class="demo-links"><span>Nous contacter</span><span>Nos magasins</span><span>Mon compte</span><span>Mon panier</span></nav>
</header>
<?php // Barre d'outils de la démo, en dehors du bloc principal (le layer « Intégré » remplace le contenu de main#maincontent) ?>
<div class="demo-toolbar">
  <?php if (!$keys): ?>
    <p><strong>Aucune clé publique active.</strong> Créez-en une dans l'extranet, rubrique « Clés ».</p>
  <?php else: ?>
    <form method="get" action="<?= e(url('/admin/demo')) ?>" class="demo-options">
      <label>Clé utilisée <select name="key" data-autosubmit><?php foreach ($keys as $k): ?><option value="<?= e($k['public_key']) ?>" <?= $k['public_key'] === $key ? 'selected' : '' ?>><?= e($k['name']) ?></option><?php endforeach; ?></select></label>
      <?php if ($layers): ?>
      <label>Layer testé <select name="layer" data-autosubmit>
        <?php $none = '<option value="0"' . (!$layer ? ' selected' : '') . '>Aucun layer (affichage par défaut' . ($hasActive ? '' : ', comme le site') . ')</option>'; ?>
        <?= $hasActive ? '' : $none ?>
        <?php foreach ($layers as $l): ?>
          <option value="<?= (int) $l['id'] ?>" <?= $layer && $layer['id'] === $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?> — <?= e(\DeepFind\Widget\WidgetConfig::TYPES[$l['type']][0]) ?><?= $l['active'] ? ' (actif sur le site)' : '' ?></option>
        <?php endforeach; ?>
        <?= $hasActive ? $none : '' ?>
      </select></label>
      <?php endif; ?>
      <a href="<?= e(url('/admin')) ?>" class="demo-back">← Retour à l'extranet</a>
    </form>
    <?php if ($layer && !$layer['active']): ?><p class="demo-note">Layer <strong>inactif</strong> : seule cette démo l'utilise, le site garde son affichage actuel.</p><?php endif; ?>
    <?php if (!$layer && $hasActive): ?><p class="demo-note">Sans layer : affichage par défaut, alors que le site utilise son layer actif.</p><?php endif; ?>
    <?php if (!$layers): ?><p class="demo-note">Aucun layer : affichage par défaut (sous la barre). Créez-en dans Intégration › Layers.</p><?php endif; ?>
    <?php if ($layer && $layer['type'] === 'embedded'): ?><p class="demo-note">Layer « Intégré » : les résultats prennent la place du bloc <code><?= e($layer['settings']['container']) ?></code> s'il existe sur cette page (ici, le contenu ci-dessous est <code>main#maincontent</code>), sinon ils s'affichent sous la barre.</p><?php endif; ?>
  <?php endif; ?>
</div>
<main class="demo-main" id="maincontent">
  <h1>Page de démonstration</h1>
  <?php if (!\DeepFind\Project::isActive()): ?><p style="padding:10px 14px;border-radius:10px;background:#fff4e5;color:#7a4b00">Projet désactivé : le widget ne s'active pas (comme sur le site). Réactivez-le dans « Projets » pour le tester.</p><?php endif; ?>
  <p>Tapez une recherche dans le champ ci-dessus (ex. <em>palme</em>, <em>plame</em>, <em>masque</em>, un SKU…).</p>
</main>
<script src="<?= e(asset('/assets/admin.js')) ?>"></script>
<?php if ($key !== ''): ?>
<script src="<?= e(asset('/widget/deepfind.js')) ?>" defer
  data-key="<?= e($key) ?>" data-lang="<?= e($lang) ?>" data-input="#search" data-anchor="#demo-header"
  data-api="<?= e(url('/api/v1')) ?>" data-results-url="" data-demo="1"<?= $previewToken !== '' ? ' data-preview="' . e($previewToken) . '"' : '' ?>></script>
<?php endif; ?>
</body>
</html>
