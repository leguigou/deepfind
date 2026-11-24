<?php
/** Aide › wiki : rubriques à gauche, page à droite (sommaire de la page sur grand écran), recherche.
 *  @var array $tree @var array $node @var string $html @var array $toc @var ?array $prev @var ?array $next @var array $search @var ?string $source @var ?int $updated @var string $title */
$current = $node['path'];
$open = array_column($node['trail'] ?? [], 'path');
$renderTree = static function (array $nodes, int $depth) use (&$renderTree, $current, $open): void { ?>
  <ul class="wk-tree wk-depth-<?= $depth ?>">
    <?php foreach ($nodes as $n): $isOpen = $n['path'] === $current || in_array($n['path'], $open, true); ?>
      <li>
        <?php if ($n['type'] === 'cat'): ?>
          <details<?= $isOpen ? ' open' : '' ?>>
            <summary class="<?= $n['path'] === $current ? 'is-current' : '' ?>">
              <?= admin_icon('chevron-down', 'wk-chev') ?>
              <?php if ($n['file'] !== null): ?><a href="<?= e(url($n['path'])) ?>"<?= $n['path'] === $current ? ' aria-current="page"' : '' ?>><?= e($n['title']) ?></a>
              <?php else: ?><span><?= e($n['title']) ?></span><?php endif; ?>
            </summary>
            <?php $renderTree($n['children'], $depth + 1); ?>
          </details>
        <?php else: ?>
          <a href="<?= e(url($n['path'])) ?>" class="wk-page<?= $n['path'] === $current ? ' is-current' : '' ?>"<?= $n['path'] === $current ? ' aria-current="page"' : '' ?>><?= e($n['title']) ?></a>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php };
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · Aide DeepFind</title>
<link rel="icon" href="<?= e(url('/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('/assets/admin.css')) ?>">
<script src="<?= e(asset('/assets/admin.js')) ?>"></script>
</head>
<body class="wk-body">
<header class="wk-top">
  <a class="wk-brand" href="<?= e(url(\DeepFind\Wiki\Wiki::BASE)) ?>"><?= logo_svg(26) ?><span>Deep<b>Find</b></span><span class="wk-brand-sub">Aide</span></a>
  <div class="wk-search" role="search">
    <input type="search" data-wk-search placeholder="Rechercher dans l'aide (ex. popin, import, consentement)" aria-label="Rechercher dans l'aide" autocomplete="off">
    <div class="wk-results" data-wk-results hidden role="listbox" aria-label="Résultats"></div>
  </div>
  <button type="button" class="icon-btn wk-menu-btn" data-wk-menu aria-label="Afficher les rubriques" aria-expanded="false"><?= admin_icon('bars') ?></button>
  <button type="button" class="icon-btn" data-toggle-theme title="Basculer thème clair / sombre" aria-label="Basculer thème clair / sombre"><?= admin_theme_icons() ?></button>
  <a class="btn btn-sm btn-light wk-back" href="<?= e(url('/admin')) ?>"><?= admin_icon('chevron-left') ?> Extranet</a>
</header>
<div class="wk-shell">
  <nav class="wk-nav" aria-label="Rubriques de l'aide" data-wk-nav>
    <a href="<?= e(url(\DeepFind\Wiki\Wiki::BASE)) ?>" class="wk-page wk-home<?= $current === \DeepFind\Wiki\Wiki::BASE ? ' is-current' : '' ?>"><?= admin_icon('lifebuoy') ?> Accueil de l'aide</a>
    <?php $renderTree($tree, 0); ?>
  </nav>
  <main class="wk-main">
    <article class="wk-article">
      <?php if (!empty($node['trail'])): ?>
        <nav class="wk-crumbs" aria-label="Fil d'Ariane"><a href="<?= e(url(\DeepFind\Wiki\Wiki::BASE)) ?>">Aide</a>
          <?php foreach ($node['trail'] as $t): ?> › <a href="<?= e(url($t['path'])) ?>"><?= e($t['title']) ?></a><?php endforeach; ?></nav>
      <?php endif; ?>
      <div class="wk-content"><?= $html /* Markdown rendu par Wiki\Markdown : tout le texte y est échappé */ ?></div>
      <?php if (in_array($node['type'], ['cat', 'home'], true) && $node['children']): ?>
        <div class="wk-cards">
          <?php foreach ($node['children'] as $c): ?>
            <a class="wk-card" href="<?= e(url($c['path'])) ?>"><strong><?= e($c['title']) ?></strong>
              <?php if ($c['type'] === 'cat'): ?><span class="muted small"><?= count($c['children']) ?> page<?= count($c['children']) > 1 ? 's' : '' ?></span><?php endif; ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <nav class="wk-pager" aria-label="Pages voisines">
        <?php if ($prev): ?><a class="wk-prev" href="<?= e(url($prev['path'])) ?>"><span class="muted small">‹ Précédent</span><strong><?= e($prev['title']) ?></strong></a><?php else: ?><span></span><?php endif; ?>
        <?php if ($next): ?><a class="wk-next" href="<?= e(url($next['path'])) ?>"><span class="muted small">Suivant ›</span><strong><?= e($next['title']) ?></strong></a><?php endif; ?>
      </nav>
      <?php if ($source): ?>
        <p class="wk-source muted small">Source : <code><?= e($source) ?></code><?= $updated ? ' · mise à jour le ' . e(date('d/m/Y', $updated)) : '' ?>.
          Pour compléter cette page, modifiez ce fichier dans le dépôt de DeepFind (voir « Contribuer à l'aide » sur l'accueil).</p>
      <?php endif; ?>
    </article>
    <?php if (count($toc) > 2): ?>
      <aside class="wk-toc" aria-label="Sur cette page">
        <strong>Sur cette page</strong>
        <ul><?php foreach ($toc as $h): ?><li class="wk-toc-<?= (int) $h['level'] ?>"><a href="#<?= e($h['id']) ?>"><?= e($h['text']) ?></a></li><?php endforeach; ?></ul>
      </aside>
    <?php endif; ?>
  </main>
</div>
<script type="application/json" id="wk-index"><?= str_replace('</', '<\/', \DeepFind\Text::json(array_map(static fn ($p) => $p + ['url' => url($p['path'])], $search))) ?></script>
<script src="<?= e(asset('/assets/wiki.js')) ?>"></script>
</body>
</html>
