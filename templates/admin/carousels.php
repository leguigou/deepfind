<?php
/** @var array $list @var array $stats @var int $days @var array $dayChoices @var string $lang @var string $personal */
use DeepFind\Rules\Carousels;
use DeepFind\Rules\CustomResults;
use DeepFind\Rules\PageRules;

$sourceShort = ['auto' => 'Automatique', 'manual' => 'Manuel', 'similar' => 'Similaires', 'personal' => 'Personnalisé'];
$sortShort = ['biz' => 'score métier', 'sales' => 'ventes', 'clicks' => 'clics', 'new' => 'nouveautés', 'discount' => 'remises', 'rating' => 'notes',
    'price_asc' => 'prix ↑', 'price_desc' => 'prix ↓'];
$insertShort = ['append' => 'dans (fin)', 'prepend' => 'dans (début)', 'before' => 'avant', 'after' => 'après', 'replace' => 'à la place de'];
?>
<div class="page-head">
  <p class="muted br-intro">Langue <?= e(strtoupper($lang)) ?>. Un carrousel est un bandeau de produits qui défile, inséré par le widget dans un <strong>bloc de la page</strong>
    (identifiant ou classe CSS), sur les pages choisies. Produits choisis à la main, automatiquement (conditions et tri), similaires au produit de la page ou personnalisés
    selon le visiteur ; option « apprentissage » pour faire remonter les produits les plus cliqués. Faites glisser les lignes pour l'ordre (même emplacement : dans l'ordre de la liste).</p>
  <a class="btn" href="<?= e(url('/admin/carousels/edit')) ?>">+ Nouveau carrousel</a>
</div>

<form method="get" class="form inline cr-period">
  <label>Statistiques sur <select name="days" data-autosubmit><?php foreach ($dayChoices as $d => $l): ?><option value="<?= $d ?>" <?= $d === $days ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <span class="muted small">Vue = carrousel visible à l'écran (au moins à moitié) · Clic = clic sur un produit · CTR = clics / vues</span>
</form>

<form method="post" action="<?= e(url('/admin/carousels/reorder')) ?>" data-pr-order>
  <?= csrf_field() ?><input type="hidden" name="ids" value="">
