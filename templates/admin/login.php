<?php /** @var ?string $error */ ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Connexion · DeepFind</title>
<link rel="icon" href="<?= e(url('/favicon.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= e(url('/favicon-32.png')) ?>" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="<?= e(url('/apple-touch-icon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('/assets/admin.css')) ?>">
<script src="<?= e(asset('/assets/admin.js')) ?>"></script>
</head>
<body class="login-page">
<button type="button" class="icon-btn login-theme" data-toggle-theme title="Basculer thème clair / sombre" aria-label="Basculer thème clair / sombre"><?= admin_theme_icons() ?></button>
<form method="post" action="<?= e(url('/admin/login')) ?>" class="login-box" autocomplete="off">
  <?= csrf_field() ?>
  <div class="brand"><?= logo_svg() ?><span class="brand-text">Deep<span>Find</span></span></div>
  <p class="muted">Extranet de gestion du moteur de recherche</p>
  <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
  <label>Jeton d'accès
    <input type="password" name="token" required autofocus>
  </label>
  <button class="btn">Se connecter</button>
</form>
</body>
</html>
