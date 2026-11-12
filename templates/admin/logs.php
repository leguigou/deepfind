<form method="get" class="card form inline">
  <label>Contient <input name="q" value="<?= e($f['q']) ?>"></label>
  <label>Empreinte IP <input name="ip" value="<?= e($f['ip']) ?>" size="18" pattern="[a-f0-9]{16}"></label>
  <label>Période <select name="days"><?php foreach ([1 => '24 h', 7 => '7 j', 30 => '30 j', 90 => '90 j', 400 => 'Tout'] as $d => $l): ?><option value="<?= $d ?>" <?= (string) $f['days'] === (string) $d ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
  <label class="toggle toggle-sm"><input type="checkbox" role="switch" name="zero" value="1" <?= $f['zero'] ? 'checked' : '' ?><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">> Sans résultat</span></label>
  <label class="toggle toggle-sm"><input type="checkbox" role="switch" name="all" value="1" <?= $f['all'] ? 'checked' : '' ?><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">> Inclure la saisie intermédiaire</span></label>
  <button class="btn">Filtrer</button>
  <a class="btn btn-light" href="<?= e(url('/admin/logs/export', $f)) ?>">Exporter CSV</a>
</form>
<p class="muted"><?= fmt_n($total) ?> ligne(s). L'IP n'est jamais stockée en clair : empreinte (HMAC) + adresse tronquée. Conservation : voir Paramètres.</p>
<table class="table compact">
  <thead><tr><th>Date</th><th>Requête</th><th>Filtres</th><th class="num">Résultats</th><th class="num">ms</th><th>IP</th><th>User-agent</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['is_final'] ? '' : 'dim' ?>">
      <td><?= fmt_dt($r['created_at']) ?></td>
      <td><strong><?= e($r['query']) ?></strong></td>
      <td class="ellipsis"><code><?= e($r['filters']) ?></code></td>
      <td class="num <?= (int) $r['results'] === 0 ? 'ko' : '' ?>"><?= fmt_n($r['results']) ?></td>
      <td class="num"><?= (int) $r['took_ms'] ?></td>
      <td><a href="<?= e(url('/admin/logs', ['ip' => $r['ip_hash'], 'days' => $f['days']])) ?>" title="<?= e($r['ip_hash']) ?>"><?= e($r['ip_masked']) ?></a></td>
      <td class="ellipsis"><?= e($r['user_agent']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if ($pages > 1): ?>
<p class="pager">
  <?php if ($page > 1): ?><a class="btn btn-sm btn-light" href="<?= e(url('/admin/logs', ['page' => $page - 1] + $f)) ?>">← Précédent</a><?php endif; ?>
  Page <?= $page ?> / <?= $pages ?>
  <?php if ($page < $pages): ?><a class="btn btn-sm btn-light" href="<?= e(url('/admin/logs', ['page' => $page + 1] + $f)) ?>">Suivant →</a><?php endif; ?>
</p>
<?php endif; ?>
