/* Intégration : paramètres d'un layer (renommer, couleurs), listes ordonnées des filtres et des tris
 * (glisser-déposer, ajout / retrait, recherche ; tri = champ puis ordre selon son type puis nom). Aucune donnée insérée en HTML brut. */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
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
          if (area.value.trim() !== '' && !window.confirm('Remplacer le contenu actuel par le modèle par défaut ?')) { return; }
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
        if (rm) { rm.addEventListener('click', function () { tr.remove(); syncMissing(); }); }
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

      var search = form.querySelector('[data-order-search]');
      if (search) {
        search.addEventListener('input', function () {
          var q = search.value.trim().toLowerCase();
          rows().forEach(function (tr) { tr.hidden = q !== '' && tr.textContent.toLowerCase().indexOf(q) === -1 && tr.querySelector('input').value.toLowerCase().indexOf(q) === -1; });
        });
        search.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); } });
      }

      form.addEventListener('submit', function () {
        var payload;
        if (kind === 'filters') {
          payload = rows().map(function (tr) {
            return { code: tr.getAttribute('data-code'), label: tr.querySelector('[data-f="label"]').value, visible: tr.querySelector('[data-f="visible"]').checked };
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
