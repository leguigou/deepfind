<?php $reasons = ['rate_minute' => 'Trop de requêtes / minute', 'rate_hour' => 'Trop de requêtes / heure', 'same_query' => 'Même requête répétée', 'user_agent' => 'User-agent de robot']; ?>
<div class="cards">
  <?php foreach ($thresholds as $l => $v): ?><div class="card kpi"><div class="kpi-label"><?= e($l) ?></div><div class="kpi-value"><?= fmt_n($v) ?></div></div><?php endforeach; ?>
</div>
<p class="muted">Seuils modifiables dans <a href="<?= e(url('/admin/settings')) ?>">Paramètres</a>. Au-delà d'un seuil : réponse 429 (le widget patiente). Après plusieurs minutes de dépassement, ou en cas de rafale massive : blocage automatique (403).</p>

<div class="grid2">
  <form method="post" action="<?= e(url('/admin/bots/block')) ?>" class="card form">
    <?= csrf_field() ?>
    <h3>Bloquer une IP manuellement</h3>
    <label>Adresse IP <input name="ip" required placeholder="203.0.113.10"></label>
    <div class="grid2">
      <label>Durée <select name="minutes"><option value="60">1 heure</option><option value="1440" selected>24 heures</option><option value="10080">7 jours</option><option value="0">Définitif</option></select></label>
      <label>Motif <input name="reason" placeholder="Scraping"></label>
    </div>
    <button class="btn">Bloquer</button>
  </form>
  <div class="card">
    <h3>Alertes sur la période</h3>
    <table class="table compact"><thead><tr><th>Motif</th><th class="num">Alertes</th><th class="num">IP distinctes</th></tr></thead><tbody>
    <?php foreach ($eventStats as $s): ?><tr><td><?= e($reasons[$s['reason']] ?? $s['reason']) ?></td><td class="num"><?= fmt_n($s['n']) ?></td><td class="num"><?= fmt_n($s['ips']) ?></td></tr><?php endforeach; ?>
    <?php if (!$eventStats): ?><tr><td colspan="3" class="muted">Aucune alerte.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>

<h2>Blocages actifs</h2>
<table class="table compact">
  <thead><tr><th>IP</th><th>Empreinte</th><th>Type</th><th>Motif</th><th>Depuis</th><th>Jusqu'au</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($blocks as $b): ?>
    <tr><td><?= e($b['ip_masked'] ?: '—') ?></td><td><code><?= e($b['ip_hash']) ?></code></td><td><?= badge($b['source']) ?></td><td><?= e($b['reason']) ?></td>
      <td><?= fmt_dt($b['created_at']) ?></td><td><?= $b['blocked_until'] ? fmt_dt($b['blocked_until']) : 'Définitif' ?></td>
      <td class="actions"><form method="post" action="<?= e(url('/admin/bots/unblock')) ?>"><?= csrf_field() ?><input type="hidden" name="ip_hash" value="<?= e($b['ip_hash']) ?>"><button class="btn btn-sm btn-light">Débloquer</button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$blocks): ?><tr><td colspan="7" class="muted">Aucune IP bloquée.</td></tr><?php endif; ?>
  </tbody>
</table>

<h2>IP les plus actives
  <?php foreach ([1 => '1 h', 6 => '6 h', 24 => '24 h', 168 => '7 j'] as $h => $l): ?>
    <a class="btn btn-sm <?= $hours === $h ? '' : 'btn-light' ?>" href="<?= e(url('/admin/bots', ['hours' => $h])) ?>"><?= $l ?></a>
  <?php endforeach; ?>
</h2>
<p class="muted">Indices de robot : beaucoup de recherches, peu de requêtes distinctes (ou au contraire des milliers de requêtes différentes), activité continue, user-agent inhabituel.</p>
<table class="table compact">
  <thead><tr><th>IP</th><th class="num">Recherches</th><th class="num">Distinctes</th><th class="num">Alertes</th><th>Première</th><th>Dernière</th><th>User-agent</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($top as $t): ?>
    <tr>
      <td><a href="<?= e(url('/admin/logs', ['ip' => $t['ip_hash'], 'days' => 7, 'all' => 1])) ?>" title="<?= e($t['ip_hash']) ?>"><?= e($t['ip_masked']) ?></a></td>
      <td class="num"><?= fmt_n($t['n']) ?></td><td class="num"><?= fmt_n($t['uniq']) ?></td>
      <td class="num <?= $t['events'] ? 'ko' : '' ?>"><?= fmt_n($t['events']) ?></td>
      <td><?= fmt_dt($t['first_at']) ?></td><td><?= fmt_dt($t['last_at']) ?></td><td class="ellipsis"><?= e($t['ua']) ?></td>
      <td class="actions"><?php if ($t['blocked']): ?><span class="badge badge-ko">bloquée</span><?php else: ?>
        <form method="post" action="<?= e(url('/admin/bots/block')) ?>"><?= csrf_field() ?><input type="hidden" name="ip_hash" value="<?= e($t['ip_hash']) ?>"><input type="hidden" name="minutes" value="1440"><input type="hidden" name="reason" value="Bloquée depuis le classement des IP">
          <button class="btn btn-sm btn-danger" data-confirm="Bloquer cette IP 24 h ?">Bloquer 24 h</button></form><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$top): ?><tr><td colspan="8" class="muted">Pas de données.</td></tr><?php endif; ?>
  </tbody>
</table>

<?php if ($repeated): ?>
<h2>Requêtes répétées par une même IP (≥ 20 fois)</h2>
<table class="table compact"><thead><tr><th>IP</th><th>Requête</th><th class="num">Fois</th></tr></thead><tbody>
<?php foreach ($repeated as $r): ?><tr><td title="<?= e($r['ip_hash']) ?>"><?= e($r['ip_masked']) ?></td><td><?= e($r['query_norm']) ?></td><td class="num"><?= fmt_n($r['n']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<h2>Dernières alertes</h2>
<table class="table compact">
  <thead><tr><th>Date</th><th>IP</th><th>Motif</th><th>User-agent</th></tr></thead>
  <tbody>
  <?php foreach ($events as $ev): ?>
    <tr><td><?= fmt_dt($ev['created_at']) ?></td><td title="<?= e($ev['ip_hash']) ?>"><?= e($ev['ip_masked']) ?></td><td><?= e($reasons[$ev['reason']] ?? $ev['reason']) ?></td><td class="ellipsis"><?= e($ev['user_agent']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$events): ?><tr><td colspan="4" class="muted">Aucune alerte.</td></tr><?php endif; ?>
  </tbody>
</table>
