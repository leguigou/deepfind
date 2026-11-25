/* Carrousels de produits : fiche d'édition (pages, emplacement, source des produits, produits épinglés par glisser-déposer,
 * conditions, tri, affichage, aperçu). L'ordre de la liste se règle par glisser-déposer (page-rules.js). */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-carousel-form]');
    if (!form) { return; }
    var c = JSON.parse(form.getAttribute('data-carousel') || '{}');
    var fields = JSON.parse(form.getAttribute('data-fields') || '[]');
    var urlOps = JSON.parse(form.getAttribute('data-url-ops') || '{}');
    var ui = window.DeepFindUI;
    var f = function (name) { return form.querySelector('[data-f="' + name + '"]'); };
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined && text !== null) { n.textContent = String(text); } return n; }
    var byCode = {};
    fields.forEach(function (fd) { byCode[fd.code] = fd; });
    var editor = window.DFEditor(form, { starts_at: c.starts_at, ends_at: c.ends_at });
    var pagesUi = window.DFPages(form, c, urlOps);

    ['name', 'selector', 'title', 'title_class', 'more_url', 'more_label', 'custom_css'].forEach(function (k) { f(k).value = c[k] || ''; });
    ['insert_mode', 'device', 'sort_by', 'similar_by'].forEach(function (k) { if (c[k]) { f(k).value = c[k]; } });
    ['max_items', 'per_view_desktop', 'per_view_mobile', 'autoplay'].forEach(function (k) { f(k).value = c[k] !== undefined ? c[k] : ''; });
    f('active').checked = c.active !== false;
    f('learning').checked = !!c.learning;
    f('in_stock_only').checked = c.in_stock_only !== false;
    form.querySelector('input[name=ca_personal][value=' + (c.personal_mode === 'recent' ? 'recent' : 'recommended') + ']').checked = true;

    // ---------------------------------------------------------------- Modèle de carte produit : ordinateur / mobile, retour au modèle par défaut
    var tplBox = form.querySelector('[data-card-tpl]');
    var tplDefault = { desktop: tplBox.getAttribute('data-default-desktop') || '', mobile: tplBox.getAttribute('data-default-mobile') || '' };
    // mobile identique à l'ordinateur : laissé vide (il suit l'ordinateur)
    var mobileDefault = function () { return tplDefault.mobile !== tplDefault.desktop ? tplDefault.mobile : ''; };
    f('card_template').value = c.card_template || tplDefault.desktop;
    f('card_link').value = c.card_link || '';
    f('card_template_mobile').value = c.card_template_mobile || (c.card_template ? '' : mobileDefault());
    var tplTab = 'desktop';
    tplBox.querySelectorAll('[data-tpl-tab]').forEach(function (b) {
      b.addEventListener('click', function () {
        tplTab = b.getAttribute('data-tpl-tab');
        tplBox.querySelectorAll('[data-tpl-tab]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
        tplBox.querySelectorAll('[data-tpl-device]').forEach(function (t) { t.hidden = t.getAttribute('data-tpl-device') !== tplTab; });
      });
    });
    tplBox.querySelector('[data-tpl-reset]').addEventListener('click', function () {
      var t = f(tplTab === 'mobile' ? 'card_template_mobile' : 'card_template');
      t.value = tplTab === 'mobile' ? mobileDefault() : tplDefault.desktop;
      t.focus();
      if (tplTab === 'desktop') { f('card_link').value = ''; } // lien : celui de la recherche
    });

    // ---------------------------------------------------------------- Source : blocs affichés selon le choix
    var itemsTitle = form.querySelector('[data-items-title]');
    function source() { var r = form.querySelector('input[name=ca_source]:checked'); return r ? r.value : 'auto'; }
    function syncSource() {
      var s = source();
      form.querySelectorAll('[data-show]').forEach(function (n) { n.hidden = n.getAttribute('data-show') !== s; });
      form.querySelectorAll('[data-hide]').forEach(function (n) { n.hidden = n.getAttribute('data-hide') === s; });
      itemsTitle.textContent = '';
      itemsTitle.appendChild(document.createTextNode(s === 'manual' ? 'Produits du carrousel ' : 'Produits épinglés en tête '));
      itemsTitle.appendChild(el('small', 'muted', s === 'manual' ? '(dans cet ordre)' : '(facultatif, dans cet ordre)'));
    }
    var src = form.querySelector('input[name=ca_source][value="' + (c.source || 'auto') + '"]') || form.querySelector('input[name=ca_source][value=auto]');
    src.checked = true;
    form.querySelectorAll('input[name=ca_source]').forEach(function (r) { r.addEventListener('change', syncSource); });
    syncSource();

    // ---------------------------------------------------------------- Produits épinglés : recherche, ajout, ordre par glisser-déposer
    var list = form.querySelector('[data-items-list]');
    var search = form.querySelector('[data-item-search]');
    var results = form.querySelector('[data-item-results]');
    function money(v) { return v === null || v === undefined || v === '' ? '' : Number(v).toFixed(2).replace('.', ',') + ' €'; }
    function thumb(src) {
      var box = el('span', 'ca-thumb');
      if (/^https?:\/\//i.test(src || '')) { var im = el('img'); im.src = src; im.alt = ''; im.loading = 'lazy'; box.appendChild(im); }
      return box;
    }
    function skus() { return Array.prototype.map.call(list.querySelectorAll('[data-sku]'), function (li) { return li.getAttribute('data-sku'); }); }
    function addItem(p) {
      if (skus().indexOf(p.sku) !== -1) { return; }
      var li = el('li', 'ca-item' + (p.missing ? ' ca-missing' : ''));
      li.setAttribute('data-sku', p.sku);
      li.draggable = true;
      li.appendChild(el('span', 'ca-grip', '⠿'));
      li.appendChild(thumb(p.image));
      var txt = el('span', 'ca-item-text');
      txt.appendChild(el('strong', null, p.name || p.sku));
      txt.appendChild(el('small', 'muted', p.missing ? p.sku + ' · introuvable dans le catalogue' : p.sku + (p.brand ? ' · ' + p.brand : '') + (p.price !== undefined && p.price !== null ? ' · ' + money(p.price) : '')));
      li.appendChild(txt);
      var rm = el('button', 'icon-btn', '✕');
      rm.type = 'button';
      rm.setAttribute('aria-label', 'Retirer ' + (p.name || p.sku));
      rm.addEventListener('click', function () { li.remove(); });
      li.appendChild(rm);
      list.appendChild(li);
    }
    JSON.parse(form.getAttribute('data-items') || '[]').forEach(addItem);
    var dragged = null;
    list.addEventListener('dragstart', function (ev) {
      dragged = ev.target.closest('li');
      if (!dragged) { return; }
      dragged.classList.add('ly-dragging');
      ev.dataTransfer.effectAllowed = 'move';
      try { ev.dataTransfer.setData('text/plain', ''); } catch (e) { /* Firefox */ }
    });
    list.addEventListener('dragover', function (ev) {
      var over = ev.target.closest('li');
      if (!dragged || !over || over === dragged) { return; }
      ev.preventDefault();
      var r = over.getBoundingClientRect();
      list.insertBefore(dragged, ev.clientY > r.top + r.height / 2 ? over.nextSibling : over);
    });
    list.addEventListener('dragend', function () { if (dragged) { dragged.classList.remove('ly-dragging'); } dragged = null; });

    var timer = null;
    function runSearch() {
      var q = search.value.trim();
      if (q.length < 2) { results.hidden = true; return; }
      fetch(form.getAttribute('data-search-url') + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          results.textContent = '';
          (d.items || []).slice(0, 20).forEach(function (p) {
            var b = el('button', 'ca-result');
            b.type = 'button';
            b.appendChild(thumb(p.image));
            var t = el('span', 'ca-item-text');
            t.appendChild(el('strong', null, p.name || p.sku));
            t.appendChild(el('small', 'muted', p.sku + (p.brand ? ' · ' + p.brand : '') + ' · ' + money(p.price) + (p.in_stock ? '' : ' · rupture')));
            b.appendChild(t);
            if (skus().indexOf(p.sku) !== -1) { b.disabled = true; }
            b.addEventListener('click', function () { addItem(p); b.disabled = true; search.focus(); });
            results.appendChild(b);
          });
          if (!results.children.length) { results.appendChild(el('p', 'muted small', d.error || 'Aucun produit trouvé.')); }
          results.hidden = false;
        })
        .catch(function () { results.textContent = 'Recherche impossible.'; results.hidden = false; });
    }
    search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(runSearch, 250); });
    search.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); clearTimeout(timer); runSearch(); } if (ev.key === 'Escape') { results.hidden = true; } });
    document.addEventListener('click', function (ev) { if (!ev.target.closest('.ca-picker')) { results.hidden = true; } });

    // ---------------------------------------------------------------- Conditions (même éditeur que les règles selon la page)
    var root = form.querySelector('[data-rule-root]');
    var conds = form.querySelector('[data-conds]');
    form.querySelector('input[name=pr_match][value=' + (c.match_all === false ? 'any' : 'all') + ']').checked = true;
    var lib = window.DFConditions({ fields: fields, valuesUrl: form.getAttribute('data-values-url'), root: root, listOps: true, listContains: true });
    function addCond(cond) {
      var row = lib.conditionRow(cond || {});
      conds.appendChild(row);
      if (!cond || !cond.field) { row.focusField(); }
    }
    (c.conditions || []).forEach(function (cond) { addCond(cond); });
    form.querySelector('[data-add-cond]').addEventListener('click', function () { addCond(); });
    document.addEventListener('click', function (ev) {
      if (ev.target.isConnected && !ev.target.closest('.ms')) { root.querySelectorAll('.ms-panel').forEach(function (p) { p.hidden = true; }); }
    });

    // ---------------------------------------------------------------- Adapter les produits selon la page : adresse → conditions en plus
    var pfBox = form.querySelector('[data-pfs]');
    function addPf(pf) {
      pf = pf || { url: { match: 'contains', text: '' }, match_all: true, conditions: [{ field: 'category_names', op: 'contains' }] };
      var box = el('div', 'ca-pf');
      var head = el('div', 'ca-pf-head');
      head.appendChild(el('span', 'ca-pf-label', 'Sur les pages dont l\'adresse'));
      var op = el('select', 'pr-url-op');
      op.setAttribute('aria-label', 'Comparaison de l\'adresse');
      Object.keys(urlOps).forEach(function (k) { var o = el('option', '', urlOps[k]); o.value = k; op.appendChild(o); });
      op.value = urlOps[(pf.url || {}).match] ? pf.url.match : 'contains';
      var txt = el('input', 'pr-url-text');
      txt.type = 'text';
      txt.maxLength = 300;
      txt.placeholder = '/peche';
      txt.value = (pf.url || {}).text || '';
      txt.setAttribute('aria-label', 'Adresse de la page');
      var rm = el('button', 'icon-btn', '✕');
      rm.type = 'button';
      rm.setAttribute('aria-label', 'Supprimer cette adaptation');
      rm.addEventListener('click', function () { box.remove(); });
      [op, txt, rm].forEach(function (n) { head.appendChild(n); });
      var mrow = el('div', 'ca-pf-match');
      mrow.appendChild(el('span', null, 'proposer uniquement les produits qui remplissent'));
      var m = el('select', 'ca-pf-all');
      m.setAttribute('aria-label', 'Toutes ou au moins une condition');
      [['all', 'toutes les conditions'], ['any', 'au moins une condition']].forEach(function (x) { var o = el('option', '', x[1]); o.value = x[0]; m.appendChild(o); });
      m.value = pf.match_all === false ? 'any' : 'all';
      mrow.appendChild(m);
      var cl = el('div', 'br-conds');
      var add = el('button', 'btn btn-light btn-sm br-add-cond', '+ Ajouter une condition');
      add.type = 'button';
      function addRow(cond) { var row = lib.conditionRow(cond || {}); cl.appendChild(row); return row; }
      add.addEventListener('click', function () { addRow().focusField(); });
      (pf.conditions || []).forEach(function (cd) { addRow(cd); });
      [head, mrow, cl, add].forEach(function (n) { box.appendChild(n); });
      pfBox.appendChild(box);
      return txt;
    }
    (c.page_filters || []).forEach(function (pf) { addPf(pf); });
    form.querySelector('[data-add-pf]').addEventListener('click', function () { addPf().focus(); });

    // ---------------------------------------------------------------- Données du formulaire (enregistrement et aperçu)
    // conditions d'un bloc, vérifiées ; prefix : précise le bloc dans le message
    function readConds(container, prefix) {
      var out = [];
      var problem = '';
      container.querySelectorAll('.br-cond').forEach(function (r) {
        var cd = r.getCondition && r.getCondition();
        if (!cd || problem) { return; }
        if (cd.values && !cd.values.length) {
          problem = prefix + (cd.op === 'contains' || cd.op === 'not_contains' ? 'saisissez au moins un texte pour « ' : 'choisissez au moins une valeur pour « ') + (byCode[cd.field] || {}).label + ' ».';
          return;
        }
        if (cd.value !== undefined && (cd.value === '' || isNaN(Number(cd.value)))) { problem = prefix + 'valeur numérique attendue pour « ' + (byCode[cd.field] || {}).label + ' ».'; return; }
        if (cd.value !== undefined) { cd.value = Number(cd.value); }
        out.push(cd);
      });
      return { list: out, problem: problem };
    }
    function collect() {
      var period = editor.period();
      var main = readConds(conds, 'Conditions : ');
      var list0 = main.list;
      var problem = main.problem ? main.problem.charAt(0).toUpperCase() + main.problem.slice(1) : '';
      var pageFilters = [];
      if (source() !== 'manual') {
        pfBox.querySelectorAll('.ca-pf').forEach(function (box, i) {
          var text = box.querySelector('.pr-url-text').value.trim();
          var prefix = 'Adaptation selon la page n° ' + (i + 1) + (text ? ' (« ' + text + ' »)' : '') + ' : ';
          var rc = readConds(box, prefix);
          if (!text && !rc.list.length) { return; }
          if (!problem && rc.problem) { problem = rc.problem; }
          if (!problem && !text) { problem = prefix + 'indiquez l\'adresse de la page (ex. contient « /peche »).'; }
          if (!problem && !rc.list.length) { problem = prefix + 'ajoutez au moins une condition sur les produits.'; }
          pageFilters.push({ url: { match: box.querySelector('.pr-url-op').value, text: text }, match: box.querySelector('.ca-pf-all').value, conditions: rc.list });
        });
      }
      return {
        problem: problem,
        data: {
          id: c.id || 0, name: f('name').value, active: f('active').checked,
          duration: period.on ? 'period' : 'always', starts_at: period.start, ends_at: period.end, _period: period,
          pages: pagesUi.mode(), home: f('home').checked, urls: pagesUi.urls('urls'), exclude_urls: pagesUi.urls('exclude_urls'), segments: pagesUi.segments(),
          selector: f('selector').value, insert_mode: f('insert_mode').value, device: f('device').value,
          title: f('title').value, title_class: f('title_class').value, more_url: f('more_url').value, more_label: f('more_label').value,
          source: source(), items: skus(), match: form.querySelector('input[name=pr_match]:checked').value, conditions: list0, page_filters: pageFilters,
          sort_by: f('sort_by').value, similar_by: f('similar_by').value, personal_mode: form.querySelector('input[name=ca_personal]:checked').value,
          learning: f('learning').checked, in_stock_only: f('in_stock_only').checked, max_items: f('max_items').value,
          per_view_desktop: f('per_view_desktop').value, per_view_mobile: f('per_view_mobile').value, autoplay: f('autoplay').value,
          custom_css: f('custom_css').value, card_template: f('card_template').value, card_template_mobile: f('card_template_mobile').value,
          card_link: f('card_link').value
        }
      };
    }

    form.addEventListener('submit', function (ev) {
      var r = collect();
      var d = r.data;
      var problem = r.problem;
      if (!problem && d._period.on && (!d._period.start || !d._period.end)) { problem = 'Choisissez la date de début et la date de fin de la période.'; }
      else if (!problem && d.pages === 'custom' && !d.home && !d.urls.length) { problem = 'Choisissez les pages : la page d\'accueil et/ou au moins une adresse (ex. contient « /nautisme/ »).'; }
      else if (!problem && !d.selector.trim()) { problem = 'Indiquez l\'emplacement : l\'identifiant (#…) ou la classe (.…) du bloc de la page.'; }
      else if (!problem && d.source === 'manual' && !d.items.length) { problem = 'Sélection manuelle : ajoutez au moins un produit.'; }
      if (problem) { ev.preventDefault(); ui.alert(problem); return; }
      delete d._period;
      form.querySelector('input[name=payload]').value = JSON.stringify(d);
    });

    // ---------------------------------------------------------------- Aperçu direct (ordinateur / mobile) : produits de la page choisie ci-dessous
    function previewBody(r) {
      var body = new FormData();
      body.append('_csrf', form.getAttribute('data-csrf'));
      body.append('payload', JSON.stringify(r.data));
      body.append('page', form.querySelector('[data-preview-page]').value);
      body.append('sku', form.querySelector('[data-preview-sku]').value);
      body.append('history', form.querySelector('[data-preview-history]').value);
      body.append('cart', form.querySelector('[data-preview-cart]').value);
      body.append('cart_total', form.querySelector('[data-preview-cart-total]').value);
      return body;
    }
    window.DFLivePreview({
      button: form.querySelector('[data-live-preview]'), frameUrl: form.getAttribute('data-frame-url'), type: 'carousel',
      load: function () {
        var r = collect();
        if (r.problem) { return Promise.resolve({ error: r.problem }); }
        delete r.data._period;
        return fetch(form.getAttribute('data-preview-url'), { method: 'POST', body: previewBody(r), credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (res) { return res.json(); })
          .then(function (d) {
            if (!d.carousel) { return { error: d.note || 'Aperçu impossible.' }; }
            if (!d.carousel.products.length) { return { error: 'Aucun produit pour cette page : vérifiez les conditions (ou la page choisie dans « Liste des produits sélectionnés »).' }; }
            return { item: d.carousel, note: d.note };
          });
      }
    });

    // bouton du bloc « Liste des produits sélectionnés » : ouvre le même rendu réel que le bouton du bas
    var altLive = form.querySelector('[data-live-preview-alt]');
    if (altLive) { altLive.addEventListener('click', function () { form.querySelector('[data-live-preview]').click(); }); }

    // ---------------------------------------------------------------- Liste des produits sélectionnés (contrôle)
    var pv = form.querySelector('[data-preview-list]');
    var note = form.querySelector('[data-preview-note]');
    form.querySelector('[data-preview-run]').addEventListener('click', function () {
      var r = collect();
      if (r.problem) { ui.alert(r.problem); return; }
      delete r.data._period;
      var body = previewBody(r);
      note.textContent = 'Chargement…';
      pv.textContent = '';
      fetch(form.getAttribute('data-preview-url'), { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (res) { return res.json(); })
        .then(function (d) {
          note.textContent = (d.items && d.items.length ? d.items.length + ' produit(s). ' : 'Aucun produit : vérifiez les conditions (ou le catalogue). ') + (d.note || '');
          (d.items || []).forEach(function (p, i) {
            var card = el('div', 'ca-pcard');
            card.appendChild(el('span', 'ca-pcard-rank', String(i + 1)));
            card.appendChild(thumb(p.image));
            card.appendChild(el('strong', null, p.name || p.sku));
            card.appendChild(el('small', 'muted', p.sku + (p.brand ? ' · ' + p.brand : '')));
            card.appendChild(el('span', 'ca-pcard-price', money(p.price) + (p.in_stock ? '' : ' · rupture')));
            pv.appendChild(card);
          });
        })
        .catch(function () { note.textContent = 'Aperçu impossible : vérifiez la connexion.'; });
    });
  });
})();
