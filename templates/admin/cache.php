<?php
$totalEntries = array_sum(array_column($rows, 'entries'));
$totalBytes = array_sum(array_column($rows, 'bytes'));
$res = $rows['res'] ?? ['entries' => 0, 'bytes' => 0];
$hits = $stats['hits']; $misses = $stats['misses'];
$human = static fn (int $b): string => $b >= 1048576 ? fmt_n($b / 1048576, 1) . ' Mo' : ($b >= 1024 ? fmt_n($b / 1024, 1) . ' Ko' : fmt_n($b) . ' o');
?>
<p class="muted">Les réponses de recherche sont gardées <strong><?= $ttl ?> s</strong> (<a href="<?= e(url('/admin/settings')) ?>">Paramètres</a> › « Cache des réponses », 0 = désactivé) pour absorber les recherches identiques.
  Après chaque import, modification de règle ou de paramètre, <strong>tout le cache est invalidé automatiquement</strong> : les nouveaux résultats sont visibles immédiatement. La purge manuelle n'est utile qu'en cas de doute.</p>

<div class="cards">
  <div class="card kpi"><div class="kpi-label">Stockage</div><div class="kpi-value"><?= e(strtoupper($backend)) ?></div><div class="muted">version des données <?= e(substr($version, -8)) ?></div></div>
  <div class="card kpi"><div class="kpi-label">Résultats en cache</div><div class="kpi-value"><?= fmt_n($res['entries']) ?></div><div class="muted"><?= $human((int) $res['bytes']) ?></div></div>
  <div class="card kpi"><div class="kpi-label">Toutes les entrées</div><div class="kpi-value"><?= fmt_n($totalEntries) ?></div><div class="muted"><?= $human((int) $totalBytes) ?></div></div>
  <?php if ($stats['memory']): $m = $stats['memory']; ?>
  <div class="card kpi"><div class="kpi-label">Mémoire <?= e(strtoupper($backend)) ?></div><div class="kpi-value"><?= $human($m['used']) ?></div>
    <div class="muted"><?= $m['total'] > 0 ? 'sur ' . $human($m['total']) . ' (' . fmt_n(100 * $m['used'] / $m['total'], 1) . ' %)' : 'sans limite définie' ?></div></div>
  <?php endif; ?>
  <?php if ($hits !== null && $hits + $misses > 0): ?>
  <div class="card kpi"><div class="kpi-label">Taux de succès du cache</div><div class="kpi-value"><?= fmt_n(100 * $hits / ($hits + $misses), 1) ?> %</div><div class="muted">depuis le démarrage du serveur</div></div>
  <?php endif; ?>
</div>

<div class="actions">
  <form method="post" action="<?= e(url('/admin/cache/purge')) ?>"><?= csrf_field() ?><input type="hidden" name="scope" value="results">
    <button class="btn" data-confirm="Vider le cache des résultats de recherche ?">Vider le cache des résultats</button></form>
  <form method="post" action="<?= e(url('/admin/cache/purge')) ?>"><?= csrf_field() ?><input type="hidden" name="scope" value="all">
    <button class="btn btn-light" data-confirm="Vider tous les caches (résultats, règles, paramètres, clés) ? Les compteurs anti-robots sont conservés.">Vider tous les caches</button></form>
</div>

<h2>Détail par catégorie</h2>
<table class="table">
  <thead><tr><th>Catégorie</th><th>Rôle</th><th class="num">Entrées</th><th class="num">Taille</th><th>Purge « tous les caches »</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $cat => $r): ?>
    <tr><td><strong><?= e($r['label']) ?></strong><br><code><?= e($cat) ?></code></td><td class="muted"><?= e($r['help']) ?></td>
      <td class="num"><?= fmt_n($r['entries']) ?></td><td class="num"><?= $human((int) $r['bytes']) ?></td>
      <td><?= $r['purgeable'] ? '<span class="badge badge-ok">oui</span>' : '<span class="badge badge-info">conservé</span>' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="muted">Cache vide.</td></tr><?php endif; ?>
  </tbody>
</table>
<p class="muted small">Tailles : mémoire réellement occupée (APCu) ou taille des valeurs (Redis, MySQL). Avec APCu, le cache est propre au serveur web : les commandes en ligne de commande ne le voient pas, d'où l'invalidation par numéro de version.</p>
