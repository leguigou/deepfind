/* Bannières : page d'édition (images ordinateur / mobile avec téléversement, ou code HTML ; termes ; période). */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-banner-form]');
    if (!form) { return; }
    var b = JSON.parse(form.getAttribute('data-banner') || '{}');
    var ui = window.DeepFindUI;
    var f = function (name) { return form.querySelector('[data-f="' + name + '"]'); };
    var editor = window.DFEditor(form, { terms: b.terms, starts_at: b.starts_at, ends_at: b.ends_at });

    f('name').value = b.name || '';
    f('active').checked = b.active !== false;
    f('is_default').checked = !!b.is_default;
    f('mode').value = b.mode === 'code' ? 'code' : 'visual';
    f('image_desktop').value = b.image_desktop || '';
    f('image_mobile').value = b.image_mobile || '';
    f('alt').value = b.alt || '';
    f('link_url').value = b.link_url || '';
    f('new_window').checked = !!b.new_window;
    f('html').value = b.html || '';

    function showMode() {
      form.querySelectorAll('[data-mode-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-mode-panel') !== f('mode').value; });
    }
    f('mode').addEventListener('change', showMode);
    showMode();

    // ---------------------------------------------------------------- Images : aperçu, téléversement, glisser-déposer
    form.querySelectorAll('[data-slot]').forEach(function (slot) {
      var key = slot.getAttribute('data-slot');
      var input = f('image_' + key);
      var img = slot.querySelector('[data-preview]');
      var empty = slot.querySelector('[data-empty]');
      var remove = slot.querySelector('[data-remove]');
      var drop = slot.querySelector('[data-drop]');
      function refresh() {
        var u = input.value.trim();
        var ok = /^https?:\/\//i.test(u);
        img.hidden = !ok;
        remove.hidden = !ok;
        empty.hidden = ok;
        if (ok) { img.src = u; } else { img.removeAttribute('src'); }
      }
      function upload(file) {
        if (!file) { return; }
        var data = new FormData();
        data.append('_csrf', form.getAttribute('data-csrf'));
        data.append('image', file);
        drop.classList.add('is-busy');
        fetch(form.getAttribute('data-upload-url'), { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (r) { return r.json().catch(function () { return { error: 'Réponse illisible du serveur.' }; }); })
          .then(function (d) {
            drop.classList.remove('is-busy');
            if (d.url) { input.value = d.url; refresh(); } else { ui.alert('Image non téléversée : ' + (d.error || 'erreur inconnue') + '.'); }
          })
          .catch(function () { drop.classList.remove('is-busy'); ui.alert('Téléversement impossible (réseau).'); });
      }
      slot.querySelector('[data-file]').addEventListener('change', function (ev) { upload(ev.target.files[0]); ev.target.value = ''; });
      input.addEventListener('input', refresh);
      remove.addEventListener('click', function () { input.value = ''; refresh(); });
      ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (ev) { ev.preventDefault(); drop.classList.add('is-over'); }); });
      ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function (ev) { ev.preventDefault(); drop.classList.remove('is-over'); }); });
      drop.addEventListener('drop', function (ev) { upload(ev.dataTransfer && ev.dataTransfer.files[0]); });
      img.addEventListener('error', function () { if (input.value) { img.hidden = true; empty.hidden = false; remove.hidden = false; } });
      refresh();
    });

    form.querySelector('[data-copy]').addEventListener('click', function () {
      var t = f('html');
      if (navigator.clipboard) { navigator.clipboard.writeText(t.value); } else { t.select(); document.execCommand('copy'); }
    });

    // ---------------------------------------------------------------- Enregistrement
    form.addEventListener('submit', function (ev) {
      editor.addPending();
      var period = editor.period();
      var problem = '';
      if (!editor.terms.length && !f('is_default').checked) { problem = 'Ajoutez au moins un terme de recherche, ou faites-en la bannière par défaut.'; }
      else if (period.on && (!period.start || !period.end)) { problem = 'Choisissez la date de début et la date de fin de la période.'; }
      else if (f('mode').value === 'visual' && !/^https?:\/\//i.test(f('image_desktop').value.trim())) { problem = 'Ajoutez au moins l\'image « Ordinateur ».'; }
      else if (f('mode').value === 'code' && !f('html').value.trim()) { problem = 'Saisissez le code HTML de la bannière.'; }
      if (problem) { ev.preventDefault(); ui.alert(problem); return; }
      form.querySelector('input[name=payload]').value = JSON.stringify({
        id: b.id || 0, name: f('name').value, active: f('active').checked, is_default: f('is_default').checked,
        duration: period.on ? 'period' : 'always', starts_at: period.start, ends_at: period.end, terms: editor.terms,
        mode: f('mode').value, image_desktop: f('image_desktop').value, image_mobile: f('image_mobile').value,
        alt: f('alt').value, link_url: f('link_url').value, new_window: f('new_window').checked, html: f('html').value
      });
    });
  });
})();
