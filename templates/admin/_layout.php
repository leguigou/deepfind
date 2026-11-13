<?php
/** @var string $title @var string $content @var string $active @var array $flash */
use DeepFind\Admin\Auth;

$navGroups = [
    'analyse' => ['Analyse & performance', 'bars', [
        'stats' => ['/admin/stats', 'Statistiques', 'chart-pie'],
        'logs' => ['/admin/logs', 'Journal des recherches', 'file-search'],
    ]],
    'catalogue' => ['Catalogue & indexation', 'layers', [
        'sources' => ['/admin/sources', 'Flux & mapping', 'hierarchy'],
        'imports' => ['/admin/imports', 'Imports', 'download'],
        'disabled' => ['/admin/disabled', 'Produits désactivés', 'archive'],
        'bots' => ['/admin/bots', 'Robots & blocages', 'robot-face'],
    ]],
    'optimisation' => ['Optimisation de la recherche', 'adjustments', [
        'relevance' => ['/admin/relevance', 'Pertinence & classement', 'layers'],
        'boost_rules' => ['/admin/boost-rules', 'Règles de boost', 'rocket'],
        'synonyms' => ['/admin/synonyms', 'Synonymes & alias', 'tag'],
        'redirects' => ['/admin/redirects', 'Redirections', 'redirect'],
    ]],
    'merchandising' => ['Merchandising & expérience', 'storefront', [
        'custom_results' => ['/admin/custom-results', 'Résultats personnalisés', 'bars'],
        'banners' => ['/admin/banners', 'Bannières', 'speakerphone'],
        'preview' => ['/admin/preview', 'Aperçu interne', 'eye'],
        'demo' => ['/admin/demo', 'Démo du widget', 'external-link'],
    ]],
    'administration' => ['Administration & technique', 'settings', [
        'projects' => ['/admin/projects', 'Projets', 'folder'],
        'keys' => ['/admin/keys', 'Clés & intégration', 'key'],
        'cache' => ['/admin/cache', 'Cache', 'database'],
        'settings' => ['/admin/settings', 'Paramètres', 'settings'],
    ]],
];

$activeGroup = null;
foreach ($navGroups as $group => [, , $items]) {
    if (isset($items[$active])) {
        $activeGroup = $group;
        break;
    }
}
$navLink = static function (string $key, array $item) use ($active): void {
    [$href, $label, $icon] = $item;
    ?>
    <a href="<?= e(url($href)) ?>"<?= $active === $key ? ' class="on" aria-current="page"' : '' ?>
       <?= $key === 'demo' ? 'target="_blank" rel="noopener"' : '' ?> title="<?= e($label) ?>" aria-label="<?= e($label) ?>">
      <span class="nav-ico" aria-hidden="true"><?= admin_icon($icon) ?></span><span class="nav-label"><?= e($label) ?></span>
    </a>
    <?php
};
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
    <form method="post" action="<?= e(url('/admin/project')) ?>" class="project-switch" title="<?= e(\DeepFind\Project::current()['name']) ?>">
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
    <?php $navLink('dashboard', ['/admin', 'Tableau de bord', 'dashboard']); ?>
    <div class="side-nav" data-nav-groups>
      <div class="nav-groups">
        <?php foreach ($navGroups as $group => [$label, $icon, $items]): ?>
          <details name="admin-nav" class="nav-group<?= $activeGroup === $group ? ' contains-active' : '' ?>"
                   data-nav-group="<?= e($group) ?>"<?= ($activeGroup ?? 'analyse') === $group ? ' open' : '' ?>>
            <summary title="<?= e($label) ?>" aria-label="<?= e($label) ?>">
              <span class="nav-ico" aria-hidden="true"><?= admin_icon($icon) ?></span>
              <span class="nav-label"><?= e($label) ?></span>
              <?= admin_icon('chevron-down', 'nav-chevron') ?>
            </summary>
            <div class="nav-group-links">
              <?php foreach ($items as $key => $item): $navLink($key, $item); endforeach; ?>
            </div>
          </details>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="side-foot">
      <button type="button" class="theme-btn" data-toggle-theme title="Basculer thème clair / sombre" aria-label="Basculer thème clair / sombre">
        <span class="nav-ico" aria-hidden="true"><?= admin_theme_icons() ?></span><span class="nav-label">Thème <span data-theme-label>sombre</span></span>
      </button>
      <?php if (count(Auth::langs()) > 1): ?>
      <form method="post" action="<?= e(url('/admin/lang')) ?>" class="lang-form">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
        <label class="side-meta"><?= admin_icon('world') ?> <span class="nav-label">Langue</span>
          <span class="side-select"><select name="lang" data-autosubmit aria-label="Langue">
            <?php foreach (Auth::langs() as $l): ?>
              <option value="<?= e($l) ?>" <?= Auth::lang() === $l ? 'selected' : '' ?>><?= e(strtoupper($l)) ?></option>
            <?php endforeach; ?>
          </select><?= admin_icon('chevron-down') ?></span>
        </label>
      </form>
      <?php else: ?>
        <div class="side-meta muted"><?= admin_icon('world') ?><span class="nav-label">Langue : <?= e(strtoupper(Auth::lang())) ?></span></div>
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
