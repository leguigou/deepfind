/* Popins : page d'édition (pages et pages exclues, affichage, fréquence, contenu image ou HTML, période).
 * L'ordre de la liste se règle par glisser-déposer (page-rules.js, mêmes attributs data-pr-list / data-pr-order). */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-popin-form]');
    if (!form) { return; }
    var p = JSON.parse(form.getAttribute('data-popin') || '{}');
    var urlOps = JSON.parse(form.getAttribute('data-url-ops') || '{}');
    var ui = window.DeepFindUI;
    var f = function (name) { return form.querySelector('[data-f="' + name + '"]'); };
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
    var editor = window.DFEditor(form, { starts_at: p.starts_at, ends_at: p.ends_at });

    f('name').value = p.name || '';
    f('active').checked = p.active !== false;
    f('home').checked = !!p.home;
    f('device').value = p.device || 'all';
    f('placement').value = p.placement || 'center';
    f('delay_seconds').value = p.delay_seconds !== undefined ? p.delay_seconds : 3;
    f('max_width').value = p.max_width || 600;
    f('remember_days').value = p.remember_days !== undefined ? p.remember_days : 7;
    f('mode').value = p.mode === 'code' ? 'code' : 'visual';
    f('image_desktop').value = p.image_desktop || '';
    f('image_mobile').value = p.image_mobile || '';
    f('alt').value = p.alt || '';
    f('link_url').value = p.link_url || '';
    f('new_window').checked = !!p.new_window;
    f('html').value = p.html || '';

    // ---------------------------------------------------------------- Pages : toutes / au choix, adresses et pages exclues
    var custom = form.querySelector('[data-pages-custom]');
    function syncPages() { custom.hidden = form.querySelector('input[name=pp_pages]:checked').value !== 'custom'; }
    form.querySelector('input[name=pp_pages][value=' + (p.pages === 'custom' ? 'custom' : 'all') + ']').checked = true;
    form.querySelectorAll('input[name=pp_pages]').forEach(function (r) { r.addEventListener('change', syncPages); });
    syncPages();

    function addUrl(box, u, keepOne) {
      u = u || { match: 'contains', text: '' };
      var row = el('div', 'pr-url');
      var op = el('select', 'pr-url-op');
      op.setAttribute('aria-label', 'Comparaison de l\'adresse');
      Object.keys(urlOps).forEach(function (k) { var o = el('option', '', urlOps[k]); o.value = k; op.appendChild(o); });
      op.value = urlOps[u.match] ? u.match : 'contains';
      var txt = el('input', 'pr-url-text');
      txt.type = 'text';
      txt.maxLength = 300;
      txt.placeholder = box.getAttribute('data-urls') === 'exclude_urls' ? '/checkout' : '/nautisme/';
      txt.value = u.text || '';
      txt.setAttribute('aria-label', box.getAttribute('data-urls') === 'exclude_urls' ? 'Adresse de la page exclue' : 'Adresse de la page');
      var rm = el('button', 'icon-btn', '✕');
      rm.type = 'button';
      rm.setAttribute('aria-label', 'Retirer cette adresse');
      rm.addEventListener('click', function () { row.remove(); if (keepOne && !box.children.length) { addUrl(box, null, keepOne); } });
      row.appendChild(op);
      row.appendChild(txt);
      row.appendChild(rm);
      box.appendChild(row);
      return txt;
    }
    var boxes = {};
    form.querySelectorAll('[data-urls]').forEach(function (box) {
      var key = box.getAttribute('data-urls');
      var keepOne = key === 'urls'; // pages choisies : toujours une ligne à remplir ; pages exclues : liste vide possible
      boxes[key] = box;
      var list = p[key] && p[key].length ? p[key] : (keepOne ? [null] : []);
      list.forEach(function (u) { addUrl(box, u, keepOne); });
      form.querySelector('[data-add-url="' + key + '"]').addEventListener('click', function () { addUrl(box, null, keepOne).focus(); });
    });
    function urlsOf(key) {
      var out = [];
      boxes[key].querySelectorAll('.pr-url').forEach(function (r) {
        var text = r.querySelector('.pr-url-text').value.trim();
        if (text) { out.push({ match: r.querySelector('.pr-url-op').value, text: text }); }
      });
      return out;
    }

    form.querySelector('input[name=pp_repeat][value=' + (p.repeat_mode === 'page' ? 'page' : 'visit') + ']').checked = true;

    // ---------------------------------------------------------------- Contenu : image(s) + lien, ou HTML
    function showMode() {
      form.querySelectorAll('[data-mode-panel]').forEach(function (panel) { panel.hidden = panel.getAttribute('data-mode-panel') !== f('mode').value; });
    }
    f('mode').addEventListener('change', showMode);
    showMode();
    window.DFImageSlots(form);
    form.querySelector('[data-copy]').addEventListener('click', function () {
      var t = f('html');
      if (navigator.clipboard) { navigator.clipboard.writeText(t.value); } else { t.select(); document.execCommand('copy'); }
    });

    // ---------------------------------------------------------------- Enregistrement
    form.addEventListener('submit', function (ev) {
      var period = editor.period();
      var pages = form.querySelector('input[name=pp_pages]:checked').value;
      var urls = urlsOf('urls');
      var problem = '';
      if (period.on && (!period.start || !period.end)) { problem = 'Choisissez la date de début et la date de fin de la période.'; }
      else if (pages === 'custom' && !f('home').checked && !urls.length) { problem = 'Choisissez les pages : la page d\'accueil et/ou au moins une adresse (ex. contient « /nautisme/ »).'; }
      else if (f('mode').value === 'visual' && !/^https?:\/\//i.test(f('image_desktop').value.trim())) { problem = 'Ajoutez au moins l\'image « Ordinateur ».'; }
      else if (f('mode').value === 'code' && !f('html').value.trim()) { problem = 'Saisissez le code HTML de la popin.'; }
      if (problem) { ev.preventDefault(); ui.alert(problem); return; }
      var reset = f('reset_memory');
      form.querySelector('input[name=payload]').value = JSON.stringify({
        id: p.id || 0, name: f('name').value, active: f('active').checked,
        duration: period.on ? 'period' : 'always', starts_at: period.start, ends_at: period.end,
        pages: pages, home: f('home').checked, urls: urls, exclude_urls: urlsOf('exclude_urls'),
        device: f('device').value, placement: f('placement').value, delay_seconds: f('delay_seconds').value, max_width: f('max_width').value,
        repeat_mode: form.querySelector('input[name=pp_repeat]:checked').value, remember_days: f('remember_days').value,
        reset_memory: !!(reset && reset.checked),
        mode: f('mode').value, image_desktop: f('image_desktop').value, image_mobile: f('image_mobile').value,
        alt: f('alt').value, link_url: f('link_url').value, new_window: f('new_window').checked, html: f('html').value
      });
    });
  });
})();
