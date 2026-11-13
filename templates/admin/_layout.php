<?php
/** @var string $title @var string $content @var string $active @var array $flash */
use DeepFind\Admin\Auth;

$nav = [
    'dashboard' => ['/admin', 'Tableau de bord', 'dashboard'],
    'projects' => ['/admin/projects', 'Projets', 'folder'],
    'sources' => ['/admin/sources', 'Flux & mapping', 'hierarchy'],
    'imports' => ['/admin/imports', 'Imports', 'download'],
    'synonyms' => ['/admin/synonyms', 'Synonymes & alias', 'tag'],
    'custom_results' => ['/admin/custom-results', 'Résultats personnalisés', 'bars'],
    'banners' => ['/admin/banners', 'Bannières', 'speakerphone'],
    'redirects' => ['/admin/redirects', 'Redirections', 'redirect'],
    'relevance' => ['/admin/relevance', 'Pertinence & classement', 'layers'],
    'boost_rules' => ['/admin/boost-rules', 'Règles de boost', 'rocket'],
    'preview' => ['/admin/preview', 'Aperçu interne', 'eye'],
    'disabled' => ['/admin/disabled', 'Produits désactivés', 'archive'],
    'stats' => ['/admin/stats', 'Statistiques', 'chart-pie'],
    'logs' => ['/admin/logs', 'Journal des recherches', 'file-search'],
    'bots' => ['/admin/bots', 'Robots & blocages', 'robot-face'],
    'keys' => ['/admin/keys', 'Clés & intégration', 'key'],
    'cache' => ['/admin/cache', 'Cache', 'database'],
    'settings' => ['/admin/settings', 'Paramètres', 'settings'],
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
        <?= admin_icon('chevron-left') ?>
      </button>
    </div>
    <form method="post" action="<?= e(url('/admin/project')) ?>" class="project-switch" title="Projet actif">
      <?= csrf_field() ?>
      <input type="hidden" name="back" value="<?= e(substr((string) ($_SERVER['REQUEST_URI'] ?? '/admin'), strlen(rtrim((string) \DeepFind\Config::get('APP_BASE_PATH', ''), '/')))) ?>">
      <span class="nav-ico project-ico" aria-hidden="true"><?= admin_icon('layers') ?></span>
      <label class="project-label"><span class="muted small">Projet</span>
        <span class="side-select"><select name="project_id" data-autosubmit aria-label="Projet actif">
          <?php foreach (\DeepFind\Project::all() as $pj): ?>
            <option value="<?= (int) $pj['id'] ?>" <?= $pj['id'] === \DeepFind\Project::id() ? 'selected' : '' ?>><?= e($pj['name']) ?></option>
          <?php endforeach; ?>
        </select><?= admin_icon('chevron-down') ?></span>
      </label>
    </form>
    <?php foreach ($nav as $k => [$href, $label, $icon]): ?>
      <a href="<?= e(url($href)) ?>" <?= $active === $k ? 'class="on" aria-current="page"' : '' ?> title="<?= e($label) ?>" aria-label="<?= e($label) ?>"><span class="nav-ico" aria-hidden="true"><?= admin_icon($icon) ?></span><span class="nav-label"><?= e($label) ?></span></a>
    <?php endforeach; ?>
    <a href="<?= e(url('/admin/demo')) ?>" target="_blank" rel="noopener" title="Démo du widget" aria-label="Démo du widget"><span class="nav-ico" aria-hidden="true"><?= admin_icon('external-link') ?></span><span class="nav-label">Démo du widget</span></a>
    <div class="side-foot">
      <button type="button" class="theme-btn" data-toggle-theme title="Basculer thème clair / sombre" aria-label="Basculer thème clair / sombre">
        <span class="nav-ico" aria-hidden="true"><?= admin_theme_icons() ?></span><span class="nav-label">Thème <span data-theme-label>sombre</span></span>
      </button>
      <?php if (count(Auth::langs()) > 1): ?>
      <form method="post" action="<?= e(url('/admin/lang')) ?>" class="lang-form">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
        <label class="side-meta"><?= admin_icon('world') ?> Langue
          <span class="side-select"><select name="lang" data-autosubmit>
            <?php foreach (Auth::langs() as $l): ?>
              <option value="<?= e($l) ?>" <?= Auth::lang() === $l ? 'selected' : '' ?>><?= e(strtoupper($l)) ?></option>
            <?php endforeach; ?>
          </select><?= admin_icon('chevron-down') ?></span>
        </label>
      </form>
      <?php else: ?>
        <div class="side-meta muted"><?= admin_icon('world') ?> Langue : <?= e(strtoupper(Auth::lang())) ?></div>
      <?php endif; ?>
      <form method="post" action="<?= e(url('/admin/logout')) ?>"><?= csrf_field() ?><button class="side-meta side-logout" title="Se déconnecter" aria-label="Se déconnecter"><?= admin_icon('logout') ?><span class="nav-label">Se déconnecter</span></button></form>
    </div>
  </nav>
  <main class="main">
    <div class="page-head">
      <h1><?= e($title) ?></h1>
      <a class="project-chip <?= \DeepFind\Project::id() === \DeepFind\Project::defaultId() ? 'is-main' : 'is-other' ?>" href="<?= e(url('/admin/projects')) ?>" title="Changer de projet">
        <?= e(\DeepFind\Project::current()['name']) ?></a>
    </div>
    <?php if (!\DeepFind\Project::isActive()): ?>
      <div class="flash flash-warn">Projet « <?= e(\DeepFind\Project::current()['name']) ?> » <strong>désactivé</strong> : le widget ne s'active pas sur le site, la recherche d'origine du site est utilisée.
        <a href="<?= e(url('/admin/projects')) ?>">Gérer les projets</a></div>
    <?php endif; ?>
    <?php foreach ($flash as [$type, $msg]): ?>
      <div class="flash flash-<?= e($type) ?>"><?= e($msg) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
</div>
</body>
</html>
