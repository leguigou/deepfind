/* Résultats personnalisés : page d'édition (termes, période avec calendrier, produits inclus / exclus un par un ou par règle).
 * Données affichées avec textContent uniquement. */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-cr-form]');
    if (!form) { return; }
    var cr = JSON.parse(form.getAttribute('data-cr') || '{}');
    var products = JSON.parse(form.getAttribute('data-products') || '{}');
    var fields = JSON.parse(form.getAttribute('data-fields') || '[]');
    var byCode = {};
    fields.forEach(function (fd) { byCode[fd.code] = fd; });
    var searchUrl = form.getAttribute('data-search-url');
    var f = function (name) { return form.querySelector('[data-f="' + name + '"]'); };
    var ui = window.DeepFindUI;

    var state = {
      terms: (cr.terms || []).slice(),
      start: cr.starts_at || '', end: cr.ends_at || '',
      include: { items: (cr.include_items || []).slice(), rules: (cr.include_rules || []).slice() },
      exclude: { items: (cr.exclude_items || []).slice(), rules: (cr.exclude_rules || []).slice() }
    };

    function el(tag, cls, text) {
      var n = document.createElement(tag);
      if (cls) { n.className = cls; }
      if (text !== undefined && text !== null) { n.textContent = String(text); }
      return n;
    }
    function btn(cls, text, label, fn) {
      var b = el('button', cls, text);
      b.type = 'button';
      if (label) { b.setAttribute('aria-label', label); b.title = label; }
      b.addEventListener('click', fn);
      return b;
    }

    // ---------------------------------------------------------------- Champs simples
    f('name').value = cr.name || '';
    f('active').checked = cr.active !== false;
    form.querySelector('input[name=cr_mode][value=' + (cr.mode === 'only' ? 'only' : 'first') + ']').checked = true;
    form.querySelector('input[name=cr_duration][value=' + (cr.starts_at ? 'period' : 'always') + ']').checked = true;

    // ---------------------------------------------------------------- Termes
    var termsList = form.querySelector('[data-terms]');
    function renderTerms() {
      termsList.textContent = '';
      state.terms.forEach(function (t, i) {
        var chip = el('span', 'chip');
        chip.title = t.match === 'broad' ? 'Correspondance large' : 'Correspondance exacte';
        chip.appendChild(el('span', 'chip-ico', t.match === 'broad' ? '≈' : '='));
        chip.appendChild(document.createTextNode(t.term));
        chip.appendChild(btn('chip-x', '✕', 'Retirer ' + t.term, function () { state.terms.splice(i, 1); renderTerms(); }));
        termsList.appendChild(chip);
      });
    }
    function addTerm() {
      var input = f('term');
      var term = input.value.trim().toLowerCase();
      if (!term) { input.focus(); return; }
      var match = f('term_match').value;
      if (!state.terms.some(function (t) { return t.term === term && t.match === match; })) { state.terms.push({ term: term, match: match }); }
      input.value = '';
      input.focus();
      renderTerms();
    }
    form.querySelector('[data-add-term]').addEventListener('click', addTerm);
    f('term').addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); addTerm(); } });
    renderTerms();

    // ---------------------------------------------------------------- Période : calendrier de 2 mois
    var MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    var rangeBtn = form.querySelector('[data-range]');
    var cal = form.querySelector('[data-cal]');
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var iso = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
    var fr = function (s) { var p = s.split('-'); return p[2] + '/' + p[1] + '/' + p[0]; };
    var parse = function (s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); };
    var view = state.start ? parse(state.start) : new Date();
    view = new Date(view.getFullYear(), view.getMonth(), 1);

    function renderRange() {
      var s = rangeBtn.querySelector('[data-range-start]');
      var e = rangeBtn.querySelector('[data-range-end]');
      s.textContent = state.start ? fr(state.start) : 'Date de début';
      e.textContent = state.end ? fr(state.end) : 'Date de fin';
      s.className = state.start ? '' : 'muted';
      e.className = state.end ? '' : 'muted';
      rangeBtn.classList.toggle('is-disabled', !periodOn());
    }
    function periodOn() { return form.querySelector('input[name=cr_duration]:checked').value === 'period'; }
    function month(first) {
      var box = el('div', 'cal-month');
      box.appendChild(el('div', 'cal-title', MONTHS[first.getMonth()] + ' ' + first.getFullYear()));
      var grid = el('div', 'cal-grid');
      ['L', 'M', 'M', 'J', 'V', 'S', 'D'].forEach(function (d) { grid.appendChild(el('span', 'cal-wd', d)); });
      var offset = (first.getDay() + 6) % 7;
      for (var i = 0; i < offset; i++) { grid.appendChild(el('span', 'cal-pad')); }
      var days = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
      var today = iso(new Date());
      for (var d = 1; d <= days; d++) {
        (function (day) {
          var key = iso(day);
          var b = btn('cal-day', day.getDate(), null, function () { pick(key); });
          if (key === today) { b.classList.add('is-today'); }
          if (state.start && key === state.start) { b.classList.add('is-start'); }
          if (state.end && key === state.end) { b.classList.add('is-end'); }
          if (state.start && state.end && key > state.start && key < state.end) { b.classList.add('is-in'); }
          b.setAttribute('aria-label', fr(key));
          grid.appendChild(b);
        })(new Date(first.getFullYear(), first.getMonth(), d));
      }
      box.appendChild(grid);
      return box;
    }
    function renderCal() {
      cal.textContent = '';
      var nav = el('div', 'cal-nav');
      nav.appendChild(btn('icon-btn', '‹', 'Mois précédent', function () { view = new Date(view.getFullYear(), view.getMonth() - 1, 1); renderCal(); }));
      nav.appendChild(el('span', 'muted small', state.start && !state.end ? 'Choisissez la date de fin' : 'Choisissez la date de début'));
      nav.appendChild(btn('icon-btn', '›', 'Mois suivant', function () { view = new Date(view.getFullYear(), view.getMonth() + 1, 1); renderCal(); }));
      cal.appendChild(nav);
      var months = el('div', 'cal-months');
      months.appendChild(month(view));
      months.appendChild(month(new Date(view.getFullYear(), view.getMonth() + 1, 1)));
      cal.appendChild(months);
    }
    function pick(key) {
      if (!state.start || state.end || key < state.start) {
        state.start = key;
        state.end = '';
      } else {
        state.end = key;
        cal.hidden = true;
      }
      renderRange();
      if (!cal.hidden) { renderCal(); }
    }
    rangeBtn.addEventListener('click', function () {
      form.querySelector('input[name=cr_duration][value=period]').checked = true;
      renderRange();
      cal.hidden = !cal.hidden;
      if (!cal.hidden) { renderCal(); }
    });
    form.querySelectorAll('input[name=cr_duration]').forEach(function (r) {
      r.addEventListener('change', function () {
        renderRange();
        if (periodOn() && !state.start) { cal.hidden = false; renderCal(); } else if (!periodOn()) { cal.hidden = true; }
      });
    });
    document.addEventListener('click', function (ev) {
      // un jour cliqué est retiré du document (calendrier redessiné) : ce n'est pas un clic « en dehors »
      if (!cal.hidden && ev.target.isConnected && !ev.target.closest('.cr-range-wrap')) { cal.hidden = true; }
    });
    renderRange();

    // ---------------------------------------------------------------- Produits et règles inclus / exclus
    function label(code, v) {
      var fd = byCode[code];
      return fd && fd.static && fd.static[v] ? fd.static[v] : v;
    }
    function describe(rule) {
      return rule.conditions.map(function (c) {
        var fd = byCode[c.field] || { label: c.field };
        if (c.value !== undefined) { return fd.label + (c.op === 'lte' ? ' ≤ ' : ' ≥ ') + String(c.value).replace('.', ','); }
        var vals = c.values.map(function (v) { return label(c.field, v); });
        return fd.label + (c.op === 'not_in' ? ' n\'est pas ' : ' est ') + vals.slice(0, 6).join(', ') + (vals.length > 6 ? ' (+' + (vals.length - 6) + ')' : '');
      }).join(rule.match_all ? ' ET ' : ' OU ');
    }
    function renderBox(kind) {
      var box = form.querySelector('[data-box="' + kind + '"]');
      var list = box.querySelector('[data-items]');
      var rules = box.querySelector('[data-rules]');
      var data = state[kind];
      list.textContent = '';
      rules.textContent = '';
      data.items.forEach(function (sku, i) {
        var p = products[sku] || null;
        var li = el('li', 'cr-item');
        if (kind === 'include') { li.appendChild(el('span', 'cr-pos', String(i + 1))); }
        var img = el('span', 'cr-thumb');
        if (p && p.image && /^https?:\/\//i.test(p.image)) { var im = el('img'); im.src = p.image; im.alt = ''; im.loading = 'lazy'; img.appendChild(im); }
        li.appendChild(img);
        var txt = el('span', 'cr-item-text');
        txt.appendChild(el('span', 'cr-item-name', p ? p.name : 'Produit absent de l\'index'));
        var meta = el('small', 'muted');
        var code = el('code', null, sku);
        meta.appendChild(document.createTextNode(p && p.brand ? p.brand + ' · ' : ''));
        meta.appendChild(code);
        txt.appendChild(meta);
        li.appendChild(txt);
        var acts = el('span', 'cr-item-actions');
        if (kind === 'include') {
          var up = btn('icon-btn', '↑', 'Monter', function () { if (i > 0) { data.items.splice(i - 1, 0, data.items.splice(i, 1)[0]); renderBox(kind); } });
          var down = btn('icon-btn', '↓', 'Descendre', function () { if (i < data.items.length - 1) { data.items.splice(i + 1, 0, data.items.splice(i, 1)[0]); renderBox(kind); } });
          up.disabled = i === 0;
          down.disabled = i === data.items.length - 1;
          acts.appendChild(up);
          acts.appendChild(down);
        }
        acts.appendChild(btn('icon-btn', '✕', 'Retirer ' + sku, function () { data.items.splice(i, 1); renderBox(kind); }));
        li.appendChild(acts);
        list.appendChild(li);
      });
      data.rules.forEach(function (rule, i) {
        var li = el('li', 'cr-rule');
        li.appendChild(el('span', 'cr-rule-ico', 'Règle'));
        li.appendChild(el('span', 'cr-rule-text', describe(rule)));
        var acts = el('span', 'cr-item-actions');
        acts.appendChild(btn('icon-btn', '✎', 'Modifier la règle', function () { openRule(kind, i); }));
        acts.appendChild(btn('icon-btn', '✕', 'Retirer la règle', function () { data.rules.splice(i, 1); renderBox(kind); }));
        li.appendChild(acts);
        rules.appendChild(li);
      });
      box.querySelector('[data-empty]').hidden = data.items.length + data.rules.length > 0;
    }

    // Menus « + Ajouter des résultats »
    form.querySelectorAll('[data-box]').forEach(function (box) {
      var kind = box.getAttribute('data-box');
      var menu = box.querySelector('.cr-menu-list');
      box.querySelector('[data-menu-toggle]').addEventListener('click', function () {
        var open = menu.hidden;
        form.querySelectorAll('.cr-menu-list').forEach(function (m) { m.hidden = true; });
        menu.hidden = !open;
      });
      box.querySelector('[data-add-items]').addEventListener('click', function () { menu.hidden = true; openItems(kind); });
      box.querySelector('[data-add-rule]').addEventListener('click', function () { menu.hidden = true; openRule(kind, -1); });
    });
    document.addEventListener('click', function (ev) {
      if (ev.target.isConnected && !ev.target.closest('.cr-menu')) { form.querySelectorAll('.cr-menu-list').forEach(function (m) { m.hidden = true; }); }
    });

    // ---------------------------------------------------------------- Fenêtre « éléments individuels »
    var itemsDlg = document.getElementById('cr-items-dialog');
    var itemSearch = itemsDlg.querySelector('[data-item-search]');
    var itemResults = itemsDlg.querySelector('[data-item-results]');
    var itemsConfirm = itemsDlg.querySelector('[data-items-confirm]');
    var itemsKind = 'include';
    var picked = [];
    var timer = null;
    var seq = 0;
    function updateConfirm() {
      itemsConfirm.disabled = picked.length === 0;
      itemsConfirm.textContent = 'Ajouter ' + picked.length + ' élément' + (picked.length > 1 ? 's' : '');
    }
    function searchItems() {
      var my = ++seq;
      itemResults.textContent = '';
      itemResults.appendChild(el('p', 'muted', 'Recherche…'));
      fetch(searchUrl + '?q=' + encodeURIComponent(itemSearch.value.trim()), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : { items: [] }; })
        .then(function (d) {
          if (my !== seq) { return; }
          itemResults.textContent = '';
          if (d.error) { itemResults.appendChild(el('p', 'flash flash-error', d.error)); }
          if (!d.items.length) { itemResults.appendChild(el('p', 'muted', 'Aucun produit trouvé.')); return; }
          var already = state[itemsKind].items;
          d.items.forEach(function (p) {
            var sku = p.sku.toUpperCase();
            products[sku] = products[sku] || p;
            var row = el('label', 'cr-pick');
            var cb = el('input');
            cb.type = 'checkbox';
            var inList = already.indexOf(sku) !== -1;
            cb.checked = inList || picked.indexOf(sku) !== -1;
            cb.disabled = inList;
            cb.addEventListener('change', function () {
              var k = picked.indexOf(sku);
              if (cb.checked && k === -1) { picked.push(sku); } else if (!cb.checked && k !== -1) { picked.splice(k, 1); }
              updateConfirm();
            });
            row.appendChild(cb);
            var th = el('span', 'cr-thumb');
            if (p.image && /^https?:\/\//i.test(p.image)) { var im = el('img'); im.src = p.image; im.alt = ''; im.loading = 'lazy'; th.appendChild(im); }
            row.appendChild(th);
            var t = el('span', 'cr-item-text');
            t.appendChild(el('span', 'cr-item-name', p.name));
            var m = el('small', 'muted');
            m.appendChild(document.createTextNode((p.brand ? p.brand + ' · ' : '')));
            m.appendChild(el('code', null, p.sku));
            m.appendChild(document.createTextNode(inList ? ' · déjà dans la liste' : ''));
            t.appendChild(m);
            row.appendChild(t);
            itemResults.appendChild(row);
          });
        })
        .catch(function () { if (my === seq) { itemResults.textContent = ''; itemResults.appendChild(el('p', 'flash flash-error', 'Recherche impossible.')); } });
    }
    function openItems(kind) {
      itemsKind = kind;
      picked = [];
      updateConfirm();
      itemsDlg.querySelector('#cr-items-title').textContent = kind === 'include' ? 'Ajouter des produits inclus' : 'Ajouter des produits exclus';
      itemsDlg.showModal();
      itemSearch.value = state.terms.length ? state.terms[0].term : '';
      itemSearch.focus();
      searchItems();
    }
    itemSearch.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(searchItems, 250); });
    itemSearch.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); clearTimeout(timer); searchItems(); } });
    itemsConfirm.addEventListener('click', function () {
      var other = state[itemsKind === 'include' ? 'exclude' : 'include'];
      picked.forEach(function (sku) {
        if (state[itemsKind].items.indexOf(sku) === -1) { state[itemsKind].items.push(sku); }
        var k = other.items.indexOf(sku);
        if (k !== -1) { other.items.splice(k, 1); } // un produit ne peut pas être inclus et exclu
      });
      itemsDlg.close();
      renderBox('include');
      renderBox('exclude');
    });

    // ---------------------------------------------------------------- Fenêtre « règle »
    var ruleDlg = document.getElementById('cr-rule-dialog');
    var ruleRoot = ruleDlg.querySelector('[data-rule-root]');
    var ruleConds = ruleDlg.querySelector('[data-rule-conds]');
    var lib = window.DFConditions({ fields: fields, valuesUrl: form.getAttribute('data-values-url'), root: ruleRoot, listOps: true });
    var ruleTarget = { kind: 'include', index: -1 };
    function addCond(c) {
      var row = lib.conditionRow(c || {});
      ruleConds.appendChild(row);
      if (!c || !c.field) { row.focusField(); }
    }
    function openRule(kind, index) {
      ruleTarget = { kind: kind, index: index };
      var rule = index >= 0 ? state[kind].rules[index] : { match_all: true, conditions: [] };
      ruleDlg.querySelector('#cr-rule-title').textContent = (index >= 0 ? 'Modifier la règle' : 'Ajouter une règle') + (kind === 'include' ? ' (inclus)' : ' (exclus)');
      ruleDlg.querySelector('input[name=cr_rule_match][value=' + (rule.match_all === false ? 'any' : 'all') + ']').checked = true;
      ruleConds.textContent = '';
      ruleDlg.showModal();
      if (rule.conditions.length) { rule.conditions.forEach(function (c) { addCond(c); }); } else { addCond(); }
    }
    ruleDlg.querySelector('[data-rule-add-cond]').addEventListener('click', function () { addCond(); });
    ruleRoot.addEventListener('click', function (ev) {
      if (!ev.target.closest('.ms')) { ruleRoot.querySelectorAll('.ms-panel').forEach(function (p) { p.hidden = true; }); }
    });
    ruleDlg.addEventListener('cancel', function (ev) {
      var openPanel = ruleRoot.querySelector('.ms-panel:not([hidden])');
      if (openPanel) { ev.preventDefault(); openPanel.hidden = true; }
    });
    ruleDlg.querySelector('[data-rule-confirm]').addEventListener('click', function () {
      var conds = [];
      var problem = '';
      ruleConds.querySelectorAll('.br-cond').forEach(function (r) {
        var c = r.getCondition && r.getCondition();
        if (!c) { return; }
        if (c.values && !c.values.length) { problem = 'Choisissez au moins une valeur pour « ' + (byCode[c.field] || {}).label + ' ».'; return; }
        if (c.value !== undefined && (c.value === '' || isNaN(Number(c.value)))) { problem = 'Valeur numérique attendue pour « ' + (byCode[c.field] || {}).label + ' ».'; return; }
        if (c.value !== undefined) { c.value = Number(c.value); }
        conds.push(c);
      });
      if (problem) { ui.alert(problem); return; }
      if (!conds.length) { ui.alert('Ajoutez au moins une condition (champ + valeurs).'); return; }
      var rule = { match_all: ruleDlg.querySelector('input[name=cr_rule_match]:checked').value !== 'any', conditions: conds };
      var list = state[ruleTarget.kind].rules;
      if (ruleTarget.index >= 0) { list[ruleTarget.index] = rule; } else { list.push(rule); }
      ruleDlg.close();
      renderBox(ruleTarget.kind);
    });

    renderBox('include');
    renderBox('exclude');

    // ---------------------------------------------------------------- Enregistrement
    form.addEventListener('submit', function (ev) {
      if (f('term').value.trim()) { addTerm(); }
      var problem = '';
      if (!state.terms.length) { problem = 'Ajoutez au moins un terme de recherche.'; }
      else if (periodOn() && (!state.start || !state.end)) { problem = 'Choisissez la date de début et la date de fin de la période.'; }
      else if (form.querySelector('input[name=cr_mode]:checked').value === 'only' && !state.include.items.length && !state.include.rules.length) {
        problem = 'Mode « uniquement les résultats sélectionnés » : ajoutez au moins un produit ou une règle dans « Résultats inclus ».';
      }
      if (problem) { ev.preventDefault(); ui.alert(problem); return; }
      form.querySelector('input[name=payload]').value = JSON.stringify({
        id: cr.id || 0, name: f('name').value, active: f('active').checked,
        duration: periodOn() ? 'period' : 'always', starts_at: state.start, ends_at: state.end,
        mode: form.querySelector('input[name=cr_mode]:checked').value, terms: state.terms,
        include_items: state.include.items, include_rules: state.include.rules,
        exclude_items: state.exclude.items, exclude_rules: state.exclude.rules
      });
    });
  });
})();
