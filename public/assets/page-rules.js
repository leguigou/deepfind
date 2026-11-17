/*
 * Recommandations › Règles (selon la page) :
 *  - liste : ordre d'application par glisser-déposer (enregistré au lâcher) ;
 *  - fiche : adresses de page, action, texte du bandeau, conditions produits (conditions.js, mode est / n'est pas).
 */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var ui = window.DeepFindUI;

    // ------------------------------------------------------------ Liste : ordre par glisser-déposer
    var list = document.querySelector('[data-pr-list]');
    var orderForm = document.querySelector('[data-pr-order]');
    if (list && orderForm) {
      var dragged = null;
      var before = '';
      var ids = function () { return Array.prototype.map.call(list.querySelectorAll('[data-row]'), function (r) { return r.getAttribute('data-id'); }).join(','); };
      list.querySelectorAll('[data-row]').forEach(function (tr) {
        tr.addEventListener('dragstart', function (ev) {
          if (ev.target.closest && ev.target.closest('input, select, button, a')) { ev.preventDefault(); return; }
          dragged = tr;
          before = ids();
          tr.classList.add('ly-dragging');
          ev.dataTransfer.effectAllowed = 'move';
          try { ev.dataTransfer.setData('text/plain', ''); } catch (e) { /* Firefox exige une donnée */ }
        });
        tr.addEventListener('dragover', function (ev) {
          if (!dragged || dragged === tr) { return; }
          ev.preventDefault();
          var r = tr.getBoundingClientRect();
          list.insertBefore(dragged, ev.clientY > r.top + r.height / 2 ? tr.nextSibling : tr);
        });
        tr.addEventListener('dragend', function () {
          tr.classList.remove('ly-dragging');
          dragged = null;
          list.querySelectorAll('[data-rank]').forEach(function (td, i) { td.textContent = String(i + 1); });
          if (ids() !== before) {
            orderForm.querySelector('input[name=ids]').value = ids();
            orderForm.submit();
          }
        });
      });
    }

    // ------------------------------------------------------------ Fiche d'une règle
    var form = document.querySelector('[data-pr-form]');
    if (!form) { return; }
    var rule = JSON.parse(form.getAttribute('data-rule') || '{}');
    var fields = JSON.parse(form.getAttribute('data-fields') || '[]');
    var urlOps = JSON.parse(form.getAttribute('data-url-ops') || '{}');
    var byCode = {};
    fields.forEach(function (fd) { byCode[fd.code] = fd; });
    function f(name) { return form.querySelector('[data-f=' + name + ']'); }
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }

    f('name').value = rule.name || '';
    f('active').checked = rule.active !== false;
    f('label').value = rule.label || '';

    // Adresses de page
    var urlsBox = form.querySelector('[data-urls]');
    function addUrl(u) {
      u = u || { match: 'contains', text: '' };
      var row = el('div', 'pr-url');
      var op = el('select', 'pr-url-op');
      op.setAttribute('aria-label', 'Comparaison de l\'adresse');
      Object.keys(urlOps).forEach(function (k) { var o = el('option', '', urlOps[k]); o.value = k; op.appendChild(o); });
      op.value = urlOps[u.match] ? u.match : 'contains';
      var txt = el('input', 'pr-url-text');
      txt.type = 'text';
      txt.maxLength = 300;
      txt.placeholder = '/nautisme/';
      txt.value = u.text || '';
      txt.setAttribute('aria-label', 'Adresse de la page');
      var rm = el('button', 'icon-btn', '✕');
      rm.type = 'button';
      rm.setAttribute('aria-label', 'Retirer cette adresse');
      rm.addEventListener('click', function () { row.remove(); if (!urlsBox.children.length) { addUrl(); } });
      row.appendChild(op);
      row.appendChild(txt);
      row.appendChild(rm);
      urlsBox.appendChild(row);
      return txt;
    }
    (rule.urls && rule.urls.length ? rule.urls : [{}]).forEach(function (u) { addUrl(u); });
    form.querySelector('[data-add-url]').addEventListener('click', function () { addUrl().focus(); });

    // Action (le texte du bandeau ne sert qu'au filtre pré-coché)
    var labelRow = form.querySelector('[data-label-row]');
    function syncAction() {
      var a = form.querySelector('input[name=pr_action]:checked');
      labelRow.hidden = !a || a.value !== 'prefilter';
    }
    var act = form.querySelector('input[name=pr_action][value="' + (rule.action || 'prefilter') + '"]');
    if (act) { act.checked = true; }
    form.querySelectorAll('input[name=pr_action]').forEach(function (r) { r.addEventListener('change', syncAction); });
    syncAction();

    // Conditions produits (est / n'est pas, prix…) : même éditeur que les résultats personnalisés
    var root = form.querySelector('[data-rule-root]');
    var conds = form.querySelector('[data-conds]');
    form.querySelector('input[name=pr_match][value=' + (rule.match_all === false ? 'any' : 'all') + ']').checked = true;
    var lib = window.DFConditions({ fields: fields, valuesUrl: form.getAttribute('data-values-url'), root: root, listOps: true });
    function addCond(c) {
      var row = lib.conditionRow(c || {});
      conds.appendChild(row);
      if (!c || !c.field) { row.focusField(); }
    }
    (rule.conditions && rule.conditions.length ? rule.conditions : [null]).forEach(function (c) { addCond(c); });
    form.querySelector('[data-add-cond]').addEventListener('click', function () { addCond(); });
    // clic n'importe où hors d'une liste de valeurs : elle se referme (sinon elle recouvre le bouton Enregistrer)
    document.addEventListener('click', function (ev) {
      if (ev.target.isConnected && !ev.target.closest('.ms')) { root.querySelectorAll('.ms-panel').forEach(function (p) { p.hidden = true; }); }
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { root.querySelectorAll('.ms-panel').forEach(function (p) { p.hidden = true; }); }
    });

    form.addEventListener('submit', function (ev) {
      var urls = [];
      urlsBox.querySelectorAll('.pr-url').forEach(function (r) {
        var text = r.querySelector('.pr-url-text').value.trim();
        if (text) { urls.push({ match: r.querySelector('.pr-url-op').value, text: text }); }
      });
      var list = [];
      var problem = '';
      conds.querySelectorAll('.br-cond').forEach(function (r) {
        var c = r.getCondition && r.getCondition();
        if (!c) { return; }
        if (c.values && !c.values.length) { problem = 'Choisissez au moins une valeur pour « ' + (byCode[c.field] || {}).label + ' ».'; return; }
        if (c.value !== undefined && (c.value === '' || isNaN(Number(c.value)))) { problem = 'Valeur numérique attendue pour « ' + (byCode[c.field] || {}).label + ' ».'; return; }
        if (c.value !== undefined) { c.value = Number(c.value); }
        list.push(c);
      });
      if (!urls.length) { problem = 'Indiquez au moins une adresse de page (ex. contient « /nautisme/ »).'; }
      else if (!problem && !list.length) { problem = 'Ajoutez au moins une condition sur les produits (ex. Catégorie est « Nautisme »).'; }
      if (problem) { ev.preventDefault(); ui.alert(problem); return; }
      form.querySelector('input[name=payload]').value = JSON.stringify({
        id: rule.id || 0, name: f('name').value, active: f('active').checked, urls: urls,
        action: form.querySelector('input[name=pr_action]:checked').value, label: f('label').value,
        match: form.querySelector('input[name=pr_match]:checked').value, conditions: list
      });
    });
  });
})();
