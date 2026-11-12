<?php
/** @var string $title @var string $content @var string $active @var array $flash */
use DeepFind\Admin\Auth;

$nav = [
    'dashboard' => ['/admin', 'Tableau de bord'],
    'sources' => ['/admin/sources', 'Flux & mapping'],
    'imports' => ['/admin/imports', 'Imports'],
    'synonyms' => ['/admin/synonyms', 'Synonymes & alias'],
    'rules' => ['/admin/rules', 'Règles de résultats'],
    'redirects' => ['/admin/redirects', 'Redirections'],
    'relevance' => ['/admin/relevance', 'Pertinence & classement'],
    'boosts' => ['/admin/boosts', 'Boosts produits'],
    'preview' => ['/admin/preview', 'Aperçu interne'],
    'stats' => ['/admin/stats', 'Statistiques'],
    'logs' => ['/admin/logs', 'Journal des recherches'],
    'bots' => ['/admin/bots', 'Robots & blocages'],
    'keys' => ['/admin/keys', 'Clés & intégration'],
    'cache' => ['/admin/cache', 'Cache'],
    'settings' => ['/admin/settings', 'Paramètres'],
];
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · DeepFind</title>
<link rel="icon" href="<?= e(url('/favicon.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= e(url('/favicon-32.png')) ?>" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="<?= e(url('/apple-touch-icon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('/assets/admin.css')) ?>">
<script src="<?= e(asset('/assets/admin.js')) ?>"></script>
</head>
<body>
<div class="shell">
  <nav class="side" aria-label="Menu principal">
    <div class="side-head">
      <div class="brand"><?= logo_svg() ?><span class="brand-text">Deep<span>Find</span></span></div>
      <button type="button" class="icon-btn" data-toggle-menu aria-label="Replier ou déplier le menu" title="Replier / déplier le menu">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M15 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </button>
    </div>
    <?php foreach ($nav as $k => [$href, $label]): ?>
      <a href="<?= e(url($href)) ?>" class="<?= $active === $k ? 'on' : '' ?>" title="<?= e($label) ?>"><span class="nav-ico" aria-hidden="true"><?= e(mb_substr($label, 0, 1)) ?></span><span class="nav-label"><?= e($label) ?></span></a>
    <?php endforeach; ?>
    <a href="<?= e(url('/admin/demo')) ?>" target="_blank" rel="noopener" title="Démo du widget"><span class="nav-ico" aria-hidden="true">↗</span><span class="nav-label">Démo du widget</span></a>
    <div class="side-foot">
      <button type="button" class="theme-btn" data-toggle-theme title="Basculer thème clair / sombre">
        <span class="nav-ico" aria-hidden="true">☾</span><span class="nav-label">Thème <span data-theme-label>sombre</span></span>
      </button>
      <?php if (count(Auth::langs()) > 1): ?>
      <form method="post" action="<?= e(url('/admin/lang')) ?>" class="lang-form">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
        <label>Langue
          <select name="lang" data-autosubmit>
            <?php foreach (Auth::langs() as $l): ?>
              <option value="<?= e($l) ?>" <?= Auth::lang() === $l ? 'selected' : '' ?>><?= e(strtoupper($l)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>
      <?php else: ?>
        <div class="muted">Langue : <?= e(strtoupper(Auth::lang())) ?></div>
      <?php endif; ?>
      <form method="post" action="<?= e(url('/admin/logout')) ?>"><?= csrf_field() ?><button class="link">Se déconnecter</button></form>
    </div>
  </nav>
  <main class="main">
    <h1><?= e($title) ?></h1>
    <?php foreach ($flash as [$type, $msg]): ?>
      <div class="flash flash-<?= e($type) ?>"><?= e($msg) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
</div>
</body>
</html>
