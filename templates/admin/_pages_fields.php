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
