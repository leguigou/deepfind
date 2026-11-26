<?php
/** Recommandations › Envoi de notifications : messages et résultats. @var list<array> $list @var array $counts @var array $stats @var bool $enabled */
use DeepFind\Push\Push;

$pct = static fn (int $a, int $b) => $b > 0 ? fmt_n(100 * $a / $b, 1) . ' %' : '—';
$statusLabel = ['draft' => ['Brouillon', ''], 'scheduled' => ['Programmé', 'badge-warn'], 'sending' => ['Envoi en cours', 'badge-info'], 'sent' => ['Envoyé', 'badge-ok']];
?>
<?php if (!$enabled): ?>
  <div class="flash flash-info">L'invitation à s'abonner est désactivée : activez-la dans <a href="<?= e(url('/admin/push/settings')) ?>">Paramètres › Notifications</a>
    (après avoir déposé le fichier sw.js sur le site). Les abonnés existants peuvent toujours recevoir les messages.</div>
<?php endif; ?>
<div class="cards">
  <div class="card kpi"><div class="kpi-label">Abonnés</div><div class="kpi-value"><?= fmt_n($counts['total']) ?></div>
    <div class="muted small">téléphones <?= fmt_n($counts['phone']) ?> · tablettes <?= fmt_n($counts['tablet']) ?> · ordinateurs <?= fmt_n($counts['desktop']) ?></div></div>
  <div class="card kpi"><div class="kpi-label">Nouveaux abonnés (30 j)</div><div class="kpi-value"><?= fmt_n($stats['sub']) ?></div>
    <div class="muted small"><?= fmt_n($stats['uns']) ?> désabonnement(s)</div></div>
</div>
<p class="actions"><a class="btn" href="<?= e(url('/admin/push/edit')) ?>">+ Nouveau message</a>
  <a class="btn btn-light" href="<?= e(url('/admin/push/subscribers')) ?>"><?= admin_icon('users') ?> Voir les abonnés (<?= fmt_n($counts['total']) ?>)</a></p>
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Message</th><th>Cible</th><th>Envoi</th><th class="num">Envoyés</th><th class="num">Affichés</th><th class="num">Clics</th><th class="num">Taux de clic</th><th class="actions"></th></tr></thead>
  <tbody>
  <?php foreach ($list as $c): [$sl, $sc] = $statusLabel[$c['status']] ?? [$c['status'], '']; ?>
    <tr>
      <td><?php if (in_array($c['status'], ['draft', 'scheduled'], true)): ?><a href="<?= e(url('/admin/push/edit', ['id' => $c['id']])) ?>"><?= e($c['title']) ?></a><?php else: ?><strong><?= e($c['title']) ?></strong><?php endif; ?>
        <span class="badge <?= e($sc) ?>"><?= e($sl) ?></span><br><small class="muted"><?= e(mb_strimwidth($c['body'], 0, 90, '…', 'UTF-8')) ?></small></td>
      <td><?= e(explode(' (', Push::DEVICES[$c['devices']] ?? $c['devices'])[0]) ?><?php if ($c['channels']): ?><br><small class="muted"><?= e(implode(', ', array_map(static fn ($k) => explode(' (', \DeepFind\Rules\Segments::CHANNELS[$k] ?? $k)[0], $c['channels']))) ?></small><?php endif; ?></td>
      <td><?= $c['sent_at'] ? e(date('d/m/Y H:i', strtotime($c['sent_at']))) : ($c['scheduled_at'] ? 'le ' . e(date('d/m/Y H:i', strtotime($c['scheduled_at']))) : '—') ?></td>
      <td class="num"><?= fmt_n($c['sent']) ?><?= $c['failed'] > 0 ? ' <small class="muted">(' . fmt_n($c['failed']) . ' échec' . ($c['failed'] > 1 ? 's' : '') . ')</small>' : '' ?></td>
      <td class="num"><?= fmt_n($c['shown']) ?></td>
      <td class="num"><?= fmt_n($c['clicks']) ?></td>
      <td class="num"><?= $pct($c['clicks'], $c['shown'] ?: $c['sent']) ?></td>
      <td class="actions"><?php if ($c['status'] !== 'sending'): ?>
        <form method="post" action="<?= e(url('/admin/push/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <button class="btn btn-sm btn-danger" data-confirm="Supprimer le message « <?= e($c['title']) ?> » ?">Supprimer</button></form><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($list === []): ?><tr><td colspan="8" class="muted">Aucun message pour l'instant.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted small">« Affichés » et « Clics » : signalés par le navigateur de l'abonné (le service worker du site). Un abonnement expiré ou retiré est supprimé automatiquement au premier envoi qui échoue.</p>
