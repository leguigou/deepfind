<?php
/** Fenêtre « Détails » d'un produit de l'aperçu. Variables : $pr, $dlgId, $words, $attrLabels, $isDisabled */
$d = $pr['details'] ?? [];
$attrs = is_array($d['attr'] ?? null) ? $d['attr'] : [];
$variants = is_array($d['variants'] ?? null) ? $d['variants'] : [];
?>
<dialog id="<?= e($dlgId) ?>" class="modal" aria-labelledby="<?= e($dlgId) ?>-t">
  <div class="modal-head">
    <?php if (!empty($pr['image'])): ?><img class="pv-thumb pv-thumb-lg" src="<?= e($pr['image']) ?>" alt="" loading="lazy"><?php endif; ?>
    <div class="modal-title">
      <h2 id="<?= e($dlgId) ?>-t"><a href="<?= e($pr['url']) ?>" target="_blank" rel="noopener"><?= e($pr['name']) ?></a></h2>
      <span class="muted"><?= e($pr['brand'] ?? '') ?></span> · <code><?= e($pr['sku']) ?></code>
      <?php if (!empty($pr['type'])): ?> · <span class="muted"><?= e($pr['type']) ?></span><?php endif; ?>
      <?php if ($isDisabled): ?> <span class="badge badge-ko">désactivé</span><?php endif; ?>
      <?php if (!empty($pr['rule'])): ?> <span class="badge badge-info">règle : <?= e($pr['rule']) ?></span><?php endif; ?>
    </div>
    <button type="button" class="icon-btn modal-close" data-dialog-close aria-label="Fermer">✕</button>
  </div>
  <div class="modal-body">
    <h3>Pourquoi ce produit est trouvé</h3>
    <?php if ($words === []): ?>
      <p class="muted">Recherche vide : pas de mot à comparer.</p>
    <?php elseif (empty($pr['matched'])): ?>
      <p class="muted"><?= !empty($pr['rule']) || !empty($pr['exact_match'])
        ? 'Ajouté par une règle ou par la correspondance exacte du SKU, pas par les mots de la recherche.'
        : 'Aucun champ cherchable ne contient les mots recherchés (correspondance par synonyme ou résultat « approchant »).' ?></p>
    <?php else: ?>
      <table class="table compact kv">
        <tbody>
        <?php foreach ($pr['matched'] as $m): ?>
          <tr><th scope="row"><?= e($m['label']) ?><br><code class="muted"><?= e($m['field']) ?></code></th>
            <td><?php foreach ($m['values'] as $v): ?><div><?= highlight($v, $words) ?></div><?php endforeach; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if (!empty($pr['boost_rules'])): ?>
      <h3>Règles de boost appliquées</h3>
      <ul class="plain"><?php foreach ($pr['boost_rules'] as $br): ?><li><strong>×<?= e(rtrim(rtrim(number_format($br['boost'], 2, ',', ''), '0'), ',')) ?></strong> <?= e($br['name']) ?></li><?php endforeach; ?>
        <?php if (count($pr['boost_rules']) > 1): ?><li class="muted">Coefficient total : ×<?= fmt_n($pr['boost_factor'] ?? 1, 2) ?></li><?php endif; ?></ul>
    <?php endif; ?>

    <h3>Informations</h3>
    <dl class="kv-grid">
      <dt>Prix</dt><dd><?= !empty($pr['has_price_range']) ? 'dès ' : '' ?><?= fmt_n($pr['final_price'] ?? 0, 2) ?> €<?= !empty($pr['on_sale']) ? ' <s class="muted">' . fmt_n($pr['price'], 2) . ' €</s> -' . (int) $pr['discount_percent'] . ' %' : '' ?></dd>
      <dt>Stock</dt><dd><?= !empty($pr['in_stock']) ? 'en stock (' . (int) ($pr['salable_qty'] ?? 0) . ')' : 'rupture' ?></dd>
      <dt>Marge</dt><dd><?= fmt_n(100 * (float) ($pr['margin_rate'] ?? 0), 1) ?> %</dd>
      <dt>Ventes / clics 30 j</dt><dd><?= fmt_n($pr['sales_30d'] ?? 0) ?> / <?= fmt_n($pr['clicks_30d'] ?? 0) ?></dd>
      <dt>Score métier</dt><dd><?= fmt_n($pr['biz_score'] ?? 0, 1) ?></dd>
      <dt>Boost (flux / extranet / total)</dt><dd><?= sprintf('%+d', (int) ($pr['manual_boost'] ?? 0)) ?> / <?= sprintf('%+d', (int) ($d['admin_boost'] ?? 0)) ?> / <?= sprintf('%+d', (int) ($pr['boost_total'] ?? 0)) ?></dd>
      <?php if (isset($pr['relevance'])): ?><dt>Pertinence moteur</dt><dd><?= fmt_n($pr['relevance'], 3) ?></dd><?php endif; ?>
      <?php if (!empty($d['ean'])): ?><dt>EAN</dt><dd><code><?= e($d['ean']) ?></code></dd><?php endif; ?>
      <?php if (!empty($d['created_ts'])): ?><dt>Créé le</dt><dd><?= e(date('d/m/Y', (int) $d['created_ts'])) ?></dd><?php endif; ?>
      <?php if (!empty($d['search_keywords'])): ?><dt>Mots-clés</dt><dd><?= highlight((string) $d['search_keywords'], $words) ?></dd><?php endif; ?>
    </dl>

    <?php if (!empty($d['category_paths'])): ?>
      <h3>Catégories</h3>
      <ul class="plain"><?php foreach ((array) $d['category_paths'] as $c): ?><li><?= highlight((string) $c, $words) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>

    <h3>Attributs</h3>
    <?php if ($attrs === []): ?><p class="muted">Aucun attribut.</p><?php else: ?>
      <table class="table compact kv"><tbody>
        <?php foreach ($attrs as $code => $vals): ?>
          <tr><th scope="row"><?= e($attrLabels[$code] ?? $code) ?><?php if (isset($attrLabels[$code])): ?><br><code class="muted"><?= e($code) ?></code><?php endif; ?></th>
            <td><?= highlight(implode(', ', array_map('strval', (array) $vals)), $words) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>

    <?php if ($variants !== []): ?>
      <h3>Variantes (<?= count($variants) ?>)</h3>
      <div class="table-scroll"><table class="table compact">
        <thead><tr><th>SKU</th><th>EAN</th><th class="num">Prix</th><th>Stock</th><th>Attributs</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($variants, 0, 100) as $v): ?>
          <tr class="<?= (int) ($v['in_stock'] ?? 1) === 1 ? '' : 'dim' ?>">
            <td><code><?= highlight((string) ($v['sku'] ?? ''), $words) ?></code></td>
            <td><code><?= e($v['ean'] ?? '') ?></code></td>
            <td class="num"><?= fmt_n($v['final_price'] ?? 0, 2) ?> €</td>
            <td><?= (int) ($v['in_stock'] ?? 1) === 1 ? (int) ($v['salable_qty'] ?? 0) : 'rupture' ?></td>
            <td><?php $va = []; foreach ((array) ($v['attrs'] ?? []) as $c => $vv) { $va[] = ($attrLabels[$c] ?? $c) . ' : ' . implode(', ', array_map('strval', (array) $vv)); } ?><?= highlight(implode(' · ', $va), $words) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>

    <?php if (!empty($d['short_description'])): ?><h3>Description courte</h3><p><?= highlight((string) $d['short_description'], $words) ?></p><?php endif; ?>
    <?php if (!empty($d['description'])): ?>
      <h3>Description</h3><p class="pv-desc"><?= highlight(mb_strimwidth((string) $d['description'], 0, 1200, ' …', 'UTF-8'), $words) ?></p>
    <?php endif; ?>
  </div>
</dialog>
