<?php
/** Recommandations › Envoi de notifications › message (lecture seule) : tel qu'envoyé, destinataires, résultats. @var array $c @var string $icon @var string $host */
use DeepFind\Push\Push;
use DeepFind\Rules\Segments;

$pct = static fn (int $a, int $b) => $b > 0 ? fmt_n(100 * $a / $b, 1) . ' %' : '—';
$statusLabel = ['draft' => ['Brouillon', ''], 'scheduled' => ['Programmé', 'badge-warn'], 'sending' => ['Envoi en cours', 'badge-info'], 'sent' => ['Envoyé', 'badge-ok']];
[$sl, $sc] = $statusLabel[$c['status']] ?? [$c['status'], ''];
$link = $c['url'] !== '' ? $c['url'] : '/';
?>
<div class="cp-nav pv-nav"><a href="<?= e(url('/admin/push')) ?>" class="cr-back">‹ Retour aux messages</a>
  <span class="pv-btns">
    <?php if (in_array($c['status'], ['draft', 'scheduled'], true)): ?><a class="btn btn-sm btn-light" href="<?= e(url('/admin/push/edit', ['id' => $c['id']])) ?>"><?= admin_icon('pencil') ?> Modifier</a><?php endif; ?>
    <form method="post" action="<?= e(url('/admin/push/duplicate')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
      <button class="btn btn-sm"><?= admin_icon('copy') ?> Dupliquer</button></form>
  </span></div>
<div class="pe-layout">
  <div class="card">
    <p><span class="badge <?= e($sc) ?>"><?= e($sl) ?></span>
      <?= $c['sent_at'] ? 'le ' . e(date('d/m/Y à H:i', strtotime($c['sent_at']))) : ($c['scheduled_at'] ? 'prévu le ' . e(date('d/m/Y à H:i', strtotime($c['scheduled_at']))) : '') ?></p>
    <dl class="kv-grid">
      <dt>Titre</dt><dd><strong><?= e($c['title']) ?></strong></dd>
      <dt>Texte</dt><dd><?= $c['body'] !== '' ? nl2br(e($c['body'])) : '<span class="muted">—</span>' ?></dd>
      <dt>Lien ouvert au clic</dt><dd><a href="<?= e(str_starts_with($link, '/') && $host !== '' ? 'https://' . $host . $link : $link) ?>" target="_blank" rel="noopener"><?= e($link) ?></a></dd>
      <dt>Image</dt><dd><?= $c['image'] !== '' ? '<a href="' . e($c['image']) . '" target="_blank" rel="noopener">' . e(basename((string) parse_url($c['image'], PHP_URL_PATH))) . '</a>' : '<span class="muted">aucune</span>' ?></dd>
      <dt>Appareils</dt><dd><?= e(explode(' (', Push::DEVICES[$c['devices']] ?? $c['devices'])[0]) ?></dd>
      <dt>Provenance</dt><dd><?= $c['channels'] ? e(implode(', ', array_map(static fn ($k) => explode(' (', Segments::CHANNELS[$k] ?? $k)[0], $c['channels']))) : 'tous les abonnés' ?></dd>
    </dl>
    <?php if ($c['status'] === 'sent' || $c['status'] === 'sending'): ?>
      <h3>Résultats</h3>
      <div class="cards pv-kpis">
        <div class="card kpi"><div class="kpi-label">Visés</div><div class="kpi-value"><?= fmt_n($c['targeted']) ?></div></div>
        <div class="card kpi"><div class="kpi-label">Envoyés</div><div class="kpi-value"><?= fmt_n($c['sent']) ?></div><?php if ($c['failed'] > 0): ?><div class="muted small"><?= fmt_n($c['failed']) ?> échec(s)</div><?php endif; ?></div>
        <div class="card kpi"><div class="kpi-label">Affichés</div><div class="kpi-value"><?= fmt_n($c['shown']) ?></div></div>
        <div class="card kpi"><div class="kpi-label">Clics</div><div class="kpi-value"><?= fmt_n($c['clicks']) ?></div><div class="muted small">taux de clic <?= $pct($c['clicks'], $c['shown'] ?: $c['sent']) ?></div></div>
      </div>
    <?php endif; ?>
  </div>
  <aside class="pe-preview" aria-label="Aperçu de la notification">
    <strong class="muted small">Tel qu'envoyé (l'apparence exacte dépend du téléphone)</strong>
    <div class="pe-notif">
      <div class="pe-notif-head"><?php if ($icon !== ''): ?><img src="<?= e($icon) ?>" alt="" class="pe-icon"><?php else: ?><span class="pe-icon pe-icon-empty"></span><?php endif; ?>
        <span class="muted small"><?= e($host !== '' ? $host : 'votre site') ?></span></div>
      <strong><?= e($c['title']) ?></strong>
      <?php if ($c['body'] !== ''): ?><p><?= e($c['body']) ?></p><?php endif; ?>
      <?php if ($c['image'] !== ''): ?><img alt="" class="pe-img" src="<?= e($c['image']) ?>"><?php endif; ?>
    </div>
    <?php if ($c['image'] !== ''): ?><small class="muted">L'image s'affiche sur Android et Windows ; iPhone et Mac montrent le titre et le texte seulement.</small><?php endif; ?>
  </aside>
</div>
