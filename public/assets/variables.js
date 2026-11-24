/* Paramètres › Variables : ajout / suppression de variables personnalisées, copie d'un {{code}}. */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-var-form]');
    if (!form) { return; }
    var rows = form.querySelector('[data-var-rows]');
    var reserved = JSON.parse(rows.getAttribute('data-reserved') || '[]');
    var next = rows.querySelectorAll('[data-var-row]').length;
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
    function input(name, attrs) {
      var i = el('input');
      i.name = name;
      Object.keys(attrs).forEach(function (k) { i.setAttribute(k, attrs[k]); });
      return i;
    }
    function bindRemove(tr) {
      tr.querySelector('[data-var-remove]').addEventListener('click', function () { tr.remove(); });
    }
    rows.querySelectorAll('[data-var-row]').forEach(bindRemove);
    form.querySelector('[data-var-add]').addEventListener('click', function () {
      var i = next++;
      var tr = el('tr');
      tr.setAttribute('data-var-row', '');
      var td1 = el('td');
      td1.appendChild(el('span', 'var-brace', '{{'));
      var code = input('vars[' + i + '][code]', { class: 'var-code-input', maxlength: '31', pattern: '[a-z][a-z0-9_]{1,30}', 'aria-label': 'Code', required: 'required', placeholder: 'livraison' });
      td1.appendChild(code);
      td1.appendChild(el('span', 'var-brace', '}}'));
      var td2 = el('td');
      td2.appendChild(input('vars[' + i + '][value]', { maxlength: '300', 'aria-label': 'Valeur', placeholder: 'ex. Livraison offerte dès 50 €' }));
      var td3 = el('td');
      td3.appendChild(input('vars[' + i + '][description]', { maxlength: '300', 'aria-label': 'Description', placeholder: 'ex. Bandeau des cartes produit' }));
      var td4 = el('td', 'actions');
      var rm = el('button', 'icon-btn', '✕');
      rm.type = 'button';
      rm.setAttribute('data-var-remove', '');
      rm.setAttribute('aria-label', 'Supprimer cette variable');
      td4.appendChild(rm);
      [td1, td2, td3, td4].forEach(function (td) { tr.appendChild(td); });
      rows.appendChild(tr);
      bindRemove(tr);
      code.focus();
    });
    // code : minuscules, sans accent ni espace ; code d'une variable système refusé avant l'envoi
    form.addEventListener('input', function (ev) {
      if (!ev.target.classList.contains('var-code-input')) { return; }
      var v = ev.target.value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9_]/g, '_');
      if (v !== ev.target.value) { ev.target.value = v; }
      ev.target.setCustomValidity(reserved.indexOf(v) !== -1 ? '« ' + v + ' » est une variable système : choisissez un autre code.' : '');
    });
    form.querySelectorAll('[data-copy-var]').forEach(function (b) {
      b.addEventListener('click', function () {
        var txt = b.getAttribute('data-copy-var');
        if (navigator.clipboard) { navigator.clipboard.writeText(txt); }
        b.classList.add('is-copied');
        setTimeout(function () { b.classList.remove('is-copied'); }, 1200);
      });
    });
  });
})();
