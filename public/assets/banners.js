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

    // ---------------------------------------------------------------- Images : zone cliquable, glisser-déposer, téléversement, aperçu
    var maxKb = parseInt(form.getAttribute('data-max-kb') || '500', 10);
    var TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    form.querySelectorAll('[data-slot]').forEach(function (slot) {
      var key = slot.getAttribute('data-slot');
      var input = f('image_' + key);
      var file = slot.querySelector('[data-file]');
      var drop = slot.querySelector('[data-drop]');
      var img = slot.querySelector('[data-preview]');
      var parts = { empty: slot.querySelector('[data-empty]'), busy: slot.querySelector('[data-busy]'), filled: slot.querySelector('[data-filled]') };
      var meta = slot.querySelector('[data-meta]');
      var err = slot.querySelector('[data-error]');
      var lastSize = 0;
      function view(name) { Object.keys(parts).forEach(function (k) { parts[k].hidden = k !== name; }); drop.classList.toggle('is-filled', name === 'filled'); }
      function error(msg) { err.textContent = msg || ''; err.hidden = !msg; }
      function refresh() {
        var u = input.value.trim();
        if (/^https?:\/\//i.test(u)) { meta.textContent = 'Chargement de l\'aperçu…'; img.src = u; view('filled'); } else { img.removeAttribute('src'); view('empty'); }
      }
      img.addEventListener('load', function () {
        meta.textContent = img.naturalWidth + ' × ' + img.naturalHeight + ' px' + (lastSize ? ' · ' + Math.round(lastSize / 1024) + ' Ko' : '');
      });
      img.addEventListener('error', function () { if (input.value.trim()) { meta.textContent = 'Image introuvable à cette adresse'; } });
      function upload(f0) {
        if (!f0) { return; }
        if (TYPES.indexOf(f0.type) === -1) { error('Format non accepté (' + f0.name + ') : JPG, PNG, GIF ou WebP.'); return; }
        if (f0.size > maxKb * 1024) { error('Image trop lourde : ' + Math.ceil(f0.size / 1024) + ' Ko pour ' + maxKb + ' Ko maximum. Compressez-la (ex. squoosh.app) puis réessayez.'); return; }
        error('');
        view('busy');
        var data = new FormData();
        data.append('_csrf', form.getAttribute('data-csrf'));
        data.append('image', f0);
        fetch(form.getAttribute('data-upload-url'), { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (r) { return r.json().catch(function () { return { error: 'réponse illisible du serveur' }; }); })
          .then(function (d) {
            if (d.url) { lastSize = f0.size; input.value = d.url; refresh(); } else { refresh(); error('Image non téléversée : ' + (d.error || 'erreur inconnue')); }
          })
          .catch(function () { refresh(); error('Téléversement impossible : vérifiez la connexion.'); });
      }
      function pick() { file.click(); }
      drop.addEventListener('click', function (ev) { if (!ev.target.closest('button') && parts.filled.hidden && parts.busy.hidden) { pick(); } });
      drop.addEventListener('keydown', function (ev) { if ((ev.key === 'Enter' || ev.key === ' ') && ev.target === drop) { ev.preventDefault(); pick(); } });
      slot.querySelector('[data-replace]').addEventListener('click', pick);
      slot.querySelector('[data-remove]').addEventListener('click', function () { input.value = ''; lastSize = 0; error(''); refresh(); drop.focus(); });
      file.addEventListener('change', function () { upload(file.files[0]); file.value = ''; });
      input.addEventListener('input', function () { lastSize = 0; error(''); refresh(); });
      ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (ev) { ev.preventDefault(); drop.classList.add('is-over'); }); });
      ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function (ev) { ev.preventDefault(); if (t === 'drop' || !drop.contains(ev.relatedTarget)) { drop.classList.remove('is-over'); } }); });
      drop.addEventListener('drop', function (ev) { upload(ev.dataTransfer && ev.dataTransfer.files[0]); });
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
