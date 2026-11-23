<?php
/** Contenu d'une bannière ou d'une popin : images ordinateur / mobile (texte alternatif, lien) ou contenu HTML,
 *  rédigé dans l'éditeur visuel ou en code. Géré par editor-tools.js (DFImageSlots, DFWysiwyg) et la page appelante. Variables : $mediaSizes [clé => [libellé, taille conseillée]], $maxKb. */
?>
      <div class="bn-panel">
        <label class="bn-mode">Mode
          <select data-f="mode">
            <option value="visual">Image (ordinateur et mobile)</option>
            <option value="code">Contenu HTML (éditeur visuel ou code)</option>
          </select>
        </label>

        <div data-mode-panel="visual">
          <div class="grid2">
            <?php foreach ($mediaSizes as $k => [$label, $size]): ?>
              <div class="bn-slot" data-slot="<?= $k ?>">
                <strong><?= e($label) ?></strong>
                <div class="bn-drop" data-drop tabindex="0" role="button" aria-label="Téléverser l'image <?= e(mb_strtolower($label)) ?>">
                  <input type="file" class="vh" accept="image/jpeg,image/png,image/gif,image/webp" data-file tabindex="-1" aria-hidden="true">
                  <div class="bn-empty" data-empty>
                    <span class="bn-ico" aria-hidden="true">⇪</span>
                    <strong>Glissez une image ici</strong>
                    <span class="muted">ou <u>parcourez vos fichiers</u></span>
                  </div>
                  <div class="bn-busy" data-busy hidden><span class="spinner" aria-hidden="true"></span>Téléversement…</div>
                  <figure class="bn-filled" data-filled hidden>
                    <img data-preview alt="">
                    <figcaption>
                      <span class="muted" data-meta></span>
                      <span class="bn-actions">
                        <button type="button" class="btn btn-sm btn-light" data-replace>Remplacer</button>
                        <button type="button" class="btn btn-sm btn-light btn-danger-soft" data-remove>Retirer</button>
                      </span>
                    </figcaption>
                  </figure>
                </div>
                <p class="file-drop-error" data-error role="alert" hidden></p>
                <small class="muted">JPG, PNG, GIF ou WebP · <?= $maxKb ?> Ko maximum · conseillé : <?= e($size) ?>.<?= $k === 'mobile' ? ' Facultative : à défaut, l\'image ordinateur est utilisée.' : '' ?></small>
                <input data-f="image_<?= $k ?>" type="url" placeholder="… ou saisissez l'adresse de l'image (https://…)" aria-label="Adresse de l'image <?= e(mb_strtolower($label)) ?>">
              </div>
            <?php endforeach; ?>
          </div>
          <label>Texte alternatif <small class="muted">(lu par les lecteurs d'écran, affiché si l'image ne charge pas)</small>
            <input data-f="alt" maxlength="190" placeholder="ex. Soldes : -30 % sur les palmes de chasse"></label>
          <label>Lien cible
            <input data-f="link_url" type="url" placeholder="Adresse de la page vers laquelle envoyer le visiteur (https://…)"></label>
          <div class="cr-row">
            <strong>Ouvrir le lien dans une nouvelle fenêtre</strong>
            <label class="toggle"><input type="checkbox" role="switch" data-f="new_window" aria-label="Nouvelle fenêtre"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span></label>
          </div>
        </div>

        <div data-mode-panel="code" hidden>
          <div class="wy-tabs" role="tablist" aria-label="Façon de rédiger le contenu">
            <button type="button" role="tab" class="wy-tab" data-wy-tab="visual" aria-selected="true">Éditeur visuel</button>
            <button type="button" role="tab" class="wy-tab" data-wy-tab="code" aria-selected="false">Code HTML</button>
          </div>
          <div data-wy-panel="visual" class="wy" data-wy></div>
          <div data-wy-panel="code" hidden>
            <div class="bn-code-head"><span>HTML</span><button type="button" class="icon-btn" data-copy aria-label="Copier le code">⧉</button></div>
            <textarea data-f="html" class="bn-code-area" rows="10" spellcheck="false" placeholder="&lt;a href=&quot;https://www.example.com/…&quot;&gt;&lt;img src=&quot;https://…&quot; alt=&quot;…&quot;&gt;&lt;/a&gt;"></textarea>
          </div>
          <small class="muted">L'éditeur visuel et le code HTML modifient le même contenu. Balises autorisées : liens, images, titres, paragraphes, listes, textes et blocs, avec leur mise en forme (couleur, alignement…). Les scripts, iframes, formulaires, événements (onclick…) et liens javascript: sont retirés à l'enregistrement.</small>
        </div>
      </div>