</form>
<div class="table-scroll">
<table class="table cr-table ca-table">
  <thead><tr><th class="ly-grip" aria-label="Ordre"></th><th class="num">N°</th><th>Statut</th><th>Carrousel</th><th>Pages</th><th>Emplacement</th><th>Produits</th>
    <th class="num">Vues</th><th class="num">Clics</th><th class="num">CTR</th><th></th></tr></thead>
  <tbody data-pr-list>
  <?php foreach ($list as $i => $c): $s = $stats[$c['id']] ?? ['views' => 0, 'clicks' => 0, 'ctr' => null]; $per = CustomResults::period($c); ?>
    <tr draggable="true" data-row data-id="<?= (int) $c['id'] ?>" class="<?= $c['active'] && $per === 'current' ? '' : 'dim-soft' ?>">
      <td class="ly-grip"><span class="ly-handle" title="Faire glisser pour changer l'ordre"><?= admin_icon('grip-vertical') ?></span></td>
      <td class="num muted" data-rank><?= $i + 1 ?></td>
      <td>
        <form method="post" action="<?= e(url('/admin/carousels/toggle')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <label class="toggle toggle-sm"><input type="checkbox" role="switch" data-autosubmit <?= $c['active'] ? 'checked' : '' ?> aria-label="Activer « <?= e($c['name']) ?> »"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
        </form>
      </td>
      <td><a href="<?= e(url('/admin/carousels/edit', ['id' => $c['id']])) ?>"><strong><?= e($c['name']) ?></strong></a>
        <?php if ($c['title'] !== ''): ?><br><small class="muted">« <?= e($c['title']) ?> »</small><?php endif; ?>
        <br><small class="muted nowrap"><?= $c['starts_at'] ? e(date('d/m/Y', (int) strtotime($c['starts_at'])) . ' → ' . date('d/m/Y', (int) strtotime((string) $c['ends_at']))) : 'En continu' ?></small>
        <?= $per === 'scheduled' ? '<span class="badge badge-info">programmé</span>' : ($per === 'expired' ? '<span class="badge badge-warn">terminé</span>' : '') ?></td>
      <td>
        <?php if ($c['pages'] !== 'custom'): ?><span class="muted">Toutes les pages</span><?php else: ?>
          <div class="chips"><?php if ($c['home']): ?><span class="chip">Accueil</span><?php endif; ?><?php foreach ($c['urls'] as $u): ?><span class="chip"><span class="muted"><?= e(PageRules::URL_OPS[$u['match']] ?? $u['match']) ?></span> <?= e($u['text']) ?></span><?php endforeach; ?></div>
        <?php endif; ?>
        <?php if ($c['exclude_urls']): ?><small class="muted pp-except">sauf <?= e(implode(', ', array_column($c['exclude_urls'], 'text'))) ?></small><?php endif; ?>
      </td>
      <td><small><?= e($insertShort[$c['insert_mode']] ?? '') ?></small> <code class="ca-sel"><?= e($c['selector']) ?></code></td>
      <td class="nowrap"><small><?= e($sourceShort[$c['source']]) ?><?= $c['source'] !== 'manual' ? ' · ' . e($sortShort[$c['sort_by']] ?? '') : '' ?>
        <?= $c['learning'] ? '<br><span class="badge badge-info">apprentissage</span>' : '' ?><br><?= (int) $c['max_items'] ?> produits max.</small></td>
      <td class="num"><?= fmt_n($s['views']) ?></td>
      <td class="num"><?= fmt_n($s['clicks']) ?></td>
      <td class="num"><?= $s['ctr'] === null ? '—' : fmt_n($s['ctr'], 1) . ' %' ?></td>
      <td class="actions">
        <a class="btn btn-sm btn-light" href="<?= e(url('/admin/carousels/edit', ['id' => $c['id']])) ?>">Modifier</a>
        <form method="post" action="<?= e(url('/admin/carousels/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <button class="btn btn-sm btn-danger" data-confirm="Supprimer le carrousel « <?= e($c['name']) ?> » et ses statistiques ?">Supprimer</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$list): ?><tr><td colspan="11" class="muted">Aucun carrousel. Exemple : « Meilleures ventes » sur la page d'accueil, dans le bloc <code>#deepfind-carrousel</code>, produits en stock triés par ventes.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<form method="post" action="<?= e(url('/admin/carousels/settings')) ?>" class="card ca-settings" id="ca-personal">
  <?= csrf_field() ?>
  <strong>Carrousels personnalisés : historique des visiteurs</strong>
  <p class="muted small">« Vus récemment » et « Vous aimerez aussi » utilisent les produits consultés ou cliqués par le visiteur. Cet historique reste <strong>dans son navigateur</strong>
    (30 jours, 20 produits, sans identifiant) et n'est jamais enregistré par DeepFind. Il est envoyé avec la demande de la page uniquement pour choisir les produits.</p>
  <?php foreach (Carousels::PERSONAL_MODES as $v => $l): ?>
    <label class="check"><input type="radio" name="carousel_personal" value="<?= e($v) ?>" <?= $personal === $v ? 'checked' : '' ?>><span><?= e($l) ?></span></label>
  <?php endforeach; ?>
  <p class="muted small">Avec le consentement : le bandeau cookies du site appelle <code>DeepFind.consent(true)</code> quand le visiteur accepte (personnalisation),
    <code>DeepFind.consent(false)</code> s'il refuse ou retire son accord (son historique est alors effacé). Avant le chargement du widget, il peut aussi définir
    <code>window.deepfindConsent = true</code>. <strong>À faire valider par votre DPO</strong> (finalité « personnalisation » dans le bandeau cookies).</p>
  <div><button class="btn btn-sm">Enregistrer ce réglage</button></div>
</form>
<p class="muted small">Une modification est visible sur le site d'ici quelques minutes. Le widget doit être en version <code>?v=<?= (int) \DeepFind\Widget\WidgetConfig::WIDGET_VERSION ?></code> ou plus récente.
  Les « produits similaires » et l'historique des pages produit nécessitent un <strong>import complet</strong> après la mise à jour (adresse des produits indexée).</p>
<script src="<?= e(asset('/assets/page-rules.js')) ?>"></script>
