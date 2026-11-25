<?php
/** Pages concernées (toutes / accueil / adresses) et pages exclues : popins et carrousels ; géré par editor-tools.js (DFPages).
 *  Variables facultatives : $pagesTitle, $pagesNoun (« de popin »), $pagesWho (« La popin s'affiche »). */
?>
      <strong><?= e($pagesTitle ?? '1. Pages concernées') ?></strong>
      <label class="check"><input type="radio" name="pp_pages" value="all"><span>Toutes les pages du site</span></label>
      <label class="check"><input type="radio" name="pp_pages" value="custom"><span>Seulement certaines pages</span></label>
      <div class="pp-pages" data-pages-custom>
        <label class="check"><input type="checkbox" data-f="home"><span>Page d'accueil <small class="muted">(« / » ou la racine d'une langue, ex. « /fr »)</small></span></label>
        <p class="muted small">Adresse de la page, sans le nom de domaine (ex. <code>/fr/nautisme/kayak.html</code>). Majuscules ignorées.
          <?= e($pagesWho ?? 'La popin s\'affiche') ?> si <strong>au moins une</strong> de ces conditions est remplie.</p>
        <div class="pr-urls" data-urls="urls"></div>
        <button type="button" class="btn btn-light btn-sm" data-add-url="urls">+ Ajouter une adresse</button>
      </div>
      <p class="muted small pp-excl-help"><strong>Pages exclues</strong> (facultatif) : jamais <?= e($pagesNoun ?? 'de popin') ?> sur ces pages, même si elles correspondent ci-dessus. Ex. contient <code>/checkout</code>, contient <code>/customer/</code>.</p>
      <div class="pr-urls" data-urls="exclude_urls"></div>
      <button type="button" class="btn btn-light btn-sm" data-add-url="exclude_urls">+ Ajouter une page exclue</button>
      <div class="pp-seg" data-segments data-utm-fields="<?= e(\DeepFind\Text::json(\DeepFind\Rules\Segments::UTM_FIELDS)) ?>" data-utm-ops="<?= e(\DeepFind\Text::json(\DeepFind\Rules\Segments::UTM_OPS)) ?>">
        <p class="muted small pp-excl-help"><strong>Provenance du visiteur</strong> (facultatif) : <?= e(mb_strtolower(mb_substr($pagesWho ?? 'La popin s\'affiche', 0, 1)) . mb_substr($pagesWho ?? 'La popin s\'affiche', 1)) ?>
          seulement pour les visiteurs arrivés par ces canaux ; la provenance est retenue pour toute leur visite de la journée. Aucune case cochée : tous les visiteurs.</p>
        <div class="pp-seg-channels">
          <?php foreach (\DeepFind\Rules\Segments::CHANNELS as $k => $label): ?>
            <label class="check"><input type="checkbox" data-seg-channel value="<?= e($k) ?>"><span><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div>
        <p class="muted small">Campagne précise (paramètres <code>utm_*</code> de l'adresse d'arrivée, majuscules ignorées) : toutes les conditions doivent être remplies.</p>
        <div class="pp-seg-utm" data-seg-utm></div>
        <button type="button" class="btn btn-light btn-sm" data-seg-add>+ Ajouter une condition UTM</button>
      </div>
