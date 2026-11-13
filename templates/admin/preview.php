<?php $sorts = ['relevance' => 'Pertinence', 'price_asc' => 'Prix croissant', 'price_desc' => 'Prix décroissant', 'newest' => 'Nouveautés',
    'margin_desc' => 'Rentabilité (marge)', 'popularity_desc' => 'Ventes 30 j', 'score_desc' => 'Score métier']; ?>
<p class="muted">Vue réservée à l'équipe : marge, score métier et règles appliquées sont visibles ici mais <strong>jamais envoyés au site public</strong>.
  <strong>Boost</strong> : multiplie la pertinence du produit sur toutes les recherches (crée une <a href="<?= e(url('/admin/boost-rules')) ?>">règle de boost</a> « Produit SKU »). <strong>Épingler</strong> : impose une position pour cette recherche uniquement. <strong>Désactiver</strong> : retire le produit de toutes les recherches du site (il reste dans le flux et l'index). <strong>Détails</strong> : fiche du produit et champs où les mots ont été trouvés. Effet immédiat, la page se recharge sur la même recherche.</p>
<form method="get" class="card form inline">
  <label>Recherche <input name="q" value="<?= e($p['q'] ?? '') ?>" placeholder="palme, C4-PREDATOR…" autofocus></label>
  <label>Tri <select name="sort"><?php foreach ($sorts as $k => $l): ?><option value="<?= $k ?>" <?= ($p['sort'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <label>Marge min. (0 à 1) <input name="margin_min" value="<?= e($p['margin_min'] ?? '') ?>" size="6" placeholder="0.3"></label>
  <label class="toggle toggle-sm"><input type="checkbox" role="switch" name="in_stock" value="1" <?= !empty($p['in_stock']) ? 'checked' : '' ?>><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">En stock</span></label>
  <label class="toggle toggle-sm"><input type="checkbox" role="switch" name="on_sale" value="1" <?= !empty($p['on_sale']) ? 'checked' : '' ?>><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">En promotion</span></label>
  <label class="toggle toggle-sm" title="Afficher aussi les produits désactivés qui correspondent à la recherche"><input type="checkbox" role="switch" name="show_disabled" value="1" <?= !empty($p['show_disabled']) ? 'checked' : '' ?>><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">Désactivés</span></label>
  <?php foreach (['brand', 'category'] as $fk): foreach ((array) ($p[$fk] ?? []) as $fv): ?><input type="hidden" name="<?= $fk ?>[]" value="<?= e((string) $fv) ?>"><?php endforeach; endforeach; ?>
  <?php foreach ((array) ($p['attr'] ?? []) as $ac => $avs): foreach ((array) $avs as $av): ?><input type="hidden" name="attr[<?= e((string) $ac) ?>][]" value="<?= e((string) $av) ?>"><?php endforeach; endforeach; ?>
  <button class="btn">Rechercher</button>
</form>
<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
<?php if ($result): ?>
  <?php if (!empty($result['approximate'])): ?><div class="flash flash-warn">Aucun résultat exact (tous les mots) : le visiteur voit « Aucun résultat exact » puis ces produits approchants. Ajoutez un synonyme si l'un d'eux correspond vraiment.</div><?php endif; ?>
  <?php $nDis = count($result['disabled'] ?? []); $showDis = !empty($p['show_disabled']); ?>
  <p><strong><?= fmt_n($result['total']) ?></strong> produit(s) · moteur <?= (int) $result['engine_ms'] ?> ms
    <?php if ($nDis > 0): ?> · <a href="<?= e(url('/admin/preview', $showDis ? array_diff_key($keep, ['show_disabled' => 1]) : $keep + ['show_disabled' => 1])) ?>" class="badge badge-ko"><?= $nDis ?> désactivé(s) correspondant(s)<?= $showDis ? ', inclus dans la liste : masquer' : ' : afficher dans la liste' ?></a><?php endif; ?>
    · <a href="<?= e(url('/admin/disabled')) ?>">liste complète des désactivés (<?= count($disabledRules) ?>)</a>
    <?= $result['redirect'] ? ' · redirection : <a href="' . e($result['redirect']) . '">' . e($result['redirect']) . '</a>' : '' ?></p>
  <div class="pv-grid">
    <aside class="card pv-facets">
      <?php
        // Lien qui ajoute ou retire une valeur de filtre (marque, catégorie, attribut), les autres paramètres étant conservés
        $facetUrl = static function (string $code, string $value) use ($keep): string {
            $q = $keep;
            if (str_starts_with($code, 'attr.')) {
                $c = substr($code, 5);
                $cur = (array) ($q['attr'][$c] ?? []);
                $cur = in_array($value, $cur, true) ? array_values(array_diff($cur, [$value])) : [...$cur, $value];
                if ($cur) { $q['attr'][$c] = $cur; } else { unset($q['attr'][$c]); }
            } else {
                $cur = (array) ($q[$code] ?? []);
                $cur = in_array($value, $cur, true) ? array_values(array_diff($cur, [$value])) : [...$cur, $value];
                if ($cur) { $q[$code] = $cur; } else { unset($q[$code]); }
            }
            return url('/admin/preview', array_filter($q, static fn ($v) => $v !== [] && $v !== null));
        };
        $hasFacet = !empty($keep['brand']) || !empty($keep['category']) || !empty($keep['attr']);
      ?>
      <?php if ($hasFacet): ?><p class="pv-clear"><a href="<?= e(url('/admin/preview', array_diff_key($keep, ['brand' => 1, 'category' => 1, 'attr' => 1]))) ?>">✕ Effacer les filtres</a></p><?php endif; ?>
      <?php foreach ($result['facets'] as $f): ?>
        <h3><?= e($f['label']) ?></h3>
        <ul class="facet-list"><?php foreach (array_slice($f['values'], 0, 12) as $v): ?>
          <li><a class="pv-fv<?= $v['selected'] ? ' on' : '' ?>" href="<?= e($facetUrl($f['code'], $v['value'])) ?>" aria-pressed="<?= $v['selected'] ? 'true' : 'false' ?>"><span class="pv-check" aria-hidden="true"></span><span class="pv-fv-label"><?= e($v['value']) ?></span><span class="muted"><?= (int) $v['count'] ?></span></a></li>
        <?php endforeach; ?></ul>
      <?php endforeach; ?>
      <h3>Articles</h3>
      <ul class="facet-list"><?php foreach ($result['posts'] as $post): ?><li><a href="<?= e($post['url']) ?>" target="_blank" rel="noopener"><?= e($post['title']) ?></a></li><?php endforeach; ?>
        <?php if (!$result['posts']): ?><li class="muted">Aucun</li><?php endif; ?></ul>
    </aside>
    <div class="pv-results">
    <div class="table-scroll">
    <table class="table compact pv-table">
      <thead><tr><th>#</th><th></th><th>Produit</th><th class="num">Prix</th><th>Stock</th><th class="num">Marge</th><th class="num" title="Ventes 30 j (flux) / clics 30 j dans la recherche (en direct)">Ventes / clics</th><th class="num" title="(Pertinence du moteur + bonus « mot au début du nom ») × règles de boost">Pertinence</th><th class="num">Score métier</th></tr></thead>
      <tbody>
      <?php $rank = 0; foreach ($result['products'] as $i => $pr): $off = !empty($pr['disabled']); if (!$off) { $rank++; } ?>
        <tr class="<?= $off ? 'pv-row-disabled' : ($pr['in_stock'] ? '' : 'dim') ?>">
          <td><?= $off ? '<span class="muted" title="Désactivé : non affiché sur le site">—</span>' : $rank ?></td>
          <td><?php if ($pr['image']): ?><img class="pv-thumb" src="<?= e($pr['image']) ?>" alt="" loading="lazy"><?php endif; ?></td>
          <td class="pv-product">
            <a href="<?= e($pr['url']) ?>" target="_blank" rel="noopener"><?= e($pr['name']) ?></a><br><span class="muted"><?= e($pr['brand']) ?></span> · <code><?= e($pr['sku']) ?></code><?= $off ? ' <span class="badge badge-ko">désactivé</span>' : '' ?><?= !empty($pr['matched_variant']) ? ' <small>variante ' . e($pr['matched_variant']['sku']) . '</small>' : '' ?>
            <div class="pv-actions">
              <span class="pv-label">Boost</span>
              <?php $cur = (float) ($adminBoosts[mb_strtoupper($pr['sku'], 'UTF-8')] ?? 1.0); $curKey = rtrim(rtrim(number_format($cur, 2, '.', ''), '0'), '.'); ?>
            <form method="post" action="<?= e(url('/admin/boost-rules/product')) ?>" class="inline-boost">
              <?= csrf_field() ?><input type="hidden" name="sku" value="<?= e($pr['sku']) ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
              <select name="boost" data-autosubmit aria-label="Boost du produit <?= e($pr['sku']) ?> sur toutes les recherches" title="Règle de boost « Produit <?= e($pr['sku']) ?> » sur toutes les recherches" class="<?= $cur > 1 ? 'boost-up' : ($cur < 1 ? 'boost-down' : '') ?>">
                <?php $opts = \DeepFind\Rules\BoostRules::QUICK; if (!isset($opts[$curKey])) { $opts = [$curKey => '×' . str_replace('.', ',', $curKey)] + $opts; } ?>
                <?php foreach ($opts as $v => $l): ?><option value="<?= e((string) $v) ?>" <?= (string) $v === $curKey ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
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
              <input type="hidden" name="sku" value="<?= e($pr['sku']) ?>"><input type="hidden" name="state" value="<?= $off ? 'on' : 'off' ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
              <?php if ($off): ?>
              <button class="btn btn-sm" title="Faire réapparaître ce produit dans les recherches du site">Activer</button></form>
              <?php else: ?>
              <button class="btn btn-sm btn-light btn-danger-soft" title="Retirer ce produit de toutes les recherches du site"
                data-confirm="Désactiver <?= e($pr['sku']) ?> ? Il n'apparaîtra plus dans aucune recherche du site (réactivable ici).">Désactiver</button></form>
              <?php endif; ?>
            <button type="button" class="btn btn-sm btn-light" data-dialog-open="pv-d-<?= $i ?>">Détails</button>
            <?= !empty($pr['exact_match']) ? '<span class="badge badge-info">SKU exact</span>' : '' ?>
            <?= !empty($pr['rule']) ? '<span class="badge badge-info">' . e($pr['rule']) . '</span>' : '' ?>
            <?php foreach ($pr['boost_rules'] ?? [] as $br): ?><span class="badge <?= $br['boost'] >= 1 ? 'badge-ok' : 'badge-ko' ?> pv-boost-rule" title="Règle de boost">×<?= e(rtrim(rtrim(number_format($br['boost'], 2, ',', ''), '0'), ',')) ?> <?= e($br['name']) ?></span><?php endforeach; ?>
            </div>
          </td>
          <td class="num"><?= $pr['has_price_range'] ? 'dès ' : '' ?><?= fmt_n($pr['final_price'], 2) ?> €<?= $pr['on_sale'] ? '<br><s class="muted">' . fmt_n($pr['price'], 2) . ' €</s> -' . (int) $pr['discount_percent'] . ' %' : '' ?></td>
          <td><?= $pr['in_stock'] ? '<span class="badge badge-ok">' . (int) ($pr['salable_qty'] ?? 0) . '</span>' : '<span class="badge badge-ko">rupture</span>' ?></td>
          <td class="num"><?= fmt_n(100 * (float) ($pr['margin_rate'] ?? 0), 1) ?> %</td>
          <?php $live = $liveClicks[(string) $pr['id']] ?? 0; $inScore = (int) ($pr['clicks_30d'] ?? 0); ?>
          <td class="num" title="Clics enregistrés sur 30 jours : <?= $live ?>. Pris en compte dans le score métier : <?= $inScore ?> (mis à jour au recalcul des scores)."><?= fmt_n($pr['sales_30d'] ?? 0) ?> / <?= fmt_n($live) ?><?= $live !== $inScore ? '<br><small class="muted">score : ' . fmt_n($inScore) . '</small>' : '' ?></td>
          <td class="num" title="Moteur <?= isset($pr['relevance']) ? fmt_n($pr['relevance'], 2) : '—' ?> + début du nom <?= isset($pr['name_position']) ? fmt_n($pr['name_position'], 2) : '—' ?><?= isset($pr['boost_factor']) ? ', × règles de boost ' . fmt_n($pr['boost_factor'], 2) : '' ?>">
            <?= isset($pr['final_score']) ? fmt_n($pr['final_score'], 2) : (isset($pr['relevance']) ? fmt_n($pr['relevance'], 2) : '—') ?>
            <?php if (isset($pr['name_position'])): ?><br><small class="muted">début nom <?= fmt_n($pr['name_position'], 2) ?></small><?php endif; ?></td>
          <td class="num"><?= fmt_n($pr['biz_score'] ?? 0, 1) ?><?= (int) ($pr['boost_total'] ?? 0) !== 0 ? '<br><small class="muted">boost ' . sprintf('%+d', (int) $pr['boost_total']) . '</small>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    </div>
  </div>
  <?php foreach ($result['products'] as $i => $pr) { $dlgId = 'pv-d-' . $i; $isDisabled = !empty($pr['disabled']); include __DIR__ . '/_preview_product.php'; } ?>
<?php endif; ?>
