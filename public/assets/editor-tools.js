/* Outils des pages d'édition (résultats personnalisés, bannières, redirections, popins) :
 * DFEditor(root, {terms, starts_at, ends_at}) → { terms: [...], period(): {on, start, end} }.
 * root contient les blocs du gabarit _terms_period.php (le bloc des termes est facultatif). Données affichées avec textContent uniquement.
 * DFImageSlots(form) : zones d'image [data-slot] (téléversement, glisser-déposer, aperçu) des bannières et des popins.
 * DFWysiwyg(form) : éditeur visuel du contenu HTML [data-wy], relié au champ [data-f="html"] (onglets éditeur / code).
 * DFPages(form, data, urlOps) : pages concernées (toutes / au choix) et adresses (gabarit _pages_fields.php) des popins et carrousels. */
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
    if (!termsList) { return; }
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
  if (termsList) {
    root.querySelector('[data-add-term]').addEventListener('click', addTerm);
    f('term').addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); addTerm(); } });
    renderTerms();
  }

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
      addPending: function () { if (termsList && f('term').value.trim()) { addTerm(); } },
      period: function () { return { on: periodOn(), start: state.start, end: state.end }; }
    };
  };

  // ---------------------------------------------------------------- Images : zone cliquable, glisser-déposer, téléversement, aperçu
  // form : data-max-kb, data-upload-url, data-csrf ; chaque [data-slot="k"] est relié au champ [data-f="image_k"].
  window.DFImageSlots = function (form) {
    var maxKb = parseInt(form.getAttribute('data-max-kb') || '500', 10);
    var TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    form.querySelectorAll('[data-slot]').forEach(function (slot) {
      var key = slot.getAttribute('data-slot');
      var input = form.querySelector('[data-f="image_' + key + '"]');
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
  };

  // ---------------------------------------------------------------- Éditeur visuel du contenu HTML (bannières, popins)
  /*
   * Zone modifiable (contenteditable) + barre d'outils ; le champ [data-f="html"] est mis à jour à chaque modification,
   * l'onglet « Code HTML » montre et modifie ce même champ. Le HTML chargé ou collé est refiltré ici (balises et attributs
   * autorisés, comme HtmlSanitizer côté serveur, qui reste la référence à l'enregistrement).
   */
  var WY_TAGS = { A: 1, IMG: 1, PICTURE: 1, SOURCE: 1, DIV: 1, SPAN: 1, P: 1, STRONG: 1, B: 1, EM: 1, I: 1, U: 1, BR: 1, H2: 1, H3: 1, H4: 1, UL: 1, OL: 1, LI: 1, SMALL: 1 };
  var WY_ATTRS = { href: 1, target: 1, rel: 1, src: 1, srcset: 1, sizes: 1, media: 1, alt: 1, title: 1, width: 1, height: 1, class: 1, style: 1, loading: 1 };
  var WY_PASTE_ATTRS = { href: 1, target: 1, src: 1, alt: 1 }; // texte collé (Word, page web…) : mise en forme d'origine retirée
  function wyClean(src, attrs) {
    if (src.nodeType === 3) { return document.createTextNode(src.nodeValue); }
    var frag = document.createDocumentFragment();
    if (src.nodeType !== 1 || !WY_TAGS[src.tagName]) {
      if (src.nodeType === 1 && !/^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|FORM|SVG|MATH|TEMPLATE|NOSCRIPT|LINK|META|BASE|TITLE|HEAD)$/.test(src.tagName)) {
        Array.prototype.forEach.call(src.childNodes, function (c) { frag.appendChild(wyClean(c, attrs)); });
        // titre trop grand (h1) ou bloc inconnu : son texte, puis un retour à la ligne pour garder les paragraphes
        if (/^(H1|H5|H6|TR|BLOCKQUOTE|ARTICLE|SECTION|HEADER|FOOTER|TABLE)$/.test(src.tagName)) { frag.appendChild(document.createElement('br')); }
      }
      return frag;
    }
    var n = document.createElement(src.tagName.toLowerCase());
    Array.prototype.forEach.call(src.attributes, function (a) {
      var name = a.name.toLowerCase();
      var v = a.value.trim();
      if (!attrs[name]) { return; }
      if ((name === 'href' || name === 'src') && v !== '' && !/^(https?:\/\/|\/(?!\/)|#)/i.test(v)) { return; }
      if ((name === 'srcset' || name === 'style') && /javascript:|data:|url\s*\(|expression|@import|behavior/i.test(v)) { return; }
      if (name === 'target' && v !== '_blank' && v !== '_self') { return; }
      n.setAttribute(name, v);
    });
    if (n.tagName === 'A' && n.getAttribute('target') === '_blank') { n.setAttribute('rel', 'noopener'); }
    Array.prototype.forEach.call(src.childNodes, function (c) { n.appendChild(wyClean(c, attrs)); });
    return n;
  }
  function wyFragment(html, attrs) {
    var doc = new DOMParser().parseFromString('<body>' + String(html || '') + '</body>', 'text/html');
    var frag = document.createDocumentFragment();
    Array.prototype.forEach.call(doc.body.childNodes, function (c) { frag.appendChild(wyClean(c, attrs || WY_ATTRS)); });
    return frag;
  }

  window.DFWysiwyg = function (form) {
    var host = form.querySelector('[data-wy]');
    var code = form.querySelector('[data-f="html"]');
    if (!host || !code) { return null; }
    var SVG = 'http://www.w3.org/2000/svg';
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
    // petites icônes (traits) pour l'alignement et les listes
    function icon(lines) {
      var svg = document.createElementNS(SVG, 'svg');
      svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('width', '18'); svg.setAttribute('height', '18'); svg.setAttribute('aria-hidden', 'true');
      svg.setAttribute('fill', 'none'); svg.setAttribute('stroke', 'currentColor'); svg.setAttribute('stroke-width', '2'); svg.setAttribute('stroke-linecap', 'round');
      lines.forEach(function (d) { var p = document.createElementNS(SVG, 'path'); p.setAttribute('d', d); svg.appendChild(p); });
      return svg;
    }
    var bar = el('div', 'wy-bar');
    bar.setAttribute('role', 'toolbar');
    bar.setAttribute('aria-label', 'Mise en forme du contenu');
    var area = el('div', 'wy-area');
    area.contentEditable = 'true';
    area.setAttribute('role', 'textbox');
    area.setAttribute('aria-multiline', 'true');
    area.setAttribute('aria-label', 'Contenu (éditeur visuel)');
    area.setAttribute('data-placeholder', 'Écrivez le contenu : titre, texte, lien, bouton, image…');
    var pop = el('div', 'wy-pop');
    pop.hidden = true;
    host.appendChild(bar);
    host.appendChild(pop);
    host.appendChild(area);

    var saved = null;
    function saveRange() {
      var sel = window.getSelection();
      if (sel.rangeCount && area.contains(sel.getRangeAt(0).commonAncestorContainer)) { saved = sel.getRangeAt(0).cloneRange(); }
    }
    function restoreRange() {
      area.focus();
      if (saved) { var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(saved); }
    }
    function isEmpty() { return !area.textContent.trim() && !area.querySelector('img'); }
    function sync() {
      // paragraphes vides laissés en fin de texte (après un bouton, une touche Entrée) : retirés
      code.value = isEmpty() ? '' : area.innerHTML.trim().replace(/(\s*<p>(<br>|&nbsp;|\s)*<\/p>)+$/i, '');
      area.classList.toggle('is-empty', isEmpty());
    }
    function exec(cmd, value, css) {
      restoreRange();
      try { document.execCommand('styleWithCSS', false, !!css); } catch (e) { /* navigateur ancien */ }
      document.execCommand(cmd, false, value === undefined ? null : value);
      saveRange();
      sync();
      refreshState();
    }
    function load() {
      area.textContent = '';
      area.appendChild(wyFragment(code.value));
      area.classList.toggle('is-empty', isEmpty());
    }

    // ------------------------------------------------ Barre d'outils
    var stateButtons = [];
    function button(label, content, fn, stateCmd) {
      var b = el('button', 'wy-btn');
      b.type = 'button';
      b.title = label;
      b.setAttribute('aria-label', label);
      if (typeof content === 'string') { b.textContent = content; } else { b.appendChild(content); }
      // mousedown : garder la sélection dans la zone de texte
      b.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
      b.addEventListener('click', fn);
      if (stateCmd) { b.setAttribute('aria-pressed', 'false'); stateButtons.push([b, stateCmd]); }
      bar.appendChild(b);
      return b;
    }
    function sep() { bar.appendChild(el('span', 'wy-sep')); }
    var block = el('select', 'wy-block');
    block.setAttribute('aria-label', 'Type de texte');
    [['p', 'Paragraphe'], ['h2', 'Titre'], ['h3', 'Sous-titre']].forEach(function (o) { var opt = el('option', '', o[1]); opt.value = o[0]; block.appendChild(opt); });
    block.addEventListener('change', function () { exec('formatBlock', '<' + block.value + '>'); });
    bar.appendChild(block);
    sep();
    var bBold = button('Gras', 'G', function () { exec('bold'); }, 'bold');
    bBold.classList.add('wy-b');
    button('Italique', 'I', function () { exec('italic'); }, 'italic').classList.add('wy-i');
    button('Souligné', 'S', function () { exec('underline'); }, 'underline').classList.add('wy-u');
    var colorWrap = el('label', 'wy-color');
    colorWrap.title = 'Couleur du texte';
    var colorSwatch = el('span', 'wy-color-a', 'A');
    var color = el('input');
    color.type = 'color';
    color.value = '#e4003a';
    color.setAttribute('aria-label', 'Couleur du texte');
    colorSwatch.style.borderBottomColor = color.value;
    colorWrap.addEventListener('mousedown', saveRange);
    color.addEventListener('input', function () { colorSwatch.style.borderBottomColor = color.value; });
    color.addEventListener('change', function () { exec('foreColor', color.value, true); });
    colorWrap.appendChild(colorSwatch);
    colorWrap.appendChild(color);
    bar.appendChild(colorWrap);
    sep();
    button('Aligner à gauche', icon(['M4 6h16', 'M4 12h10', 'M4 18h14']), function () { exec('justifyLeft', undefined, true); });
    button('Centrer', icon(['M4 6h16', 'M7 12h10', 'M5 18h14']), function () { exec('justifyCenter', undefined, true); });
    button('Aligner à droite', icon(['M4 6h16', 'M10 12h10', 'M6 18h14']), function () { exec('justifyRight', undefined, true); });
    sep();
    button('Liste à puces', icon(['M9 6h11', 'M9 12h11', 'M9 18h11', 'M4.5 6h.01', 'M4.5 12h.01', 'M4.5 18h.01']), function () { exec('insertUnorderedList'); }, 'insertUnorderedList');
    button('Liste numérotée', icon(['M10 6h10', 'M10 12h10', 'M10 18h10', 'M4 5l1.5 -1v5', 'M4 15h2.5l-2.5 3h2.5']), function () { exec('insertOrderedList'); }, 'insertOrderedList');
    sep();
    button('Lien', 'Lien', function () { openPop('link'); });
    button('Bouton (lien mis en avant)', 'Bouton', function () { openPop('button'); });
    button('Image', 'Image', function () { openPop('image'); });
    sep();
    button('Effacer la mise en forme', 'Effacer', function () { exec('removeFormat'); exec('formatBlock', '<p>'); });
    button('Annuler', '↶', function () { exec('undo'); });
    button('Rétablir', '↷', function () { exec('redo'); });

    function currentLink() {
      var sel = window.getSelection();
      var n = sel.rangeCount ? sel.getRangeAt(0).commonAncestorContainer : null;
      while (n && n !== area) { if (n.nodeType === 1 && n.tagName === 'A') { return n; } n = n.parentNode; }
      return null;
    }
    function refreshState() {
      stateButtons.forEach(function (s) {
        var on = false;
        try { on = document.queryCommandState(s[1]); } catch (e) { /* ignoré */ }
        s[0].setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      var sel = window.getSelection();
      var n = sel.rangeCount ? sel.getRangeAt(0).startContainer : null;
      while (n && n !== area && !(n.nodeType === 1 && /^(P|H2|H3|DIV|LI)$/.test(n.tagName))) { n = n.parentNode; }
      block.value = n && n !== area && (n.tagName === 'H2' || n.tagName === 'H3') ? n.tagName.toLowerCase() : 'p';
    }

    // ------------------------------------------------ Lien, bouton, image : petit formulaire sous la barre
    var popMode = '';
    function field(label, input) { var l = el('label', 'wy-field'); l.appendChild(el('span', '', label)); l.appendChild(input); return l; }
    var pUrl = el('input'); pUrl.type = 'url'; pUrl.placeholder = 'https://www.example.com/…';
    var pText = el('input'); pText.type = 'text'; pText.maxLength = 80;
    var pBlank = el('input'); pBlank.type = 'checkbox';
    var blankLabel = el('label', 'wy-check'); blankLabel.appendChild(pBlank); blankLabel.appendChild(el('span', '', 'Ouvrir dans un nouvel onglet'));
    var pFile = el('input'); pFile.type = 'file'; pFile.accept = 'image/jpeg,image/png,image/gif,image/webp'; pFile.className = 'vh'; pFile.tabIndex = -1;
    var pUpload = el('button', 'btn btn-sm btn-light', 'Téléverser une image…'); pUpload.type = 'button';
    var pMsg = el('span', 'wy-msg muted');
    var pOk = el('button', 'btn btn-sm', 'Valider'); pOk.type = 'button';
    var pCancel = el('button', 'btn btn-sm btn-light', 'Annuler'); pCancel.type = 'button';
    var urlField = field('Adresse', pUrl);
    var textField = field('Texte', pText);
    var actions = el('div', 'wy-pop-actions');
    [pUpload, pFile, pMsg, pCancel, pOk].forEach(function (n) { actions.appendChild(n); });
    [urlField, textField, blankLabel, actions].forEach(function (n) { pop.appendChild(n); });

    function openPop(mode) {
      saveRange();
      popMode = mode;
      var link = mode === 'link' ? currentLink() : null;
      var selected = saved ? saved.toString() : '';
      pUrl.value = link ? link.getAttribute('href') || '' : '';
      pUrl.placeholder = mode === 'image' ? 'Adresse de l\'image (https://…) ou téléversez-la' : 'https://www.example.com/…';
      pBlank.checked = !!(link && link.target === '_blank');
      pText.value = mode === 'button' ? (selected || 'Je découvre') : (mode === 'image' ? '' : selected);
      textField.firstChild.textContent = mode === 'image' ? 'Texte alternatif' : (mode === 'button' ? 'Texte du bouton' : 'Texte (si rien n\'est sélectionné)');
      textField.hidden = mode === 'link' && (!!link || selected !== '');
      blankLabel.hidden = mode === 'image';
      pUpload.hidden = mode !== 'image';
      pMsg.textContent = mode === 'link' && link ? 'Adresse vide = retirer le lien.' : '';
      pop.hidden = false;
      pUrl.focus();
    }
    function closePop() { pop.hidden = true; popMode = ''; restoreRange(); }
    function esc(v) { return String(v).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); }
    function validUrl(u) { return /^(https?:\/\/|\/(?!\/)|#)/i.test(u); }
    function applyPop() {
      var url = pUrl.value.trim();
      var text = pText.value.trim();
      var target = pBlank.checked ? ' target="_blank" rel="noopener"' : '';
      if (url && !validUrl(url)) { pMsg.textContent = 'Adresse complète attendue (https://…) ou chemin du site (/…).'; pUrl.focus(); return; }
      if (popMode === 'link') {
        var link = (function () { restoreRange(); return currentLink(); })();
        if (link) {
          if (!url) { var r = document.createRange(); r.selectNodeContents(link); var s0 = window.getSelection(); s0.removeAllRanges(); s0.addRange(r); exec('unlink'); }
          else { link.setAttribute('href', url); if (pBlank.checked) { link.target = '_blank'; link.rel = 'noopener'; } else { link.removeAttribute('target'); link.removeAttribute('rel'); } sync(); }
        } else if (url) {
          var sel = window.getSelection();
          if (sel.rangeCount && !sel.getRangeAt(0).collapsed) {
            exec('createLink', url);
            var made = currentLink();
            if (made && pBlank.checked) { made.target = '_blank'; made.rel = 'noopener'; sync(); }
          } else {
            exec('insertHTML', '<a href="' + esc(url) + '"' + target + '>' + esc(text || url) + '</a>&nbsp;');
          }
        }
      } else if (popMode === 'button') {
        if (!url) { pMsg.textContent = 'Indiquez l\'adresse de la page.'; pUrl.focus(); return; }
        var bg = /^#[0-9a-f]{6}$/i.test(color.value) ? color.value : '#e4003a';
        exec('insertHTML', '<p style="text-align: center;"><a class="df-btn" href="' + esc(url) + '"' + target + ' style="display: inline-block; padding: 12px 22px; border-radius: 8px; background: ' + bg
          + '; color: #ffffff; font-weight: 700; text-decoration: none;">' + esc(text || 'Je découvre') + '</a></p><p><br></p>');
        // Chrome retire « text-decoration: none » à l'insertion : remis sur les boutons
        area.querySelectorAll('a.df-btn').forEach(function (a) { a.style.textDecoration = 'none'; });
        sync();
      } else if (popMode === 'image') {
        if (!url) { pMsg.textContent = 'Indiquez l\'adresse de l\'image ou téléversez-la.'; pUrl.focus(); return; }
        exec('insertHTML', '<img src="' + esc(url) + '" alt="' + esc(text) + '" style="max-width: 100%; height: auto;">');
      }
      pop.hidden = true;
      popMode = '';
    }
    pOk.addEventListener('click', applyPop);
    pCancel.addEventListener('click', closePop);
    pop.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && ev.target.tagName === 'INPUT' && ev.target.type !== 'checkbox') { ev.preventDefault(); applyPop(); }
      if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); closePop(); }
    });
    // téléversement : même adresse et mêmes limites que les zones d'image de la page
    pUpload.addEventListener('click', function () { pFile.click(); });
    pFile.addEventListener('change', function () {
      var f0 = pFile.files[0];
      pFile.value = '';
      if (!f0) { return; }
      var maxKb = parseInt(form.getAttribute('data-max-kb') || '500', 10);
      if (f0.size > maxKb * 1024) { pMsg.textContent = 'Image trop lourde : ' + Math.ceil(f0.size / 1024) + ' Ko pour ' + maxKb + ' Ko maximum.'; return; }
      pMsg.textContent = 'Téléversement…';
      var data = new FormData();
      data.append('_csrf', form.getAttribute('data-csrf'));
      data.append('image', f0);
      fetch(form.getAttribute('data-upload-url'), { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json().catch(function () { return { error: 'réponse illisible du serveur' }; }); })
        .then(function (d) { if (d.url) { pUrl.value = d.url; pMsg.textContent = 'Image téléversée : cliquez sur Valider.'; } else { pMsg.textContent = 'Image non téléversée : ' + (d.error || 'erreur inconnue'); } })
        .catch(function () { pMsg.textContent = 'Téléversement impossible : vérifiez la connexion.'; });
    });

    // ------------------------------------------------ Saisie, collage, onglets
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* ignoré */ }
    area.addEventListener('input', function () { saveRange(); sync(); });
    area.addEventListener('keyup', function () { saveRange(); refreshState(); });
    area.addEventListener('mouseup', function () { saveRange(); refreshState(); });
    area.addEventListener('focus', function () { if (!area.firstChild) { area.appendChild(el('p')).appendChild(el('br')); } });
    area.addEventListener('paste', function (ev) {
      var dt = ev.clipboardData;
      if (!dt) { return; }
      ev.preventDefault();
      var html = dt.getData('text/html');
      if (html) {
        var box = el('div');
        box.appendChild(wyFragment(html.replace(/^[\s\S]*<body[^>]*>|<\/body>[\s\S]*$/gi, ''), WY_PASTE_ATTRS));
        document.execCommand('insertHTML', false, box.innerHTML);
      } else {
        document.execCommand('insertText', false, dt.getData('text/plain'));
      }
      sync();
    });
    // un lien cliqué dans l'éditeur ne quitte pas la page
    area.addEventListener('click', function (ev) { if (ev.target.closest && ev.target.closest('a')) { ev.preventDefault(); } });

    var tabs = form.querySelectorAll('[data-wy-tab]');
    function showTab(name) {
      tabs.forEach(function (t) { t.setAttribute('aria-selected', t.getAttribute('data-wy-tab') === name ? 'true' : 'false'); });
      form.querySelectorAll('[data-wy-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-wy-panel') !== name; });
      if (name === 'visual') { load(); }
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { showTab(t.getAttribute('data-wy-tab')); }); });
    showTab('visual');
    return {
      // largeur de l'aperçu (popins : largeur maximale choisie)
      setWidth: function (px) { area.style.maxWidth = px > 0 ? px + 'px' : ''; },
      // hauteur minimale de la popin (0 : celle de l'éditeur par défaut)
      setMinHeight: function (px) { area.style.minHeight = px > 0 ? px + 'px' : ''; },
      reload: load
    };
  };

  // ---------------------------------------------------------------- Pages concernées et pages exclues (popins, carrousels)
  window.DFPages = function (form, data, urlOps) {
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
    var custom = form.querySelector('[data-pages-custom]');
    var radios = form.querySelectorAll('input[name=pp_pages]');
    function mode() { var r = form.querySelector('input[name=pp_pages]:checked'); return r ? r.value : 'all'; }
    function syncPages() { custom.hidden = mode() !== 'custom'; }
    form.querySelector('input[name=pp_pages][value=' + (data.pages === 'custom' ? 'custom' : 'all') + ']').checked = true;
    radios.forEach(function (r) { r.addEventListener('change', syncPages); });
    syncPages();
    var home = form.querySelector('[data-f="home"]');
    if (home) { home.checked = !!data.home; }
    function addUrl(box, u, keepOne) {
      u = u || { match: 'contains', text: '' };
      var excl = box.getAttribute('data-urls') === 'exclude_urls';
      var row = el('div', 'pr-url');
      var op = el('select', 'pr-url-op');
      op.setAttribute('aria-label', 'Comparaison de l\'adresse');
      Object.keys(urlOps).forEach(function (k) { var o = el('option', '', urlOps[k]); o.value = k; op.appendChild(o); });
      op.value = urlOps[u.match] ? u.match : 'contains';
      var txt = el('input', 'pr-url-text');
      txt.type = 'text';
      txt.maxLength = 300;
      txt.placeholder = excl ? '/checkout' : '/nautisme/';
      txt.value = u.text || '';
      txt.setAttribute('aria-label', excl ? 'Adresse de la page exclue' : 'Adresse de la page');
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
      var list = data[key] && data[key].length ? data[key] : (keepOne ? [null] : []);
      list.forEach(function (u) { addUrl(box, u, keepOne); });
      form.querySelector('[data-add-url="' + key + '"]').addEventListener('click', function () { addUrl(box, null, keepOne).focus(); });
    });
    return {
      mode: mode,
      urls: function (key) {
        var out = [];
        boxes[key].querySelectorAll('.pr-url').forEach(function (r) {
          var text = r.querySelector('.pr-url-text').value.trim();
          if (text) { out.push({ match: r.querySelector('.pr-url-op').value, text: text }); }
        });
        return out;
      }
    };
  };
})();
