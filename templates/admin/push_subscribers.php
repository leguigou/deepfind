<?php
/** Recommandations › Envoi de notifications › Abonnés. @var array $f @var int $page @var list<array> $rows @var int $total @var list<string> $browsers @var array $counts @var int $testers */
use DeepFind\Push\Push;
use DeepFind\Rules\Segments;

$perPage = 50;
$devices = ['phone' => 'Téléphone', 'tablet' => 'Tablette', 'desktop' => 'Ordinateur'];
$query = array_filter($f + ['page' => $page > 1 ? $page : ''], static fn ($v) => (string) $v !== '');
$back = '/admin/push/subscribers' . ($query ? '?' . http_build_query($query) : '');
$dt = static fn (?string $d) => $d ? e(date('d/m/Y H:i', strtotime($d))) : '<span class="muted">—</span>';
$pct = static fn (int $a, int $b) => $b > 0 ? fmt_n(100 * $a / $b, 0) . ' %' : '—';
$action = static function (int $id, string $do, string $label, string $cls, string $back, string $confirm = ''): string {
    return '<form method="post" action="' . e(url('/admin/push/subscribers/action')) . '">' . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="do" value="' . e($do) . '"><input type="hidden" name="back" value="' . e($back) . '">'
        . '<button class="btn btn-sm ' . e($cls) . '"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . e($label) . '</button></form>';
};
?>
<p><a href="<?= e(url('/admin/push')) ?>" class="cr-back">‹ Retour aux messages</a></p>
<p class="muted">Chaque abonné est un navigateur (téléphone, tablette, ordinateur) qui a accepté les notifications. Aucune donnée sur la personne :
  l'adresse d'envoi et la clé de chiffrement fournies par le navigateur sont masquées (début et fin seulement).
  <strong>En pause</strong> : ne reçoit plus les messages, réactivable. <strong>Supprimer</strong> : retiré de la liste ; s'il revient sur le site avec les notifications autorisées, il est réenregistré.</p>

