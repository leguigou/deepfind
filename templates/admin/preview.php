<?php $sorts = ['relevance' => 'Pertinence', 'price_asc' => 'Prix croissant', 'price_desc' => 'Prix décroissant', 'newest' => 'Nouveautés',
    'margin_desc' => 'Rentabilité (marge)', 'popularity_desc' => 'Ventes 30 j', 'score_desc' => 'Score métier']; ?>
<p class="muted">Vue réservée à l'équipe : marge, score métier et règles appliquées sont visibles ici mais <strong>jamais envoyés au site public</strong>.
  <strong>Boost permanent</strong> : fait monter (ou descendre) le produit sur toutes les recherches. <strong>Épingler</strong> : impose une position pour cette recherche uniquement. <strong>Désactiver</strong> : retire le produit de toutes les recherches du site (il reste dans le flux et l'index). <strong>Détails</strong> : fiche du produit et champs où les mots ont été trouvés. Effet immédiat, la page se recharge sur la même recherche.</p>
<form method="get" class="card form inline">
  <label>Recherche <input name="q" value="<?= e($p['q'] ?? '') ?>" placeholder="palme, C4-PREDATOR…" autofocus></label>
  <label>Tri <select name="sort"><?php foreach ($sorts as $k => $l): ?><option value="<?= $k ?>" <?= ($p['sort'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <label>Marge min. (0 à 1) <input name="margin_min" value="<?= e($p['margin_min'] ?? '') ?>" size="6" placeholder="0.3"></label>
  <label class="toggle toggle-sm"><input type="checkbox" role="switch" name="in_stock" value="1" <?= !empty($p['in_stock']) ? 'checked' : '' ?>><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">En stock</span></label>
  <label class="toggle toggle-sm"><input type="checkbox" role="switch" name="on_sale" value="1" <?= !empty($p['on_sale']) ? 'checked' : '' ?>><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">En promotion</span></label>
  <button class="btn">Rechercher</button>
</form>
<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
<?php if ($result): ?>
  <?php if (!empty($result['approximate'])): ?><div class="flash flash-warn">Aucun résultat exact (tous les mots) : le visiteur voit « Aucun résultat exact » puis ces produits approchants. Ajoutez un synonyme si l'un d'eux correspond vraiment.</div><?php endif; ?>
  <p><strong><?= fmt_n($result['total']) ?></strong> produit(s) · moteur <?= (int) $result['engine_ms'] ?> ms
    <?= $result['redirect'] ? ' · redirection : <a href="' . e($result['redirect']) . '">' . e($result['redirect']) . '</a>' : '' ?></p>
  <div class="pv-grid">
    <aside class="card">
      <?php foreach ($result['facets'] as $f): ?>
        <h3><?= e($f['label']) ?></h3>
        <ul class="facet-list"><?php foreach (array_slice($f['values'], 0, 12) as $v): ?><li><span><?= e($v['value']) ?></span><span class="muted"><?= (int) $v['count'] ?></span></li><?php endforeach; ?></ul>
      <?php endforeach; ?>
      <h3>Articles</h3>
      <ul class="facet-list"><?php foreach ($result['posts'] as $post): ?><li><a href="<?= e($post['url']) ?>" target="_blank" rel="noopener"><?= e($post['title']) ?></a></li><?php endforeach; ?>
        <?php if (!$result['posts']): ?><li class="muted">Aucun</li><?php endif; ?></ul>
    </aside>
    <div class="pv-results">
    <div class="table-scroll">
    <table class="table compact pv-table">
      <thead><tr><th>#</th><th></th><th>Produit</th><th class="num">Prix</th><th>Stock</th><th class="num">Marge</th><th class="num" title="Ventes 30 j / clics 30 j">Ventes / clics</th><th class="num" title="Pertinence du moteur + bonus « mot au début du nom »">Pertinence</th><th class="num">Score métier</th></tr></thead>
      <tbody>
      <?php foreach ($result['products'] as $i => $pr): ?>
        <tr class="<?= $pr['in_stock'] ? '' : 'dim' ?>">
          <td><?= $i + 1 ?></td>
          <td><?php if ($pr['image']): ?><img class="pv-thumb" src="<?= e($pr['image']) ?>" alt="" loading="lazy"><?php endif; ?></td>
          <td class="pv-product">
            <a href="<?= e($pr['url']) ?>" target="_blank" rel="noopener"><?= e($pr['name']) ?></a><br><span class="muted"><?= e($pr['brand']) ?></span> · <code><?= e($pr['sku']) ?></code><?= !empty($pr['matched_variant']) ? ' <small>variante ' . e($pr['matched_variant']['sku']) . '</small>' : '' ?>
            <div class="pv-actions">
              <span class="pv-label">Boost permanent</span>
              <?php $cur = $adminBoosts[mb_strtoupper($pr['sku'], 'UTF-8')] ?? 0; ?>
            <form method="post" action="<?= e(url('/admin/boosts/save')) ?>" class="inline-boost">
              <?= csrf_field() ?><input type="hidden" name="sku" value="<?= e($pr['sku']) ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
              <select name="boost" data-autosubmit aria-label="Boost permanent de <?= e($pr['sku']) ?>" class="<?= $cur > 0 ? 'boost-up' : ($cur < 0 ? 'boost-down' : '') ?>">
                <?php for ($b = 10; $b >= -10; $b--): ?><option value="<?= $b ?>" <?= $b === $cur ? 'selected' : '' ?>><?= $b === 0 ? 'Aucun' : sprintf('%+d', $b) ?></option><?php endfor; ?>
              </select>
              <noscript><button class="btn btn-sm">OK</button></noscript>
            </form>
              <?php $pin = $pins[mb_strtoupper($pr['sku'], 'UTF-8')] ?? null; ?>
            <?php if ($pin): ?>
              <form method="post" action="<?= e(url('/admin/rules/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $pin['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
                <button class="btn btn-sm btn-light" title="Épinglé en position <?= (int) $pin['position'] ?>">📌 <?= (int) $pin['position'] ?> · retirer</button></form>
            <?php elseif (($p['q'] ?? '') !== ''): ?>
              <form method="post" action="<?= e(url('/admin/rules/add')) ?>"><?= csrf_field() ?>
                <input type="hidden" name="query" value="<?= e($p['q']) ?>"><input type="hidden" name="sku" value="<?= e($pr['sku']) ?>">
                <input type="hidden" name="action" value="pin"><input type="hidden" name="back" value="<?= e($back) ?>">
                <select name="position" aria-label="Position"><?php for ($pp = 1; $pp <= 10; $pp++): ?><option value="<?= $pp ?>"><?= $pp ?>e</option><?php endfor; ?></select>
                <button class="btn btn-sm btn-light" title="Afficher ce produit à cette position pour cette recherche">Épingler</button></form>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/admin/preview/toggle')) ?>"><?= csrf_field() ?>
              <input type="hidden" name="sku" value="<?= e($pr['sku']) ?>"><input type="hidden" name="state" value="off"><input type="hidden" name="back" value="<?= e($back) ?>">
              <button class="btn btn-sm btn-light btn-danger-soft" title="Retirer ce produit de toutes les recherches du site"
                data-confirm="Désactiver <?= e($pr['sku']) ?> ? Il n'apparaîtra plus dans aucune recherche du site (réactivable ici).">Désactiver</button></form>
            <button type="button" class="btn btn-sm btn-light" data-dialog-open="pv-d-<?= $i ?>">Détails</button>
            <?= !empty($pr['exact_match']) ? '<span class="badge badge-info">SKU exact</span>' : '' ?>
            <?= !empty($pr['rule']) ? '<span class="badge badge-info">' . e($pr['rule']) . '</span>' : '' ?>
            </div>
          </td>
          <td class="num"><?= $pr['has_price_range'] ? 'dès ' : '' ?><?= fmt_n($pr['final_price'], 2) ?> €<?= $pr['on_sale'] ? '<br><s class="muted">' . fmt_n($pr['price'], 2) . ' €</s> -' . (int) $pr['discount_percent'] . ' %' : '' ?></td>
          <td><?= $pr['in_stock'] ? '<span class="badge badge-ok">' . (int) ($pr['salable_qty'] ?? 0) . '</span>' : '<span class="badge badge-ko">rupture</span>' ?></td>
          <td class="num"><?= fmt_n(100 * (float) ($pr['margin_rate'] ?? 0), 1) ?> %</td>
          <td class="num"><?= fmt_n($pr['sales_30d'] ?? 0) ?> / <?= fmt_n($pr['clicks_30d'] ?? 0) ?></td>
          <td class="num" title="Moteur <?= isset($pr['relevance']) ? fmt_n($pr['relevance'], 2) : '—' ?> + début du nom <?= isset($pr['name_position']) ? fmt_n($pr['name_position'], 2) : '—' ?>">
            <?= isset($pr['final_score']) ? fmt_n($pr['final_score'], 2) : (isset($pr['relevance']) ? fmt_n($pr['relevance'], 2) : '—') ?>
            <?php if (isset($pr['name_position'])): ?><br><small class="muted">début nom <?= fmt_n($pr['name_position'], 2) ?></small><?php endif; ?></td>
          <td class="num"><?= fmt_n($pr['biz_score'] ?? 0, 1) ?><?= (int) ($pr['boost_total'] ?? 0) !== 0 ? '<br><small class="muted">boost ' . sprintf('%+d', (int) $pr['boost_total']) . '</small>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if (!empty($result['disabled'])): ?>
      <h3 class="pv-disabled-title">Produits désactivés correspondant à cette recherche (<?= count($result['disabled']) ?>)</h3>
      <p class="muted">Ils ne sont jamais renvoyés au site. Total désactivés dans ce projet et cette langue : <?= count($disabledRules) ?>.</p>
      <div class="table-scroll">
      <table class="table compact pv-table">
        <thead><tr><th></th><th>Produit</th><th class="num">Prix</th><th>Stock</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($result['disabled'] as $j => $pr): ?>
          <tr class="dim-soft">
            <td><?php if ($pr['image']): ?><img class="pv-thumb" src="<?= e($pr['image']) ?>" alt="" loading="lazy"><?php endif; ?></td>
            <td class="pv-product"><a href="<?= e($pr['url']) ?>" target="_blank" rel="noopener"><?= e($pr['name']) ?></a><br><span class="muted"><?= e($pr['brand']) ?></span> · <code><?= e($pr['sku']) ?></code> <span class="badge badge-ko">désactivé</span></td>
            <td class="num"><?= fmt_n($pr['final_price'], 2) ?> €</td>
            <td><?= $pr['in_stock'] ? '<span class="badge badge-ok">' . (int) ($pr['salable_qty'] ?? 0) . '</span>' : '<span class="badge badge-ko">rupture</span>' ?></td>
            <td><div class="pv-actions">
              <form method="post" action="<?= e(url('/admin/preview/toggle')) ?>"><?= csrf_field() ?>
                <input type="hidden" name="sku" value="<?= e($pr['sku']) ?>"><input type="hidden" name="state" value="on"><input type="hidden" name="back" value="<?= e($back) ?>">
                <button class="btn btn-sm">Activer</button></form>
              <button type="button" class="btn btn-sm btn-light" data-dialog-open="pv-x-<?= $j ?>">Détails</button>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
    </div>
  </div>
  <?php foreach ($result['products'] as $i => $pr) { $dlgId = 'pv-d-' . $i; $isDisabled = false; include __DIR__ . '/_preview_product.php'; } ?>
  <?php foreach ($result['disabled'] ?? [] as $j => $pr) { $dlgId = 'pv-x-' . $j; $isDisabled = true; include __DIR__ . '/_preview_product.php'; } ?>
<?php endif; ?>
