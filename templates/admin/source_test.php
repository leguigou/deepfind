<p><a href="<?= e(url('/admin/sources/edit', ['id' => $source['id']])) ?>">← Retour à la source <?= e($source['code']) ?></a>
  · <a href="<?= e(url('/admin/sources/test', ['id' => $source['id'], 'refresh' => 1, 'type' => $type])) ?>">Retélécharger le flux</a>
  <?php if ($source['delta_url']): ?> · <a href="<?= e(url('/admin/sources/test', ['id' => $source['id'], 'type' => $type === 'delta' ? 'full' : 'delta'])) ?>">Tester le flux <?= $type === 'delta' ? 'complet' : 'delta' ?></a><?php endif; ?></p>
<?php if ($file): ?><p class="muted">Fichier analysé : <?= e($file) ?> · flux <?= e($type) ?> · rien n'est indexé pendant ce test.</p><?php endif; ?>
<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
<?php if ($flat): ?><div class="flash flash-info">Mode « flat » : les enfants sont regroupés sous leur parent pendant l'import. Ce test montre chaque ligne séparément.</div><?php endif; ?>

<?php foreach ($samples as $i => $s): ?>
  <h2>Élément <?= $i + 1 ?></h2>
  <div class="grid3">
    <div><h3>XML source</h3><pre class="code"><?= e($s['xml']) ?></pre></div>
    <div><h3>Après mapping</h3><pre class="code"><?= e(json_encode($s['mapped'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></div>
    <div><h3>Document indexé</h3>
      <?php if (is_string($s['doc'])): ?><div class="flash flash-error">Rejeté : <?= e($s['doc']) ?></div>
      <?php elseif ($s['doc'] === null): ?><p class="muted">Calculé à l'import.</p>
      <?php else: ?><pre class="code"><?= e(json_encode($s['doc'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
