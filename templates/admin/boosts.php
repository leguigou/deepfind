<p class="muted">Langue <?= e(strtoupper($lang)) ?>. Un boost met un produit <strong>en avant (+1 à +10)</strong> ou <strong>en retrait (-1 à -10)</strong> sur toutes les recherches où il apparaît.
  Il s'ajoute au boost éventuel du flux (total borné à ±10) et compte pour <strong><?= fmt_n($weight, 1) ?> point(s) par niveau</strong> dans le score métier
  (poids réglable dans <a href="<?= e(url('/admin/relevance')) ?>">Pertinence &amp; classement</a>). Appliqué <strong>immédiatement</strong>.
  Pour imposer une position sur une requête précise, utilisez plutôt les <a href="<?= e(url('/admin/rules')) ?>">règles de résultats</a>.</p>

<form method="post" action="<?= e(url('/admin/boosts/save')) ?>" class="card form">
  <?= csrf_field() ?>
  <div class="grid4">
    <label>SKU du produit parent (plusieurs possibles) <input name="sku" value="<?= e($prefill) ?>" required placeholder="C4-PREDATOR"></label>
    <label>Boost
      <select name="boost">
        <?php for ($b = 10; $b >= -10; $b--): if ($b === 0) { continue; } ?>
          <option value="<?= $b ?>" <?= $b === 3 ? 'selected' : '' ?>><?= $b > 0 ? '+' . $b . ' (en avant)' : $b . ' (en retrait)' ?></option>
        <?php endfor; ?>
      </select>
    </label>
    <label>Début (facultatif) <input type="date" name="starts_at"></label>
    <label>Fin (facultatif) <input type="date" name="ends_at"></label>
  </div>
  <label>Note (facultative) <input name="note" placeholder="Opération salon de la plongée, fin de série…"></label>
  <button class="btn">Enregistrer le boost</button>
  <p class="muted small">Astuce : dans l'<a href="<?= e(url('/admin/preview')) ?>">aperçu interne</a>, le bouton « Booster » de chaque produit pré-remplit ce formulaire.</p>
</form>

<table class="table">
  <thead><tr><th></th><th>Produit</th><th>SKU</th><th class="num">Boost</th><th class="num">Score actuel</th><th>Période</th><th>Note</th><th></th></tr></thead>
  <tbody>
  <?php $today = date('Y-m-d'); foreach ($rows as $r): $d = $info[mb_strtoupper($r['sku'], 'UTF-8')] ?? null;
      $inactive = ($r['ends_at'] && $r['ends_at'] < $today) || ($r['starts_at'] && $r['starts_at'] > $today); ?>
    <tr class="<?= $inactive ? 'dim' : '' ?>">
      <td><?php if (!empty($d['image'])): ?><img class="pv-thumb" src="<?= e($d['image']) ?>" alt="" loading="lazy"><?php endif; ?></td>
      <td><?= $d ? e($d['name']) : '<span class="muted">Absent de l\'index</span>' ?></td>
      <td><code><?= e($r['sku']) ?></code></td>
      <td class="num"><span class="badge <?= (int) $r['boost'] > 0 ? 'badge-ok' : 'badge-ko' ?>"><?= (int) $r['boost'] > 0 ? '+' : '' ?><?= (int) $r['boost'] ?></span></td>
      <td class="num"><?= $d ? fmt_n($d['biz_score'] ?? 0, 1) : '—' ?></td>
      <td><?= $r['starts_at'] || $r['ends_at'] ? e(($r['starts_at'] ?: '…') . ' → ' . ($r['ends_at'] ?: '…')) : 'Permanent' ?><?= $inactive ? ' <span class="badge badge-warn">inactif</span>' : '' ?></td>
      <td class="muted"><?= e($r['note']) ?></td>
      <td class="actions"><form method="post" action="<?= e(url('/admin/boosts/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="Supprimer ce boost ?">Supprimer</button></form></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted">Aucun boost.</td></tr><?php endif; ?>
  </tbody>
</table>
<p class="muted small">Un boost daté démarre ou s'arrête lors du recalcul nocturne des scores (ou du prochain import).</p>
