<?php use DeepFind\Settings; ?>
<form method="post" action="<?= e(url('/admin/settings/save')) ?>" class="form">
  <?= csrf_field() ?>
  <?php foreach ($groups as $group => $names): ?>
    <fieldset class="card">
      <legend><?= e($group) ?></legend>
      <div class="grid2">
      <?php foreach ($names as $n): $long = in_array($n, ['bad_user_agents', 'ip_allowlist'], true); ?>
        <label class="<?= $long ? 'span2' : '' ?>"><?= e(Settings::LABELS[$n] ?? $n) ?>
          <?php if ($long): ?>
            <textarea name="<?= e($n) ?>" rows="2" class="code"><?= e($values[$n] ?? '') ?></textarea>
          <?php else: ?>
            <input name="<?= e($n) ?>" value="<?= e($values[$n] ?? '') ?>" inputmode="decimal">
          <?php endif; ?>
          <small class="muted">Défaut : <?= e(Settings::DEFAULTS[$n] === '' ? '(vide)' : Text_short(Settings::DEFAULTS[$n])) ?></small>
        </label>
      <?php endforeach; ?>
      </div>
    </fieldset>
  <?php endforeach; ?>
  <button class="btn">Enregistrer</button>
</form>
<?php
function Text_short(string $s): string
{
    return mb_strlen($s) > 60 ? mb_substr($s, 0, 60) . '…' : $s;
}
