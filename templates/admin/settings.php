<?php
/** @var array $groups @var array $values */
use DeepFind\Settings;

$short = static fn (string $s): string => mb_strlen($s) > 60 ? mb_substr($s, 0, 60) . '…' : $s;
// Champs longs (texte libre sur toute la largeur) et unités affichées à côté des champs numériques
$long = ['bad_user_agents', 'ip_allowlist'];
$units = [
    'rate_per_minute' => 'req./min', 'rate_per_hour' => 'req./h', 'same_query_per_minute' => 'req./min', 'burst_multiplier' => '×',
    'violations_before_block' => 'min', 'block_minutes' => 'min', 'posts_limit' => 'articles', 'max_limit' => 'produits',
    'cache_ttl' => 's', 'facet_values_limit' => 'valeurs', 'log_retention_days' => 'jours', 'max_drop_percent' => '%',
];
?>
<p class="muted">Les critères de classement (stock, marge, popularité…) se règlent dans <a href="<?= e(url('/admin/relevance')) ?>">Pertinence &amp; classement</a>.</p>
<form method="post" action="<?= e(url('/admin/settings/save')) ?>" class="form st">
  <?= csrf_field() ?>
  <?php foreach ($groups as $group => $names): ?>
    <section class="card st-card<?= count($names) > 3 ? ' st-wide' : '' ?>">
      <h2 class="st-title"><?= e($group) ?></h2>
      <div class="st-rows">
      <?php foreach ($names as $n):
          $id = 'st-' . $n;
          $label = Settings::LABELS[$n] ?? $n;
          $default = Settings::DEFAULTS[$n] ?? '';
          $bool = in_array($n, Settings::BOOLEANS, true); ?>
        <?php if ($bool): $on = ($values[$n] ?? $default) === '1'; ?>
          <div class="st-row">
            <div class="st-text"><label for="<?= e($id) ?>"><?= e($label) ?></label>
              <small class="muted">Par défaut : <?= $default === '1' ? 'activé' : 'désactivé' ?></small></div>
            <div class="st-ctrl">
              <input type="hidden" name="<?= e($n) ?>" value="0">
              <label class="toggle"><input type="checkbox" role="switch" id="<?= e($id) ?>" name="<?= e($n) ?>" value="1" <?= $on ? 'checked' : '' ?>><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
            </div>
          </div>
        <?php elseif (in_array($n, $long, true)): ?>
          <div class="st-row st-row-long">
            <div class="st-text"><label for="<?= e($id) ?>"><?= e($label) ?></label>
              <small class="muted">Défaut : <?= e($default === '' ? '(vide)' : $short($default)) ?></small></div>
            <textarea id="<?= e($id) ?>" name="<?= e($n) ?>" rows="2" class="code"><?= e($values[$n] ?? '') ?></textarea>
          </div>
        <?php else: ?>
          <div class="st-row">
            <div class="st-text"><label for="<?= e($id) ?>"><?= e($label) ?></label>
              <small class="muted">Défaut : <?= e($default === '' ? '(vide)' : $default) ?></small></div>
            <div class="st-ctrl">
              <input id="<?= e($id) ?>" name="<?= e($n) ?>" value="<?= e($values[$n] ?? '') ?>" inputmode="decimal" class="st-num" placeholder="<?= e($default) ?>">
              <?php if (isset($units[$n])): ?><span class="st-unit"><?= e($units[$n]) ?></span><?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
  <div class="st-foot"><button class="btn">Enregistrer</button></div>
</form>
