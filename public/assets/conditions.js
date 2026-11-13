/* Conditions sur les produits (règles de boost, résultats personnalisés) : liste de valeurs avec recherche, choix du champ.
 * DFConditions({fields, valuesUrl, root, listOps}) → { el, multiSelect(code, selected), conditionRow(condition) }.
 * Aucune donnée n'est insérée en HTML brut (textContent uniquement). */
(function () {
  'use strict';
  window.DFConditions = function (opts) {
    var fields = opts.fields || [];
    var byCode = {};
    fields.forEach(function (f) { byCode[f.code] = f; });
    var valuesUrl = opts.valuesUrl;
    var root = opts.root || document;
    var listOps = !!opts.listOps;
    var valuesCache = {};

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined) { n.textContent = text; }
    return n;
  }

  // ---------------------------------------------------------------- Valeurs proposées
  function loadValues(code, cb) {
    var def = byCode[code];
    if (def && def.static) {
      cb(Object.keys(def.static).map(function (v) { return { value: v, label: def.static[v] }; }));
      return;
    }
    if (code === 'sku') { cb([]); return; }
    if (valuesCache[code]) { cb(valuesCache[code]); return; }
    fetch(valuesUrl + '?field=' + encodeURIComponent(code), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : { values: [] }; })
      .then(function (d) { valuesCache[code] = d.values || []; cb(valuesCache[code]); })
      .catch(function () { cb([]); });
  }

  // Liste à cocher avec recherche, « Toutes les valeurs » et saisie libre d'une valeur absente
  function multiSelect(code, selected) {
    var box = el('div', 'ms');
    var sel = selected.slice();
    var all = [];
    var toggle = el('button', 'ms-toggle');
    toggle.type = 'button';
    var panel = el('div', 'ms-panel');
    panel.hidden = true;
    var search = el('input', 'ms-search');
    search.placeholder = code === 'sku' ? 'Saisir un SKU puis Entrée' : 'Rechercher…';
    var allRow = el('label', 'ms-item ms-all');
    var allCb = el('input');
    allCb.type = 'checkbox';
    allRow.appendChild(allCb);
    allRow.appendChild(document.createTextNode(' Toutes les valeurs'));
    var list = el('div', 'ms-list');
    var addFree = el('button', 'btn btn-sm btn-light ms-add');
    addFree.type = 'button';
    addFree.hidden = true;
    panel.appendChild(search);
    panel.appendChild(allRow);
    panel.appendChild(list);
    panel.appendChild(addFree);
    box.appendChild(toggle);
    box.appendChild(panel);

    function label(v) {
      for (var i = 0; i < all.length; i++) { if (all[i].value === v) { return all[i].label; } }
      return v;
    }
    function renderToggle() {
      toggle.textContent = '';
      if (!sel.length) { toggle.appendChild(el('span', 'muted', 'Sélectionner des valeurs')); return; }
      sel.slice(0, 8).forEach(function (v) { toggle.appendChild(el('span', 'chip', label(v))); });
      if (sel.length > 8) { toggle.appendChild(el('span', 'muted', '+' + (sel.length - 8))); }
    }
    function visible() {
      var q = search.value.trim().toLowerCase();
      return all.filter(function (o) { return !q || o.label.toLowerCase().indexOf(q) !== -1; });
    }
    function renderList() {
      list.textContent = '';
      var vis = visible();
      vis.slice(0, 300).forEach(function (o) {
        var row = el('label', 'ms-item');
        var cb = el('input');
        cb.type = 'checkbox';
        cb.checked = sel.indexOf(o.value) !== -1;
        cb.addEventListener('change', function () {
          if (cb.checked) { sel.push(o.value); } else { sel.splice(sel.indexOf(o.value), 1); }
          renderToggle();
          renderAll();
        });
        row.appendChild(cb);
        row.appendChild(document.createTextNode(' ' + o.label));
        if (o.count !== undefined) { row.appendChild(el('span', 'muted ms-count', String(o.count))); }
        list.appendChild(row);
      });
      // valeurs déjà choisies mais absentes de la liste (saisie libre, SKU)
      sel.filter(function (v) { return !all.some(function (o) { return o.value === v; }); }).forEach(function (v) {
        var row = el('label', 'ms-item');
        var cb = el('input');
        cb.type = 'checkbox';
        cb.checked = true;
        cb.addEventListener('change', function () { sel.splice(sel.indexOf(v), 1); renderToggle(); renderList(); });
        row.appendChild(cb);
        row.appendChild(document.createTextNode(' ' + v));
        list.appendChild(row);
      });
      var q = search.value.trim();
      var exists = all.concat(sel.map(function (v) { return { value: v, label: v }; }))
        .some(function (o) { return o.value.toLowerCase() === q.toLowerCase(); });
      addFree.hidden = !q || exists;
      addFree.textContent = 'Ajouter « ' + q + ' »';
      allRow.hidden = all.length === 0;
      renderAll();
    }
    function renderAll() {
      var vis = visible();
      var n = vis.filter(function (o) { return sel.indexOf(o.value) !== -1; }).length;
      allCb.checked = vis.length > 0 && n === vis.length;
      allCb.indeterminate = n > 0 && n < vis.length;
    }
    function addSearchValue() {
      var q = search.value.trim();
      if (!q) { return; }
      var match = all.filter(function (o) { return o.label.toLowerCase() === q.toLowerCase(); })[0];
      var v = match ? match.value : q;
      if (sel.indexOf(v) === -1) { sel.push(v); }
      search.value = '';
      renderToggle();
      renderList();
    }
    allCb.addEventListener('change', function () {
      visible().forEach(function (o) {
        var i = sel.indexOf(o.value);
        if (allCb.checked && i === -1) { sel.push(o.value); }
        if (!allCb.checked && i !== -1) { sel.splice(i, 1); }
      });
      renderToggle();
      renderList();
    });
    search.addEventListener('input', renderList);
    search.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); addSearchValue(); }
      if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); panel.hidden = true; toggle.focus(); }
    });
    addFree.addEventListener('click', addSearchValue);
    toggle.addEventListener('click', function () {
      var open = panel.hidden;
      root.querySelectorAll('.ms-panel').forEach(function (p) { p.hidden = true; });
      panel.hidden = !open;
      if (open) { search.focus(); }
    });
    loadValues(code, function (vals) { all = vals; renderToggle(); renderList(); });
    renderToggle();
    box.getValues = function () { return sel.slice(); };
    return box;
  }


  function conditionRow(c) {
    c = c || {};
    var row = el('div', 'br-cond');
    var fieldSel = el('select', 'br-field');
    fieldSel.setAttribute('aria-label', 'Champ');
    var ph = el('option', '', 'Sélectionner un champ');
    ph.value = '';
    fieldSel.appendChild(ph);
    var groups = { fixed: el('optgroup'), attr: el('optgroup') };
    groups.fixed.label = 'Produit';
    groups.attr.label = 'Attributs';
    fields.forEach(function (fd) {
      var o = el('option', '', fd.label);
      o.value = fd.code;
      (fd.code.indexOf('attr.') === 0 ? groups.attr : groups.fixed).appendChild(o);
    });
    fieldSel.appendChild(groups.fixed);
    if (groups.attr.children.length) { fieldSel.appendChild(groups.attr); }
    var valueBox = el('div', 'br-value');
    var rm = el('button', 'icon-btn br-rm', '✕');
    rm.type = 'button';
    rm.setAttribute('aria-label', 'Retirer la condition');
    rm.addEventListener('click', function () { row.remove(); });
    var head = el('div', 'br-cond-head');
    head.appendChild(fieldSel);
    head.appendChild(rm);
    row.appendChild(head);
    row.appendChild(valueBox);

    function renderValue(init) {
      valueBox.textContent = '';
      row.getCondition = function () { return null; };
      var def = byCode[fieldSel.value];
      if (!def) {
        var dis = el('div', 'ms-toggle ms-disabled', 'Sélectionner des valeurs');
        valueBox.appendChild(dis);
        return;
      }
      if (def.type === 'num') {
        var op = el('select', 'br-op');
        [['gte', '≥ supérieur ou égal à'], ['lte', '≤ inférieur ou égal à']].forEach(function (p) {
          var o = el('option', '', p[1]);
          o.value = p[0];
          op.appendChild(o);
        });
        var num = el('input', 'br-num');
        num.type = 'number';
        num.step = 'any';
        num.required = true;
        num.placeholder = 'Valeur';
        if (init && init.op) { op.value = init.op; num.value = init.value; }
        var line = el('div', 'br-num-line');
        line.appendChild(op);
        line.appendChild(num);
        valueBox.appendChild(line);
        row.getCondition = function () { return { field: def.code, op: op.value, value: num.value }; };
        return;
      }
      var ms = multiSelect(def.code, init && init.values ? init.values : []);
      if (listOps) {
        // « est » / « n'est pas » (règles d'inclusion et d'exclusion des résultats personnalisés)
        var lop = el('select', 'br-op');
        [['in', 'est'], ['not_in', 'n\'est pas']].forEach(function (p) { var o = el('option', '', p[1]); o.value = p[0]; lop.appendChild(o); });
        if (init && init.op === 'not_in') { lop.value = 'not_in'; }
        var l2 = el('div', 'br-list-line');
        l2.appendChild(lop);
        l2.appendChild(ms);
        valueBox.appendChild(l2);
        row.getCondition = function () { return { field: def.code, op: lop.value, values: ms.getValues() }; };
        return;
      }
      valueBox.appendChild(ms);
      row.getCondition = function () { return { field: def.code, values: ms.getValues() }; };
    }
    fieldSel.addEventListener('change', function () { renderValue(null); });
    if (c.field && byCode[c.field]) { fieldSel.value = c.field; }
    renderValue(c);
    row.focusField = function () { fieldSel.focus(); };
    return row;
  }

    return { el: el, multiSelect: multiSelect, conditionRow: conditionRow };
  };
})();
