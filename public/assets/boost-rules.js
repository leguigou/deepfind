/* Règles de boost : fenêtre d'ajout / modification (termes, conditions, choix des valeurs). Aucune donnée insérée en HTML brut. */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-boost-rule-form]');
    if (!form) { return; }
    var dlg = form.closest('dialog');
    var fields = JSON.parse(form.getAttribute('data-fields') || '[]');
    var valuesUrl = form.getAttribute('data-values-url');
    var f = function (name) { return form.querySelector('[data-f="' + name + '"]'); };
    var termsBox = form.querySelector('[data-terms-box]');
    var termsList = form.querySelector('[data-terms]');
    var condsBox = form.querySelector('[data-conds]');
    var state = { id: 0, terms: [] };

    var lib = window.DFConditions({ fields: fields, valuesUrl: valuesUrl, root: form });
    var el = lib.el;
    function addCondition(c) {
      var row = lib.conditionRow(c || {});
      condsBox.appendChild(row);
      if (!c || !c.field) { row.focusField(); }
    }

    // ---------------------------------------------------------------- Termes
    function renderTerms() {
      termsList.textContent = '';
      state.terms.forEach(function (t, i) {
        var chip = el('span', 'chip');
        chip.title = t.match === 'broad' ? 'Correspondance large' : 'Correspondance exacte';
        chip.appendChild(el('span', 'chip-ico', t.match === 'broad' ? '≈' : '='));
        chip.appendChild(document.createTextNode(t.term));
        var x = el('button', 'chip-x', '✕');
        x.type = 'button';
        x.setAttribute('aria-label', 'Retirer ' + t.term);
        x.addEventListener('click', function () { state.terms.splice(i, 1); renderTerms(); });
        chip.appendChild(x);
        termsList.appendChild(chip);
      });
    }
    function addTerm() {
      var input = f('term');
      var term = input.value.trim();
      if (!term) { input.focus(); return; }
      var match = f('term_match').value;
      if (!state.terms.some(function (t) { return t.term.toLowerCase() === term.toLowerCase() && t.match === match; })) {
        state.terms.push({ term: term, match: match });
      }
      input.value = '';
      input.focus();
      renderTerms();
    }
    form.querySelector('[data-add-term]').addEventListener('click', addTerm);
    f('term').addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); addTerm(); } });
    f('terms_enabled').addEventListener('change', function () { termsBox.hidden = !f('terms_enabled').checked; });

    form.querySelector('[data-add-cond]').addEventListener('click', function () { addCondition(); });

    // Fermer les listes ouvertes au clic ailleurs
    form.addEventListener('click', function (ev) {
      if (ev.target.isConnected && !ev.target.closest('.ms')) { form.querySelectorAll('.ms-panel').forEach(function (p) { p.hidden = true; }); }
    });

    // Échap ferme d'abord une liste ouverte, pas la fenêtre
    dlg.addEventListener('cancel', function (ev) {
      var openPanel = form.querySelector('.ms-panel:not([hidden])');
      if (openPanel) { ev.preventDefault(); openPanel.hidden = true; }
    });

    // ---------------------------------------------------------------- Ouverture / enregistrement
    function open(rule) {
      rule = rule || { id: 0, name: '', active: true, boost: 1, terms: [], match_all: true, conditions: [] };
      state.id = rule.id || 0;
      state.terms = (rule.terms || []).slice();
      form.querySelector('#br-title').textContent = state.id ? 'Modifier la règle de boost' : 'Ajouter une règle de boost';
      f('active').checked = !!rule.active;
      f('name').value = rule.name || '';
      f('boost').value = rule.boost;
      f('terms_enabled').checked = state.terms.length > 0;
      termsBox.hidden = state.terms.length === 0;
      f('term').value = '';
      form.querySelector('input[name=br_match][value=' + (rule.match_all === false ? 'any' : 'all') + ']').checked = true;
      condsBox.textContent = '';
      renderTerms();
      (rule.conditions || []).forEach(addCondition);
      if (!rule.conditions || !rule.conditions.length) { addCondition(); }
      dlg.showModal();
      f('name').focus();
    }
    document.querySelectorAll('[data-br-open]').forEach(function (b) {
      b.addEventListener('click', function () {
        var data = b.getAttribute('data-rule');
        open(data ? JSON.parse(data) : null);
      });
    });

    form.addEventListener('submit', function (ev) {
      var pending = f('term').value.trim();
      if (f('terms_enabled').checked && pending) { addTerm(); }
      var conds = [];
      condsBox.querySelectorAll('.br-cond').forEach(function (r) { var c = r.getCondition && r.getCondition(); if (c) { conds.push(c); } });
      if (!conds.length) { ev.preventDefault(); window.DeepFindUI.alert('Ajoutez au moins une condition (champ + valeurs).'); return; }
      if (f('terms_enabled').checked && !state.terms.length) { ev.preventDefault(); window.DeepFindUI.alert('Ajoutez au moins un terme de recherche, ou désactivez « Termes de recherche ».'); return; }
      form.querySelector('input[name=payload]').value = JSON.stringify({
        id: state.id, name: f('name').value, active: f('active').checked, boost: f('boost').value,
        terms_enabled: f('terms_enabled').checked, terms: state.terms,
        match: form.querySelector('input[name=br_match]:checked').value, conditions: conds
      });
    });
  });
})();
