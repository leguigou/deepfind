<?php /** @var ?string $error */ ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Connexion · DeepFind</title>
<link rel="stylesheet" href="<?= e(url('/assets/admin.css')) ?>?v=2">
<script src="<?= e(url('/assets/admin.js')) ?>?v=2"></script>
</head>
<body class="login-page">
<button type="button" class="icon-btn login-theme" data-toggle-theme title="Basculer thème clair / sombre" aria-label="Basculer thème clair / sombre">◐</button>
<form method="post" action="<?= e(url('/admin/login')) ?>" class="login-box" autocomplete="off">
  <?= csrf_field() ?>
  <div class="brand"><span class="brand-mark" aria-hidden="true"></span><span class="brand-text">Deep<span>Find</span></span></div>
  <p class="muted">Extranet de gestion du moteur de recherche</p>
  <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
  <label>Jeton d'accès
    <input type="password" name="token" required autofocus>
  </label>
  <button class="btn">Se connecter</button>
</form>
</body>
</html>
