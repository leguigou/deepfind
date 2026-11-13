<?php
/** Blocs « Durée » (en continu / période avec calendrier) et « Termes de recherche » ; gérés par assets/editor-tools.js.
 *  Variables facultatives : $withPeriod (true), $withTerms (true), $termsHelp (texte d'aide), $termsLabel. */
$withPeriod ??= true;
$withTerms ??= true;
?>
<?php if ($withPeriod): ?>
    <div class="cr-section">
      <strong>Durée</strong>
      <label class="check"><input type="radio" name="duration" value="always" data-duration checked><span>Appliquer en continu à partir d'aujourd'hui</span></label>
      <label class="check"><input type="radio" name="duration" value="period" data-duration><span>Définir une période</span></label>
      <div class="cr-range-wrap">
        <button type="button" class="cr-range" data-range aria-haspopup="dialog">
          <span data-range-start class="muted">Date de début</span><span class="cr-range-sep" aria-hidden="true">▸</span><span data-range-end class="muted">Date de fin</span>
          <span class="cr-cal-ico" aria-hidden="true">📅</span>
        </button>
        <div class="cr-cal" data-cal hidden></div>
      </div>
    </div>
<?php endif; ?>
<?php if ($withTerms): ?>
    <div class="cr-section">
      <strong><?= e($termsLabel ?? 'Termes de recherche') ?></strong>
      <div class="br-term-input">
        <select data-f="term_match" aria-label="Type de correspondance">
          <option value="exact">= Correspondance exacte</option>
          <option value="broad">≈ Correspondance large</option>
        </select>
        <input data-f="term" placeholder="Saisir un terme de recherche" aria-label="Terme de recherche">
        <button type="button" class="btn" data-add-term>Ajouter un terme</button>
      </div>
      <small class="muted"><?= e($termsHelp ?? 'Exacte : la recherche est exactement ce terme. Large : la recherche contient tous les mots du terme.') ?></small>
      <div class="chips" data-terms></div>
    </div>
<?php endif; ?>
