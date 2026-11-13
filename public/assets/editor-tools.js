/* Outils des pages d'édition (résultats personnalisés, bannières, redirections) :
 * DFEditor(root, {terms, starts_at, ends_at}) → { terms: [...], period(): {on, start, end} }.
 * root contient les blocs du gabarit _terms_period.php. Données affichées avec textContent uniquement. */
(function () {
  'use strict';
  window.DFEditor = function (root, init) {
    init = init || {};
    var state = { terms: (init.terms || []).slice(), start: init.starts_at || '', end: init.ends_at || '' };
    var f = function (name) { return root.querySelector('[data-f="' + name + '"]'); };
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
    var dur = root.querySelector('input[data-duration][value=' + (state.start ? 'period' : 'always') + ']');
    if (dur) { dur.checked = true; }

  // ---------------------------------------------------------------- Termes
  var termsList = root.querySelector('[data-terms]');
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
  root.querySelector('[data-add-term]').addEventListener('click', addTerm);
  f('term').addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); addTerm(); } });
  renderTerms();

  // ---------------------------------------------------------------- Période : calendrier de 2 mois
  var MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
  var rangeBtn = root.querySelector('[data-range]');
  var cal = root.querySelector('[data-cal]');
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
  function periodOn() { var r = root.querySelector('input[data-duration]:checked'); return !!r && r.value === 'period'; }
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
    root.querySelector('input[data-duration][value=period]').checked = true;
    renderRange();
    cal.hidden = !cal.hidden;
    if (!cal.hidden) { renderCal(); }
  });
  root.querySelectorAll('input[data-duration]').forEach(function (r) {
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

    return {
      terms: state.terms,
      addPending: function () { if (f('term').value.trim()) { addTerm(); } },
      period: function () { return { on: periodOn(), start: state.start, end: state.end }; }
    };
  };
})();
