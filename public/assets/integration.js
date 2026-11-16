/* Intégration : configuration d'un layer (champs selon le type), listes ordonnées des filtres et des tris
 * (glisser-déposer, ajout / retrait, recherche ; tri = champ puis ordre selon son type puis nom). Aucune donnée insérée en HTML brut. */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    // ------------------------------------------------------------ Layer : champs propres au type choisi
    var lf = document.querySelector('[data-layer-form]');
    if (lf) {
      var type = lf.querySelector('[data-layer-type]');
      var help = lf.querySelector('[data-layer-type-help]');
      var sync = function () {
        lf.querySelectorAll('[data-layer-only]').forEach(function (n) {
          var on = n.getAttribute('data-layer-only') === type.value;
          n.hidden = !on;
          n.querySelectorAll('input').forEach(function (i) { i.required = on && i.name === 'container'; });
        });
        var opt = type.options[type.selectedIndex];
        if (help && opt) { help.textContent = opt.getAttribute('data-help') || ''; }
      };
      type.addEventListener('change', sync);
      sync();
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
