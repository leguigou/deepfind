<?php
use DeepFind\Settings;

$short = static fn (string $s): string => mb_strlen($s) > 60 ? mb_substr($s, 0, 60) . '…' : $s;
?>
<p class="muted">Les critères de classement (stock, marge, popularité…) se règlent dans <a href="<?= e(url('/admin/relevance')) ?>">Pertinence &amp; classement</a>.</p>
<form method="post" action="<?= e(url('/admin/settings/save')) ?>" class="form">
  <?= csrf_field() ?>
  <?php foreach ($groups as $group => $names): ?>
    <fieldset class="card">
      <legend><?= e($group) ?></legend>
      <div class="grid2">
      <?php foreach ($names as $n):
          $long = in_array($n, ['bad_user_agents', 'ip_allowlist'], true);
          $bool = in_array($n, Settings::BOOLEANS, true); ?>
        <?php if ($bool): $on = ($values[$n] ?? '') === '1'; ?>
        <div class="toggle-row">
          <input type="hidden" name="<?= e($n) ?>" value="0">
          <label class="toggle">
            <input type="checkbox" role="switch" name="<?= e($n) ?>" value="1" <?= $on ? 'checked' : '' ?>>
            <span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span>
            <span class="toggle-text"><strong><?= e(Settings::LABELS[$n] ?? $n) ?></strong>
              <small class="muted">Par défaut : <?= Settings::DEFAULTS[$n] === '1' ? 'activé' : 'désactivé' ?></small></span>
          </label>
        </div>
        <?php else: ?>
        <label class="<?= $long ? 'span2' : '' ?>"><?= e(Settings::LABELS[$n] ?? $n) ?>
          <?php if ($long): ?>
            <textarea name="<?= e($n) ?>" rows="2" class="code"><?= e($values[$n] ?? '') ?></textarea>
          <?php else: ?>
            <input name="<?= e($n) ?>" value="<?= e($values[$n] ?? '') ?>" inputmode="decimal">
          <?php endif; ?>
          <small class="muted">Défaut : <?= e(Settings::DEFAULTS[$n] === '' ? '(vide)' : $short(Settings::DEFAULTS[$n])) ?></small>
        </label>
        <?php endif; ?>
      <?php endforeach; ?>
      </div>
    </fieldset>
  <?php endforeach; ?>
  <button class="btn">Enregistrer</button>
</form>
