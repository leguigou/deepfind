<?php
/** Aide › wiki : rubriques à gauche ; à droite, toutes les pages de la rubrique à la suite (lecture continue), la page en cours
 *  surlignée à gauche pendant le défilement ; sommaire de la page en cours sur grand écran ; recherche.
 *  @var array $tree @var array $node @var ?array $top @var list<array> $sections @var ?array $prev @var ?array $next @var array $search @var string $title */
use DeepFind\Wiki\Wiki;

$current = $node['path'];
$open = array_column($node['trail'] ?? [], 'path');
$inTop = static fn (string $p): bool => $top !== null && ($p === $top['path'] || str_starts_with($p, $top['path'] . '/'));
$renderTree = static function (array $nodes, int $depth) use (&$renderTree, $current, $open, $inTop): void { ?>
  <ul class="wk-tree wk-depth-<?= $depth ?>">
    <?php foreach ($nodes as $n):
      $isOpen = $n['path'] === $current || in_array($n['path'], $open, true) || ($depth === 0 && $inTop($n['path']));
      $sec = $inTop($n['path']) ? ' data-wk-section="' . e(Wiki::anchor($n['path'])) . '"' : ''; ?>
      <li>
        <?php if ($n['type'] === 'cat'): ?>
          <details<?= $isOpen ? ' open' : '' ?>>
            <summary class="<?= $n['path'] === $current ? 'is-current' : '' ?>">
              <?= admin_icon('chevron-down', 'wk-chev') ?>
              <a href="<?= e(url($n['path'])) ?>"<?= $sec ?><?= $n['path'] === $current ? ' aria-current="page"' : '' ?>><?= e($n['title']) ?></a>
            </summary>
            <?php $renderTree($n['children'], $depth + 1); ?>
          </details>
        <?php else: ?>
          <a href="<?= e(url($n['path'])) ?>" class="wk-page<?= $n['path'] === $current ? ' is-current' : '' ?>"<?= $sec ?><?= $n['path'] === $current ? ' aria-current="page"' : '' ?>><?= e($n['title']) ?></a>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php };
$hasToc = array_filter($sections, static fn ($s) => count($s['toc']) > 1) !== [];
$start = $top !== null && $node['path'] !== $top['path'] ? Wiki::anchor($node['path']) : '';
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
  <a class="wk-brand" href="<?= e(url(Wiki::BASE)) ?>"><?= logo_svg(26) ?><span>Deep<b>Find</b></span><span class="wk-brand-sub">Aide</span></a>
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
    <a href="<?= e(url(Wiki::BASE)) ?>" class="wk-page wk-home<?= $current === Wiki::BASE ? ' is-current' : '' ?>"><?= admin_icon('lifebuoy') ?> Accueil de l'aide</a>
    <?php $renderTree($tree, 0); ?>
  </nav>
  <main class="wk-main"<?= $start !== '' ? ' data-wk-start="' . e($start) . '"' : '' ?>>
    <article class="wk-article">
      <?php if ($top !== null): ?>
        <nav class="wk-crumbs" aria-label="Fil d'Ariane"><a href="<?= e(url(Wiki::BASE)) ?>">Aide</a> › <a href="<?= e(url($top['path'])) ?>"><?= e($top['title']) ?></a></nav>
      <?php endif; ?>
      <?php foreach ($sections as $s): ?>
        <section class="wk-section" id="<?= e($s['id']) ?>" data-wk-url="<?= e(url($s['path'])) ?>" data-wk-title="<?= e($s['title']) ?>">
          <?php if ($s['eyebrow'] !== ''): ?><p class="wk-eyebrow"><?= e($s['eyebrow']) ?></p><?php endif; ?>
          <div class="wk-content"><?= $s['html'] /* Markdown rendu par Wiki\Markdown : tout le texte y est échappé */ ?></div>
          <?php if ($s['source']): ?>
            <p class="wk-source muted small">Source : <code><?= e($s['source']) ?></code><?= $s['updated'] ? ' · mise à jour le ' . e(date('d/m/Y', $s['updated'])) : '' ?></p>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
      <?php if ($node['type'] === 'home' && $node['children']): ?>
        <div class="wk-cards">
          <?php foreach ($node['children'] as $c): ?>
            <a class="wk-card" href="<?= e(url($c['path'])) ?>"><strong><?= e($c['title']) ?></strong>
              <?php if ($c['type'] === 'cat'): ?><span class="muted small"><?= count($c['children']) ?> page<?= count($c['children']) > 1 ? 's' : '' ?></span><?php endif; ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <nav class="wk-pager" aria-label="Rubriques voisines">
        <?php if ($prev): ?><a class="wk-prev" href="<?= e(url($prev['path'])) ?>"><span class="muted small">‹ Rubrique précédente</span><strong><?= e($prev['title']) ?></strong></a><?php else: ?><span></span><?php endif; ?>
        <?php if ($next): ?><a class="wk-next" href="<?= e(url($next['path'])) ?>"><span class="muted small">Rubrique suivante ›</span><strong><?= e($next['title']) ?></strong></a><?php endif; ?>
      </nav>
      <p class="muted small">Pour compléter l'aide, modifiez le fichier indiqué sous chaque page dans le dépôt de DeepFind (voir « Contribuer à l'aide » sur l'accueil).</p>
    </article>
    <?php if ($hasToc): ?>
      <aside class="wk-toc" aria-label="Sur cette page" data-wk-toc>
        <strong>Sur cette page</strong>
        <?php foreach ($sections as $s): if (count($s['toc']) < 2) { continue; } ?>
          <ul data-wk-toc-for="<?= e($s['id']) ?>"<?= count($sections) > 1 ? ' hidden' : '' ?>><?php foreach ($s['toc'] as $h): ?><li class="wk-toc-<?= (int) $h['level'] ?>"><a href="#<?= e($h['id']) ?>"><?= e($h['text']) ?></a></li><?php endforeach; ?></ul>
        <?php endforeach; ?>
      </aside>
    <?php endif; ?>
  </main>
</div>
<script type="application/json" id="wk-index"><?= str_replace('</', '<\/', \DeepFind\Text::json(array_map(static fn ($p) => $p + ['url' => url($p['path'])], $search))) ?></script>
<script src="<?= e(asset('/assets/wiki.js')) ?>"></script>
</body>
</html>
