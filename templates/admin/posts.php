<?php
/** Catalogues › Articles de blog. @var list<array> $rows @var int $total @var int $all @var array<string,int> $cats @var string $q @var string $cat
 *  @var int $page @var int $perPage @var ?string $error @var string $lang */
$query = array_filter(['q' => $q, 'cat' => $cat], static fn ($v) => $v !== '');
?>
<p class="muted">Articles importés depuis le flux du blog (langue <?= e(strtoupper($lang)) ?>). Ce sont ceux que la recherche du site propose
  sous « Articles de blog » quand le texte tapé correspond à leur titre, catégorie, étiquettes, résumé ou contenu.
  Nombre affiché sur le site : <a href="<?= e(url('/admin/settings')) ?>">Paramètres</a> (« Nombre d'articles de blog affichés »).</p>
<?php if ($error): ?><div class="flash flash-warn"><?= e($error) ?></div><?php else: ?>
<form method="get" action="<?= e(url('/admin/posts')) ?>" class="card form jr-filters">
  <label>Rechercher <input type="search" name="q" value="<?= e($q) ?>" placeholder="ex. piscine (comme sur le site)"></label>
  <label>Catégorie <select name="cat"><option value="">Toutes (<?= fmt_n($all) ?>)</option>
    <?php foreach ($cats as $name => $n): ?><option value="<?= e((string) $name) ?>"<?= $cat === (string) $name ? ' selected' : '' ?>><?= e((string) $name) ?> (<?= fmt_n($n) ?>)</option><?php endforeach; ?></select></label>
  <button class="btn btn-sm">Afficher</button>
  <?php if ($query !== []): ?><a class="btn btn-sm btn-light" href="<?= e(url('/admin/posts')) ?>">Tout afficher</a><?php endif; ?>
</form>
<p><strong><?= fmt_n($total) ?></strong> article<?= $total > 1 ? 's' : '' ?><?= $query !== [] ? ' sur ' . fmt_n($all) : '' ?><?= $q !== '' ? ', dans l\'ordre de la recherche du site' : ', du plus récent au plus ancien' ?>.</p>
<div class="table-wrap">
<table class="table pst-table">
  <thead><tr><th></th><th>Article</th><th>Catégories</th><th>Publié le</th><th class="actions"></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="pst-img"><?php if (!empty($r['image'])): ?><img src="<?= e((string) $r['image']) ?>" alt="" loading="lazy"><?php else: ?><span class="pst-noimg" title="Pas d'image dans le flux">—</span><?php endif; ?></td>
      <td><strong><?= e((string) $r['title']) ?></strong><br><a class="small" href="<?= e((string) $r['url']) ?>" target="_blank" rel="noopener"><?= e((string) preg_replace('~^https?://[^/]+~', '', (string) $r['url'])) ?></a>
        <?php if (!empty($r['excerpt'])): ?><br><small class="muted"><?= e(mb_strimwidth((string) $r['excerpt'], 0, 160, '…', 'UTF-8')) ?></small><?php endif; ?>
        <?php if (!empty($r['author'])): ?><br><small class="muted">par <?= e((string) $r['author']) ?></small><?php endif; ?></td>
      <td><?php foreach ((array) ($r['categories'] ?? []) as $c): ?><span class="badge badge-info"><?= e((string) $c) ?></span> <?php endforeach; ?>
        <?php foreach ((array) ($r['tags'] ?? []) as $t): ?><span class="badge">#<?= e((string) $t) ?></span> <?php endforeach; ?></td>
      <td><?= !empty($r['published_ts']) ? e(date('d/m/Y', (int) $r['published_ts'])) : '<span class="muted">—</span>' ?></td>
      <td class="actions"><a class="btn btn-sm btn-light" href="<?= e((string) $r['url']) ?>" target="_blank" rel="noopener"><?= admin_icon('external-link') ?> Ouvrir</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="5" class="muted">Aucun article<?= $query !== [] ? ' pour ces critères' : '' ?>.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php $pages = (int) ceil($total / $perPage); if ($pages > 1): ?>
  <nav class="pager" aria-label="Pages">
    <?php if ($page > 1): ?><a class="btn btn-sm btn-light" href="<?= e(url('/admin/posts', ['page' => $page - 1] + $query)) ?>">‹ Précédents</a><?php endif; ?>
    <span class="muted small">Page <?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn btn-sm btn-light" href="<?= e(url('/admin/posts', ['page' => $page + 1] + $query)) ?>">Suivants ›</a><?php endif; ?>
  </nav>
<?php endif; ?>
<?php endif; ?>
