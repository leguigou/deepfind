<p>
  <a class="btn" href="<?= e(url('/admin/sources/edit', ['kind' => 'products'])) ?>">+ Flux produits</a>
  <a class="btn btn-light" href="<?= e(url('/admin/sources/edit', ['kind' => 'posts'])) ?>">+ Flux articles (blog)</a>
</p>
<?php if (!$sources): ?>
  <p class="muted">Aucune source. Créez d'abord le flux produits FR (format décrit dans le README, section « Formats des flux »).</p>
<?php else: ?>
<table class="table">
  <thead><tr><th>Code</th><th>Type</th><th>Langue</th><th>Flux complet</th><th>Flux delta</th><th>Dernier import</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($sources as $s): [$st, $at, $n] = array_pad(explode('|', (string) $s['last_run']), 3, ''); ?>
    <tr>
      <td><a href="<?= e(url('/admin/sources/edit', ['id' => $s['id']])) ?>"><strong><?= e($s['code']) ?></strong></a><?= $s['active'] ? '' : ' <span class="badge badge-warn">inactif</span>' ?></td>
      <td><?= $s['kind'] === 'posts' ? 'Articles' : 'Produits' ?></td>
      <td><?= e(strtoupper($s['lang'])) ?></td>
      <td class="ellipsis"><code><?= e($s['url']) ?></code></td>
      <td class="ellipsis"><?= $s['delta_url'] ? '<code>' . e($s['delta_url']) . '</code>' : '<span class="muted">—</span>' ?></td>
      <td><?= $st ? badge($st) . ' ' . fmt_dt($at) . ($n !== '' ? ' · ' . fmt_n((int) $n) . ' doc.' : '') : '<span class="muted">jamais</span>' ?></td>
      <td class="actions">
        <a class="btn btn-sm btn-light" href="<?= e(url('/admin/sources/test', ['id' => $s['id']])) ?>">Tester</a>
        <form method="post" action="<?= e(url('/admin/sources/import')) ?>"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="type" value="full">
          <button class="btn btn-sm" data-confirm="Lancer un import complet de <?= e($s['code']) ?> ?">Import complet</button></form>
        <?php if ($s['delta_url'] && $s['kind'] === 'products'): ?>
        <form method="post" action="<?= e(url('/admin/sources/import')) ?>"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="type" value="delta">
          <button class="btn btn-sm btn-light">Delta</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
