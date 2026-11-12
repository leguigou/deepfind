<?php $sorts = ['relevance' => 'Pertinence', 'price_asc' => 'Prix croissant', 'price_desc' => 'Prix décroissant', 'newest' => 'Nouveautés',
    'margin_desc' => 'Rentabilité (marge)', 'popularity_desc' => 'Ventes 30 j', 'score_desc' => 'Score métier']; ?>
<p class="muted">Vue réservée à l'équipe : marge, score métier et règles appliquées sont visibles ici mais <strong>jamais envoyés au site public</strong>.</p>
<form method="get" class="card form inline">
  <label>Recherche <input name="q" value="<?= e($p['q'] ?? '') ?>" placeholder="palme, C4-PREDATOR…" autofocus></label>
  <label>Tri <select name="sort"><?php foreach ($sorts as $k => $l): ?><option value="<?= $k ?>" <?= ($p['sort'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <label>Marge min. (0 à 1) <input name="margin_min" value="<?= e($p['margin_min'] ?? '') ?>" size="6" placeholder="0.3"></label>
  <label class="check"><input type="checkbox" name="in_stock" value="1" <?= !empty($p['in_stock']) ? 'checked' : '' ?>> En stock</label>
  <label class="check"><input type="checkbox" name="on_sale" value="1" <?= !empty($p['on_sale']) ? 'checked' : '' ?>> En promotion</label>
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
    <table class="table compact">
      <thead><tr><th>#</th><th></th><th>Produit</th><th>SKU</th><th class="num">Prix</th><th>Stock</th><th class="num">Marge</th><th class="num">Ventes 30 j</th><th class="num">Clics 30 j</th><th class="num">Boost</th><th class="num">Pertinence</th><th class="num">Score</th><th>Règle</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($result['products'] as $i => $pr): ?>
        <tr class="<?= $pr['in_stock'] ? '' : 'dim' ?>">
          <td><?= $i + 1 ?></td>
          <td><?php if ($pr['image']): ?><img class="pv-thumb" src="<?= e($pr['image']) ?>" alt="" loading="lazy"><?php endif; ?></td>
          <td><a href="<?= e($pr['url']) ?>" target="_blank" rel="noopener"><?= e($pr['name']) ?></a><br><span class="muted"><?= e($pr['brand']) ?></span></td>
          <td><code><?= e($pr['sku']) ?></code><?= !empty($pr['matched_variant']) ? '<br><small>variante ' . e($pr['matched_variant']['sku']) . '</small>' : '' ?></td>
          <td class="num"><?= $pr['has_price_range'] ? 'dès ' : '' ?><?= fmt_n($pr['final_price'], 2) ?> €<?= $pr['on_sale'] ? '<br><s class="muted">' . fmt_n($pr['price'], 2) . ' €</s> -' . (int) $pr['discount_percent'] . ' %' : '' ?></td>
          <td><?= $pr['in_stock'] ? '<span class="badge badge-ok">' . (int) ($pr['salable_qty'] ?? 0) . '</span>' : '<span class="badge badge-ko">rupture</span>' ?></td>
          <td class="num"><?= fmt_n(100 * (float) ($pr['margin_rate'] ?? 0), 1) ?> %</td>
          <td class="num"><?= fmt_n($pr['sales_30d'] ?? 0) ?></td>
          <td class="num"><?= fmt_n($pr['clicks_30d'] ?? 0) ?></td>
          <td class="num"><?= (int) ($pr['boost_total'] ?? 0) !== 0 ? sprintf('%+d', (int) $pr['boost_total']) : '—' ?></td>
          <td class="num" title="Score de pertinence texte du moteur (0 à 1)"><?= isset($pr['relevance']) ? fmt_n($pr['relevance'], 2) : '—' ?></td>
          <td class="num"><?= fmt_n($pr['biz_score'] ?? 0, 1) ?></td>
          <td><?= !empty($pr['exact_match']) ? '<span class="badge badge-info">SKU exact</span>' : '' ?><?= isset($pr['rule']) ? '<span class="badge badge-warn">' . e($pr['rule']) . '</span>' : '' ?></td>
          <td><a class="btn btn-sm btn-light" href="<?= e(url('/admin/boosts', ['sku' => $pr['sku']])) ?>">Booster</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
