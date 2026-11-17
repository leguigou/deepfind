/* Conditions sur les produits (règles de boost, résultats personnalisés) : liste de valeurs avec recherche, choix du champ.
 * DFConditions({fields, valuesUrl, root, listOps, textOps}) → { el, multiSelect(code, selected), conditionRow(condition) }.
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
    var textOps = !!opts.textOps;
    var valuesCache = {};

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined) { n.textContent = text; }
    return n;
  }

  // ---------------------------------------------------------------- Valeurs proposées
  // cb(valeurs, incomplet) : « incomplet » = le moteur n'en a renvoyé qu'une partie (100 au plus) ; voir searchValues()
  function loadValues(code, cb) {
    var def = byCode[code];
    if (def && def.static) {
      cb(Object.keys(def.static).map(function (v) { return { value: v, label: def.static[v] }; }), false);
      return;
    }
    if (code === 'sku') { cb([], false); return; }
    if (valuesCache[code]) { cb(valuesCache[code].values, valuesCache[code].truncated); return; }
    fetch(valuesUrl + '?field=' + encodeURIComponent(code), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : { values: [] }; })
      .then(function (d) { valuesCache[code] = { values: d.values || [], truncated: !!d.truncated }; cb(valuesCache[code].values, valuesCache[code].truncated); })
      .catch(function () { cb([], false); });
  }
  // Recherche parmi TOUTES les valeurs du champ (pas seulement celles chargées au départ)
  function searchValues(code, q, cb) {
    fetch(valuesUrl + '?field=' + encodeURIComponent(code) + '&q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : { values: [] }; })
      .then(function (d) { cb(d.values || []); })
      .catch(function () { cb([]); });
  }

  // Liste à cocher avec recherche, « Toutes les valeurs » et saisie libre d'une valeur absente
  function multiSelect(code, selected) {
    var box = el('div', 'ms');
    var sel = selected.slice();
    var all = [];
    // div focusable (et non <button>) : chaque tag contient son propre bouton « ✕ »
    var toggle = el('div', 'ms-toggle');
    toggle.tabIndex = 0;
    toggle.setAttribute('role', 'button');
    toggle.setAttribute('aria-haspopup', 'listbox');
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
    var hint = el('div', 'ms-hint muted small', 'Liste limitée aux 100 premières valeurs : tapez dans « Rechercher… » pour chercher parmi toutes.');
    hint.hidden = true;
    var truncated = false;
    var remoteTimer = null;
    var addFree = el('button', 'btn btn-sm btn-light ms-add');
    addFree.type = 'button';
    addFree.hidden = true;
    panel.appendChild(search);
    panel.appendChild(hint);
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
      sel.slice(0, 8).forEach(function (v) {
        var chip = el('span', 'chip', label(v));
        var x = el('button', 'chip-x', '✕');
        x.type = 'button';
        x.title = 'Retirer';
        x.setAttribute('aria-label', 'Retirer ' + label(v));
        x.addEventListener('click', function (ev) {
          ev.stopPropagation();
          var i = sel.indexOf(v);
          if (i !== -1) { sel.splice(i, 1); }
          renderToggle();
          renderList();
          toggle.focus();
        });
        chip.appendChild(x);
        toggle.appendChild(chip);
      });
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
    search.addEventListener('input', function () {
      renderList();
      // liste incomplète : on interroge le moteur sur toutes les valeurs et on ajoute celles qui manquent
      var q = search.value.trim();
      clearTimeout(remoteTimer);
      if (!truncated || q === '') { return; }
      remoteTimer = setTimeout(function () {
        searchValues(code, q, function (found) {
          var known = {};
          all.forEach(function (o) { known[o.value] = true; });
          var added = found.filter(function (o) { return !known[o.value]; });
          if (!added.length) { return; }
          all = all.concat(added).sort(function (a, b) { return a.label.localeCompare(b.label, 'fr', { numeric: true, sensitivity: 'base' }); });
          if (search.value.trim() === q) { renderList(); }
        });
      }, 250);
    });
    search.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); addSearchValue(); }
      if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); panel.hidden = true; toggle.focus(); }
    });
    addFree.addEventListener('click', addSearchValue);
    toggle.addEventListener('keydown', function (ev) {
      if (ev.target === toggle && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); toggle.click(); }
    });
    toggle.addEventListener('click', function () {
      var open = panel.hidden;
      root.querySelectorAll('.ms-panel').forEach(function (p) { p.hidden = true; });
      panel.hidden = !open;
      if (open) { search.focus(); }
    });
    loadValues(code, function (vals, isTruncated) { all = vals.slice(); truncated = isTruncated; hint.hidden = !truncated; renderToggle(); renderList(); });
    renderToggle();
    box.getValues = function () { return sel.slice(); };
    return box;
  }


  // Plusieurs textes sous forme de tags (OU) : Entrée, virgule ou sortie du champ ajoute le texte ; ✕ ou Retour arrière le retire
  function tagInput(initial) {
    var box = el('div', 'ms-toggle tag-input');
    var tags = [];
    var input = el('input', 'br-text');
    input.type = 'text';
    input.maxLength = 190;
    input.setAttribute('aria-label', 'Texte (Entrée pour en ajouter un autre)');
    function fold(s) { return String(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim(); }
    function render() {
      box.querySelectorAll('.chip').forEach(function (c) { c.remove(); });
      tags.forEach(function (t, i) {
        var chip = el('span', 'chip', t);
        var x = el('button', 'chip-x', '✕');
        x.type = 'button';
        x.title = 'Retirer';
        x.setAttribute('aria-label', 'Retirer ' + t);
        x.addEventListener('click', function (ev) { ev.stopPropagation(); tags.splice(i, 1); render(); input.focus(); });
        chip.appendChild(x);
        box.insertBefore(chip, input);
      });
      input.placeholder = tags.length ? 'Ajouter (OU)…' : 'Texte, puis Entrée';
    }
    function add(raw) {
      String(raw).split(',').forEach(function (part) {
        var t = part.trim();
        if (t && !tags.some(function (x) { return fold(x) === fold(t); })) { tags.push(t); }
      });
      input.value = '';
      render();
    }
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' || ev.key === ',') { ev.preventDefault(); add(input.value); }
      if (ev.key === 'Backspace' && input.value === '' && tags.length) { tags.pop(); render(); }
    });
    input.addEventListener('blur', function () { if (input.value.trim()) { add(input.value); } });
    box.addEventListener('click', function (ev) { if (ev.target === box) { input.focus(); } });
    box.appendChild(input);
    initial.forEach(function (t) { add(t); });
    render();
    box.getValues = function () { if (input.value.trim()) { add(input.value); } return tags.slice(); };
    box.focusInput = function () { input.focus(); };
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
      if ((textOps && !def.static) || def.type === 'text') {
        // « est » (valeurs cochées) ou comparaison de texte : contient, ne contient pas, commence par, finit par (règles de boost).
        // Champ texte (nom du produit) : comparaison de texte uniquement, trop de valeurs pour une liste à cocher.
        var top = el('select', 'br-op');
        (def.type === 'text' ? [] : [['is', 'est']]).concat([['contains', 'contient'], ['not_contains', 'ne contient pas'], ['starts', 'commence par'], ['ends', 'finit par']]).forEach(function (p) {
          var o = el('option', '', p[1]); o.value = p[0]; top.appendChild(o);
        });
        top.setAttribute('aria-label', 'Comparaison');
        var tl = el('div', 'br-list-line');
        var slot = el('div', 'br-text-slot');
        tl.appendChild(top);
        tl.appendChild(slot);
        valueBox.appendChild(tl);
        var tms = null;
        var txt = null;
        var renderSlot = function () {
          slot.textContent = '';
          if (top.value === 'is') {
            if (!tms) { tms = multiSelect(def.code, init && init.values ? init.values : []); }
            slot.appendChild(tms);
            return;
          }
          if (!txt) { txt = tagInput(init ? (init.texts || (init.text ? [init.text] : [])) : []); }
          slot.appendChild(txt);
        };
        if (init && init.match) { top.value = init.match; }
        top.addEventListener('change', function () { renderSlot(); if (txt && top.value !== 'is') { txt.focusInput(); } });
        renderSlot();
        row.getCondition = function () {
          return top.value === 'is' ? { field: def.code, values: tms.getValues() } : { field: def.code, match: top.value, texts: txt.getValues() };
        };
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
