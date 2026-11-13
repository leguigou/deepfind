/* Redirections : page d'édition (statut, automatique, URL, termes, période). */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-redirect-form]');
    if (!form) { return; }
    var r = JSON.parse(form.getAttribute('data-redirect') || '{}');
    var f = function (name) { return form.querySelector('[data-f="' + name + '"]'); };
    var editor = window.DFEditor(form, { terms: r.terms, starts_at: r.starts_at, ends_at: r.ends_at });
    f('name').value = r.name || '';
    f('active').checked = r.active !== false;
    f('auto').checked = r.auto !== false;
    f('url').value = r.url || '';
    form.addEventListener('submit', function (ev) {
      editor.addPending();
      var period = editor.period();
      var problem = '';
      if (!/^https?:\/\/\S+$/i.test(f('url').value.trim())) { problem = 'URL de destination complète attendue (https://…).'; }
      else if (!editor.terms.length) { problem = 'Ajoutez au moins un terme de recherche.'; }
      else if (period.on && (!period.start || !period.end)) { problem = 'Choisissez la date de début et la date de fin de la période.'; }
      if (problem) { ev.preventDefault(); window.DeepFindUI.alert(problem); return; }
      form.querySelector('input[name=payload]').value = JSON.stringify({
        id: r.id || 0, name: f('name').value, active: f('active').checked, auto: f('auto').checked, url: f('url').value.trim(),
        terms: editor.terms, duration: period.on ? 'period' : 'always', starts_at: period.start, ends_at: period.end
      });
    });
  });
})();
