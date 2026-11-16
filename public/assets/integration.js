/* Intégration : paramètres d'un layer (renommer, couleurs), listes ordonnées des filtres et des tris
 * (glisser-déposer, ajout / retrait, recherche ; tri = champ puis ordre selon son type puis nom). Aucune donnée insérée en HTML brut. */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    // ------------------------------------------------------------ Paramètres d'un layer : mise en page (champs propres au type)
    // (fenêtre « Mise en page » : boutons radio data-layer-type ; résumé mis à jour : image, nom, description)
    var typeRadios = document.querySelectorAll('input[data-layer-type]');
    if (typeRadios.length) {
      var syncType = function () {
        var cur = document.querySelector('input[data-layer-type]:checked');
        if (!cur) { return; }
        document.querySelectorAll('[data-layer-only]').forEach(function (n) {
          var on = n.getAttribute('data-layer-only').split(' ').indexOf(cur.value) !== -1;
          n.hidden = !on;
          n.querySelectorAll('input[name=container]').forEach(function (i) { i.required = on; });
        });
        var pic = document.querySelector('[data-layer-type-pic]');
        if (pic) { pic.className = 'ly-pic ly-pic-' + cur.value; }
        var nm = document.querySelector('[data-layer-type-name]');
        if (nm) { nm.textContent = cur.getAttribute('data-name'); }
        var hp = document.querySelector('[data-layer-type-help]');
        if (hp) { hp.textContent = cur.getAttribute('data-help'); }
      };
      typeRadios.forEach(function (r) { r.addEventListener('change', syncType); });
      syncType();
    }

    // ------------------------------------------------------------ Largeur maximale : bornes selon l'unité (% ou pixels)
    var widthUnit = document.querySelector('[data-width-unit]');
    var widthInput = document.querySelector('[data-width-input]');
    if (widthUnit && widthInput) {
      widthUnit.addEventListener('change', function () {
        var px = widthUnit.value === 'px';
        widthInput.min = px ? '320' : '10';
        widthInput.max = px ? '3000' : '100';
        // valeur incohérente avec la nouvelle unité (ex. 900 en %) : effacée plutôt que mal interprétée
        var v = parseInt(widthInput.value, 10);
        if (v && (v < Number(widthInput.min) || v > Number(widthInput.max))) { widthInput.value = ''; }
        widthInput.focus();
      });
    }

    // ------------------------------------------------------------ Nombre de produits : aperçu de la grille (ordinateur / mobile)
    var products = document.querySelector('[data-products]');
    if (products) {
      var paint = function (dev) {
        var sel = products.querySelector('[data-cols="' + dev + '"]');
        var box = products.querySelector('[data-preview="' + dev + '"]');
        var cols = parseInt(sel.value, 10) || 4; // automatique : 4 sur l'aperçu
        box.textContent = '';
        box.style.gridTemplateColumns = 'repeat(' + cols + ', 1fr)';
        for (var i = 0; i < cols * 2; i++) { box.appendChild(document.createElement('i')); }
      };
      ['desktop', 'mobile'].forEach(function (dev) {
        products.querySelector('[data-cols="' + dev + '"]').addEventListener('change', function () { paint(dev); });
        paint(dev);
      });
    }

    // ------------------------------------------------------------ Favori « Tester DeepFind » (aperçu sur le vrai site)
    // Le favori enregistre le jeton d'aperçu dans la session de l'onglet du site, puis : widget déjà installé → la page
    // se recharge en aperçu ; sinon → le widget est ajouté à la page avec la clé et le jeton choisis.
    var test = document.querySelector('[data-test-bookmark]');
    if (test && test.querySelector('[data-test-link]')) {
      var link = test.querySelector('[data-test-link]');
      var layerSel = test.querySelector('[data-test-layer]');
      var keySel = test.querySelector('[data-test-key]');
      var build = function () {
        var q = JSON.stringify;
        var code = '(function(){var B=' + q(test.getAttribute('data-base')) + ',K=' + q(keySel.value) + ',T=' + q(layerSel.value)
          + ',L=' + q(test.getAttribute('data-lang')) + ',V=' + q(test.getAttribute('data-version')) + ';'
          + 'try{if(T){sessionStorage.setItem("df-preview",T)}else{sessionStorage.removeItem("df-preview")}}catch(e){}'
          + 'if(window.DeepFind){location.reload();return}'
          + 'var s=document.createElement("script");s.src=B+"/widget/deepfind.js?v="+V;s.setAttribute("data-key",K);s.setAttribute("data-lang",L);'
          + 'if(T){s.setAttribute("data-preview",T)}document.body.appendChild(s)})();';
        link.href = 'javascript:' + encodeURI(code);
        var opt = layerSel.options[layerSel.selectedIndex];
        link.title = 'Glissez ce bouton dans la barre de favoris (' + opt.textContent.trim() + ')';
      };
      layerSel.addEventListener('change', build);
      keySel.addEventListener('change', build);
      build();
      // un clic dans l'extranet ne ferait rien (sécurité de la page) : on rappelle qu'il faut glisser le bouton
      link.addEventListener('click', function (ev) {
        ev.preventDefault();
        window.DeepFindUI.alert('Glissez ce bouton dans la barre de favoris de Chrome (étape 2), puis cliquez sur le favori depuis une page du site.');
      });
      var copyBtn = test.querySelector('[data-test-copy]');
      copyBtn.addEventListener('click', function () {
        var done = function () { copyBtn.classList.add('is-copied'); setTimeout(function () { copyBtn.classList.remove('is-copied'); }, 1600); };
        if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(link.href).then(done, function () {}); }
      });
    }

    // ------------------------------------------------------------ Paramètres d'un layer : renommer, couleurs
    var nameInput = document.querySelector('[data-lp-name]');
    var renameBtn = document.querySelector('[data-lp-rename]');
    if (nameInput && renameBtn) {
      renameBtn.addEventListener('click', function () {
        nameInput.readOnly = false;
        nameInput.focus();
        nameInput.select();
      });
    }
    document.querySelectorAll('input[type=color][data-color-for]').forEach(function (picker) {
      var text = document.querySelector('input[name="' + picker.getAttribute('data-color-for') + '"]');
      if (!text) { return; }
      picker.addEventListener('input', function () { text.value = picker.value.toUpperCase(); });
      text.addEventListener('input', function () { if (/^#[0-9a-f]{6}$/i.test(text.value.trim())) { picker.value = text.value.trim().toLowerCase(); } });
    });

    // ------------------------------------------------------------ Éditeurs CSS / modèle : Ordinateur / Mobile, restaurer, copier
    document.querySelectorAll('[data-editor]').forEach(function (dlg) {
      var tabs = dlg.querySelectorAll('[data-editor-tab]');
      var areas = dlg.querySelectorAll('[data-editor-device]');
      var label = dlg.querySelector('[data-editor-device-label]');
      var current = function () { return dlg.querySelector('[data-editor-device]:not([hidden])'); };
      tabs.forEach(function (t) {
        t.addEventListener('click', function () {
          var dev = t.getAttribute('data-editor-tab');
          tabs.forEach(function (o) { var on = o === t; o.classList.toggle('is-on', on); o.setAttribute('aria-selected', String(on)); });
          areas.forEach(function (a) { a.hidden = a.getAttribute('data-editor-device') !== dev; });
          if (label) { label.textContent = t.textContent; }
          current().focus();
        });
      });
      var restore = dlg.querySelector('[data-editor-restore]');
      var def = dlg.querySelector('template[data-editor-default]');
      if (restore && def) {
        restore.addEventListener('click', function () {
          var area = current();
          if (area.value.trim() !== '' && !window.confirm('Remettre le modèle d\'origine dans cet onglet ? Vos modifications non enregistrées seront perdues.')) { return; }
          area.value = def.content.textContent; // modèle stocké comme texte (échappé côté serveur)
          area.focus();
        });
      }
      var copy = dlg.querySelector('[data-editor-copy]');
      if (copy) {
        copy.addEventListener('click', function () {
          var text = current().value;
          var done = function () { copy.classList.add('is-copied'); setTimeout(function () { copy.classList.remove('is-copied'); }, 1600); };
          if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, function () {}); }
          else { var a = current(); a.select(); try { if (document.execCommand('copy')) { done(); } } catch (e) { /* copie impossible */ } }
        });
      }
    });

    // ------------------------------------------------------------ Fenêtre « Ajouter un filtre » : nom et nombre de valeurs selon le champ
    var addForm = document.querySelector('[data-filter-add]');
    if (addForm && addForm.querySelector('[data-filter-code]')) { // pas de liste quand tout est déjà ajouté
      var code = addForm.querySelector('[data-filter-code]');
      var flabel = addForm.querySelector('[data-filter-label]');
      var limitRow = addForm.querySelector('[data-filter-limit-row]');
      var limitInput = addForm.querySelector('[data-filter-limit]');
      var appearanceHelp = addForm.querySelector('[data-filter-appearance]');
      var names = { terms: 'Liste de valeurs', range: 'Plage (min / max)', toggle: 'Case à cocher' };
      var lastAuto = '';
      code.addEventListener('change', function () {
        var o = code.options[code.selectedIndex];
        var ap = o.getAttribute('data-appearance');
        if (flabel.value === '' || flabel.value === lastAuto) { flabel.value = o.getAttribute('data-label'); lastAuto = flabel.value; }
        limitRow.hidden = ap !== 'terms';
        var openRow = addForm.querySelector('[data-filter-open-row]');
        if (openRow) { openRow.hidden = ap === 'toggle'; }
        limitInput.value = o.getAttribute('data-limit');
        var orderRow = addForm.querySelector('[data-filter-order-row]');
        if (orderRow) { orderRow.hidden = ap !== 'terms'; addForm.querySelector('[data-filter-order]').value = o.getAttribute('data-order') || 'count'; }
        appearanceHelp.textContent = 'Apparence : ' + (names[ap] || ap) + '.';
      });
    }

    // ------------------------------------------------------------ Listes ordonnées (filtres, tris)
    document.querySelectorAll('[data-order-form]').forEach(function (form) {
      var kind = form.getAttribute('data-order-form');
      var list = form.querySelector('[data-order-list]');
      var dragged = null;

      function rows() { return Array.prototype.slice.call(list.querySelectorAll('[data-row]')); }
      function bindRow(tr) {
        tr.addEventListener('dragstart', function (ev) {
          // pas de glisser depuis un champ de saisie (sélection de texte)
          if (ev.target.closest && ev.target.closest('input, select, button')) { ev.preventDefault(); return; }
          dragged = tr;
          tr.classList.add('ly-dragging');
          ev.dataTransfer.effectAllowed = 'move';
          try { ev.dataTransfer.setData('text/plain', ''); } catch (e) { /* Firefox exige une donnée */ }
        });
        tr.addEventListener('dragend', function () { tr.classList.remove('ly-dragging'); dragged = null; });
        tr.addEventListener('dragover', function (ev) {
          if (!dragged || dragged === tr) { return; }
          ev.preventDefault();
          var r = tr.getBoundingClientRect();
          list.insertBefore(dragged, ev.clientY > r.top + r.height / 2 ? tr.nextSibling : tr);
        });
        var rm = tr.querySelector('[data-remove]');
        if (rm) {
          rm.addEventListener('click', function () {
            // Filtres : retrait enregistré tout de suite (après confirmation) ; tris : retiré de la liste, à enregistrer
            if (!rm.hasAttribute('data-remove-save')) { tr.remove(); syncMissing(); return; }
            window.DeepFindUI.confirm(rm.getAttribute('data-remove-confirm'), { ok: 'Retirer', danger: true }).then(function (yes) {
              if (!yes) { return; }
              tr.remove();
              form.querySelector('input[name=removed]').value = '1';
              if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
            });
          });
        }
        // Tri : champ → ordres possibles selon son type (texte, nombre, date) → nom affiché par défaut
        var field = tr.querySelector('select[data-f="field"]');
        var dir = tr.querySelector('select[data-f="dir"]');
        var label = tr.querySelector('input[data-f="label"]');
        if (field && dir && meta) {
          var placeholder = function () {
            var fd = meta.fields[field.value];
            var dd = fd && meta.dirs[fd[1]] && meta.dirs[fd[1]][dir.value];
            label.placeholder = fd && dd ? fd[0] + ' ' + dd[1] : 'Saisir le nom d\'affichage';
          };
          field.addEventListener('change', function () {
            var fd = meta.fields[field.value];
            var keep = dir.value;
            dir.textContent = '';
            var ph = document.createElement('option');
            ph.value = ''; ph.disabled = true; ph.textContent = 'Sélectionner l\'ordre';
            dir.appendChild(ph);
            Object.keys(fd ? meta.dirs[fd[1]] : {}).forEach(function (d) {
              var o = document.createElement('option');
              o.value = d; o.textContent = meta.dirs[fd[1]][d][0];
              dir.appendChild(o);
            });
            dir.disabled = !fd;
            dir.value = keep && dir.querySelector('option[value="' + keep + '"]') ? keep : '';
            if (!dir.value) { ph.selected = true; }
            placeholder();
            if (!dir.disabled && !dir.value) { dir.focus(); }
          });
          dir.addEventListener('change', placeholder);
          label.addEventListener('input', syncMissing);
        }
      }
      var metaAttr = form.getAttribute('data-sort-meta');
      var meta = metaAttr ? JSON.parse(metaAttr) : null;
      var missing = form.querySelector('[data-missing-label]');
      function syncMissing() {
        if (missing) { missing.hidden = !rows().some(function (tr) { var l = tr.querySelector('input[data-f="label"]'); return l && l.value.trim() === ''; }); }
      }
      rows().forEach(bindRow);
      syncMissing();

      var add = form.querySelector('[data-add-row]');
      var tpl = form.querySelector('template[data-row-template]');
      if (add && tpl) {
        add.addEventListener('click', function () {
          var tr = tpl.content.firstElementChild.cloneNode(true);
          list.appendChild(tr);
          bindRow(tr);
          syncMissing();
          tr.querySelector('select, input').focus();
        });
      }

      form.addEventListener('submit', function () {
        var payload;
        if (kind === 'filters') {
          payload = rows().map(function (tr) {
            var limit = tr.querySelector('[data-f="limit"]');
            var open = tr.querySelector('[data-f="open"]');
            var order = tr.querySelector('[data-f="order"]');
            return { code: tr.getAttribute('data-code'), label: tr.querySelector('[data-f="label"]').value, visible: tr.querySelector('[data-f="visible"]').checked,
              limit: limit ? parseInt(limit.value, 10) || 0 : 0, open: open ? open.checked : true, order: order ? order.value : '' };
          });
        } else {
          payload = {
            relevance: form.querySelector('[data-relevance]').value,
            items: rows().map(function (tr) {
              return { field: tr.querySelector('[data-f="field"]').value, dir: tr.querySelector('[data-f="dir"]').value, label: tr.querySelector('[data-f="label"]').value };
            })
          };
        }
        form.querySelector('input[name=payload]').value = JSON.stringify(payload);
      });
    });
  });
})();
