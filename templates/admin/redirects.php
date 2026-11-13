<?php
/** @var array $list @var string $lang */
use DeepFind\Rules\CustomResults;
?>
<div class="page-head">
  <p class="muted br-intro">Langue <?= e(strtoupper($lang)) ?>. Pour certains termes de recherche, le widget propose un lien vers une page en haut des résultats.
    En <strong>redirection automatique</strong>, la recherche validée (Entrée ou loupe) ouvre directement la page. Si plusieurs redirections correspondent, la plus récente l'emporte.</p>
  <a class="btn" href="<?= e(url('/admin/redirects/edit')) ?>">+ Nouvelle redirection</a>
</div>

<div class="table-scroll">
<table class="table cr-table">
  <thead><tr><th>Statut</th><th>Nom</th><th>Termes de recherche</th><th>Destination</th><th>Type</th><th>Durée</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($list as $r): $per = CustomResults::period($r); ?>
    <tr class="<?= $r['active'] && $per === 'current' ? '' : 'dim-soft' ?>">
      <td>
        <form method="post" action="<?= e(url('/admin/redirects/toggle')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <label class="toggle toggle-sm"><input type="checkbox" role="switch" data-autosubmit <?= $r['active'] ? 'checked' : '' ?> aria-label="Activer « <?= e($r['name']) ?> »"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
        </form>
      </td>
      <td><a href="<?= e(url('/admin/redirects/edit', ['id' => $r['id']])) ?>"><strong><?= e($r['name']) ?></strong></a></td>
      <td><div class="chips"><?php foreach ($r['terms'] as $t): ?><span class="chip" title="<?= $t['match'] === 'broad' ? 'Correspondance large' : 'Correspondance exacte' ?>"><span class="chip-ico"><?= $t['match'] === 'broad' ? '≈' : '=' ?></span><?= e($t['term']) ?></span><?php endforeach; ?></div></td>
      <td class="ellipsis"><a href="<?= e($r['url']) ?>" target="_blank" rel="noopener"><?= e($r['url']) ?></a></td>
      <td><?= $r['auto'] ? '<span class="badge badge-ok">automatique</span>' : '<span class="badge badge-info">lien proposé</span>' ?></td>
      <td class="nowrap"><?= $r['starts_at'] ? e(date('d/m/Y', (int) strtotime($r['starts_at'])) . ' → ' . date('d/m/Y', (int) strtotime((string) $r['ends_at']))) : 'En continu' ?>
        <?= $per === 'scheduled' ? '<br><span class="badge badge-info">programmée</span>' : ($per === 'expired' ? '<br><span class="badge badge-warn">terminée</span>' : '') ?></td>
      <td class="actions">
        <a class="btn btn-sm btn-light" href="<?= e(url('/admin/redirects/edit', ['id' => $r['id']])) ?>">Modifier</a>
        <form method="post" action="<?= e(url('/admin/redirects/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button class="btn btn-sm btn-danger" data-confirm="Supprimer la redirection « <?= e($r['name']) ?> » ?">Supprimer</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$list): ?><tr><td colspan="7" class="muted">Aucune redirection. Exemple : « livraison » → page des conditions de livraison.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<details class="card cr-import">
  <summary><strong>Importer un fichier CSV</strong></summary>
  <form method="post" action="<?= e(url('/admin/redirects/import')) ?>" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?>
    <p class="muted">Colonnes : <code>requete;url</code> (voir <code>samples/redirections.csv</code>). Chaque ligne devient une redirection automatique en correspondance exacte.</p>
    <label>Fichier <input type="file" name="csv" accept=".csv,text/csv" required></label>
    <label class="check"><input type="checkbox" name="replace" value="1"> Remplacer toutes les redirections de cette langue</label>
    <button class="btn btn-light">Importer</button>
  </form>
</details>
