/* Intégration : configuration d'un layer (champs selon le type), listes ordonnées des filtres et des tris
 * (glisser-déposer, flèches, ajout / retrait, recherche). Aucune donnée insérée en HTML brut. */
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
        tr.querySelectorAll('[data-move]').forEach(function (b) {
          b.addEventListener('click', function () {
            var dir = parseInt(b.getAttribute('data-move'), 10);
            var sib = dir < 0 ? tr.previousElementSibling : tr.nextElementSibling;
            if (!sib) { return; }
            list.insertBefore(tr, dir < 0 ? sib : sib.nextSibling);
            b.focus();
          });
        });
        var rm = tr.querySelector('[data-remove]');
        if (rm) { rm.addEventListener('click', function () { tr.remove(); }); }
        // tri : nom proposé = libellé du tri choisi, tant que le nom n'a pas été modifié
        var key = tr.querySelector('select[data-f="key"]');
        var label = tr.querySelector('input[data-f="label"]');
        if (key && label) {
          var auto = function () { return key.options[key.selectedIndex].textContent; };
          if (label.value === '') { label.value = auto(); }
          var last = label.value === auto() ? label.value : null;
          key.addEventListener('change', function () {
            if (last !== null && label.value === last) { label.value = auto(); last = label.value; }
          });
          label.addEventListener('input', function () { last = null; });
        }
      }
      rows().forEach(bindRow);

      var add = form.querySelector('[data-add-row]');
      var tpl = form.querySelector('template[data-row-template]');
      if (add && tpl) {
        add.addEventListener('click', function () {
          var tr = tpl.content.firstElementChild.cloneNode(true);
          list.appendChild(tr);
          bindRow(tr);
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
            items: rows().map(function (tr) { return { key: tr.querySelector('[data-f="key"]').value, label: tr.querySelector('[data-f="label"]').value }; })
          };
        }
        form.querySelector('input[name=payload]').value = JSON.stringify(payload);
      });
    });
  });
})();