<form method="get" action="<?= e(url('/admin/push/subscribers')) ?>" class="card form jr-filters">
  <label>Appareil <select name="device"><option value="">Tous</option>
    <?php foreach ($devices as $k => $l): ?><option value="<?= e($k) ?>"<?= ($f['device'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?> (<?= fmt_n($counts[$k]) ?>)</option><?php endforeach; ?></select></label>
  <label>Statut <select name="status"><option value="">Tous</option>
    <?php foreach (Push::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= ($f['status'] ?? '') === $k ? ' selected' : '' ?>><?= e(explode(' (', $l)[0]) ?></option><?php endforeach; ?></select></label>
  <label>Navigateur <select name="browser"><option value="">Tous</option>
    <?php foreach ($browsers as $b): ?><option value="<?= e($b) ?>"<?= ($f['browser'] ?? '') === $b ? ' selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?></select></label>
  <label>Provenance <select name="channel"><option value="">Toutes</option>
    <?php foreach (Segments::CHANNELS as $k => $l): ?><option value="<?= e($k) ?>"<?= ($f['channel'] ?? '') === $k ? ' selected' : '' ?>><?= e(explode(' (', $l)[0]) ?></option><?php endforeach; ?></select></label>
  <label>Groupe <select name="group"><option value="">Tous</option>
    <option value="test"<?= ($f['group'] ?? '') === 'test' ? ' selected' : '' ?>>Groupe de test (<?= fmt_n($testers) ?>)</option></select></label>
  <label>N° <input type="number" name="id" min="1" value="<?= e((string) ($f['id'] ?? '')) ?>" placeholder="ex. 42"></label>
  <button class="btn btn-sm">Filtrer</button>
  <?php if ($query !== [] && $query !== ['page' => $page]): ?><a class="btn btn-sm btn-light" href="<?= e(url('/admin/push/subscribers')) ?>">Tout afficher</a><?php endif; ?>
</form>

<div class="psb-head">
  <p><strong><?= fmt_n($total) ?></strong> abonné<?= $total > 1 ? 's' : '' ?></p>
  <div class="psb-group">
    <span><strong>Groupe de test</strong> : <?= fmt_n($testers) ?> appareil<?= $testers > 1 ? 's' : '' ?> <small class="muted">(<?= Push::MAX_TESTERS ?> au plus)</small></span>
    <?php if ($testers > 0): ?><?= $action(0, 'group_test', 'Envoyer un test au groupe', 'btn-light', $back) ?><?php endif; ?>
  </div>
</div>
<div class="table-wrap">
<table class="table psb-table">
  <thead><tr><th>N°</th><th>Appareil</th><th>Service d'envoi / adresse</th><th>Clé de chiffrement</th><th>Inscription</th><th>Statut</th>
    <th class="num">Reçus</th><th class="num">Affichés</th><th class="num">Clics</th><th>Dernier clic</th><th class="actions"></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $id = (int) $r['id']; ?>
    <tr>
      <td><strong><?= $id ?></strong></td>
      <td><?php if ((int) $r['tester'] === 1): ?><span class="badge badge-info">Test</span> <?php if ($r['label'] !== ''): ?><strong><?= e($r['label']) ?></strong><br><?php endif; ?><?php endif; ?>
        <?= e($devices[$r['device']] ?? $r['device']) ?><br><small class="muted"><?= e(trim($r['browser'] . ' · ' . $r['os'], ' ·')) ?: '—' ?></small>
        <br><small class="muted">provenance : <?= e(explode(' (', Segments::CHANNELS[$r['channel']] ?? $r['channel'])[0]) ?></small></td>
      <td><?= e($r['service']) ?><br><code class="psb-code"><?= e($r['endpoint_masked']) ?></code></td>
      <td><code class="psb-code"><?= e($r['key_masked']) ?></code></td>
      <td><?= $dt($r['created_at']) ?></td>
      <td><?php if ((int) $r['active'] === 0): ?><span class="badge badge-warn">En pause</span>
        <?php elseif ((int) $r['failures'] > 0): ?><span class="badge badge-ko" title="Envoi refusé par le service d'envoi ; supprimé après <?= Push::MAX_FAILURES ?> échecs de suite"><?= (int) $r['failures'] ?> échec(s)</span>
        <?php else: ?><span class="badge badge-ok">Actif</span><?php endif; ?></td>
      <td class="num"><?= fmt_n((int) $r['sent']) ?><?= $r['last_sent_at'] ? '<br><small class="muted">' . e(date('d/m', strtotime($r['last_sent_at']))) . '</small>' : '' ?></td>
      <td class="num"><?= fmt_n((int) $r['shown']) ?></td>
      <td class="num"><?= fmt_n((int) $r['clicks']) ?><br><small class="muted"><?= $pct((int) $r['clicks'], (int) ($r['shown'] ?: $r['sent'])) ?></small></td>
      <td><?= $dt($r['last_click_at']) ?></td>
      <td class="actions">
        <?= $action($id, 'test', 'Envoyer un test', 'btn-light', $back) ?>
        <?php if ((int) $r['tester'] === 1): ?><?= $action($id, 'tester_off', 'Retirer du groupe de test', 'btn-light', $back) ?>
        <?php else: ?><form method="post" action="<?= e(url('/admin/push/subscribers/action')) ?>" class="psb-tester"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="tester_on"><input type="hidden" name="back" value="<?= e($back) ?>">
          <input name="label" maxlength="60" placeholder="Nom (ex. iPhone de Paul)" aria-label="Nom de l'appareil de test">
          <button class="btn btn-sm btn-light">Ajouter au groupe de test</button></form><?php endif; ?>
        <?= (int) $r['active'] === 1 ? $action($id, 'pause', 'Mettre en pause', 'btn-light', $back) : $action($id, 'resume', 'Réactiver', '', $back) ?>
        <?= $action($id, 'delete', 'Supprimer', 'btn-danger', $back, 'Supprimer l\'abonné n° ' . $id . ' ? Il ne recevra plus de messages (sauf s\'il revient sur le site avec les notifications autorisées).') ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="11" class="muted">Aucun abonné pour ces critères.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php $pages = (int) ceil($total / $perPage); if ($pages > 1): ?>
  <nav class="pager" aria-label="Pages">
    <?php if ($page > 1): ?><a class="btn btn-sm btn-light" href="<?= e(url('/admin/push/subscribers', ['page' => $page - 1] + $query)) ?>">‹ Précédents</a><?php endif; ?>
    <span class="muted small">Page <?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn btn-sm btn-light" href="<?= e(url('/admin/push/subscribers', ['page' => $page + 1] + $query)) ?>">Suivants ›</a><?php endif; ?>
  </nav>
<?php endif; ?>
<p class="muted small">« Reçus » : messages acceptés par le service d'envoi pour cet appareil. « Affichés » et « Clics » : signalés par l'appareil lui-même.
  Pour reconnaître votre propre appareil : abonnez-vous depuis le site, puis utilisez « Envoyer un test » sur le dernier abonné (n° le plus élevé).
  <strong>Groupe de test</strong> : vos appareils (et ceux des collègues qui relisent) ; il reçoit les essais envoyés depuis la fiche d'un message, même en pause.
  Astuce : mettez vos appareils de test en pause pour qu'ils ne reçoivent que les essais, pas les vrais messages.</p>
