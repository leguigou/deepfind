/*!
 * DeepFind — widget de recherche instantanée (sans dépendance).
 * <script src="https://search.example.com/widget/deepfind.js" defer
 *         data-key="pk_…" data-lang="fr" data-input="#search" data-anchor="header"
 *         data-results-url="https://www.example.com/catalogsearch/result/?q="></script>
 * data-native="keep"   : laisse fonctionner l'autocomplétion d'origine du site (par défaut : elle est neutralisée)
 * data-hide=".panneau" : sélecteurs CSS supplémentaires de panneaux d'origine à masquer
 * data-position="anchor" : panneau sous data-anchor (par défaut : juste sous la barre de recherche, avec data-gap px d'écart)
 * data-enter="off"     : la touche Entrée n'ouvre pas la page de résultats (sinon : réglage de l'extranet, Paramètres › Widget)
 */
(function () {
  'use strict';
  if (window.DeepFind) { return; }

  var script = document.currentScript || document.querySelector('script[src*="deepfind.js"]');
  if (!script) { return; }
  var ds = script.dataset;
  var base = script.src.replace(/\/widget\/deepfind\.js.*$/, '');
  var cfg = {
    key: ds.key || '',
    lang: ds.lang || 'fr',
    input: ds.input || 'input[name="q"]',
    anchor: ds.anchor || '',
    api: (ds.api || base + '/api/v1').replace(/\/$/, ''),
    resultsUrl: ds.resultsUrl || '',
    currency: ds.currency || 'EUR',
    locale: ds.locale || 'fr-FR',
    minChars: parseInt(ds.minChars || '2', 10),
    pageSize: Math.min(48, parseInt(ds.pageSize || '20', 10)),
    debounce: parseInt(ds.debounce || '180', 10),
    // Autocomplétion d'origine du site (Magento…) : neutralisée par défaut pour ne pas s'ouvrir en même temps
    blockNative: ds.native !== 'keep',
    hide: ds.hide || '',
    position: ds.position === 'anchor' ? 'anchor' : 'input',
    gap: parseInt(ds.gap || '12', 10),
    // Entrée → page de résultats : data-enter (on/off) prioritaire, sinon réglage renvoyé par l'API
    enter: ds.enter === 'off' ? false : (ds.enter === 'on' ? true : null)
  };
  var enterSubmit = cfg.enter !== null ? cfg.enter : true;
  // Panneaux d'autocomplétion connus (Magento Luma / Hyvä, Amasty, Mirasvit, Smile ElasticSuite) : masqués si présents
  var NATIVE_PANELS = '#search_autocomplete, .search-autocomplete, .amsearch-results, .amsearch-wrapper-block .amsearch-results, '
    + '.mst-searchautocomplete__autocomplete, .smile-elasticsuite-autocomplete-result';
  if (!cfg.key) { console.warn('[DeepFind] data-key manquant'); return; }

  var T = {
    fr: { products: 'Produits', brands: 'Marques', posts: 'Articles de blog', filters: 'Filtres', inStock: 'En stock', onSale: 'En promotion',
      seeAll: 'Tout voir', sort: 'Trier', relevance: 'Pertinence', priceAsc: 'Prix croissant', priceDesc: 'Prix décroissant', newest: 'Nouveautés',
      none: 'Aucun résultat pour', tryOther: 'Vérifiez l\'orthographe ou essayez un terme plus général.', from: 'dès', outOfStock: 'Rupture',
      isNew: 'Nouveau', close: 'Fermer', busy: 'Trop de recherches en peu de temps, patientez quelques secondes…', error: 'Recherche momentanément indisponible.',
      goTo: 'Voir la page', closeFilters: 'Fermer les filtres', clear: 'Effacer les filtres', results: 'résultats', loading: 'Chargement…',
      approx: 'Aucun résultat exact pour « %s ». Produits approchants :', approxTitle: 'Produits approchants' },
    en: { products: 'Products', brands: 'Brands', posts: 'Blog posts', filters: 'Filters', inStock: 'In stock', onSale: 'On sale',
      seeAll: 'See all', sort: 'Sort', relevance: 'Relevance', priceAsc: 'Price: low to high', priceDesc: 'Price: high to low', newest: 'Newest',
      none: 'No results for', tryOther: 'Check the spelling or try a more general term.', from: 'from', outOfStock: 'Out of stock',
      isNew: 'New', close: 'Close', busy: 'Too many searches, please wait a few seconds…', error: 'Search temporarily unavailable.',
      goTo: 'Go to page', closeFilters: 'Close filters', clear: 'Clear filters', results: 'results', loading: 'Loading…',
      approx: 'No exact match for “%s”. Similar products:', approxTitle: 'Similar products' }
  };
  var t = T[cfg.lang] || T.fr;
  var money;
  try { money = new Intl.NumberFormat(cfg.locale, { style: 'currency', currency: cfg.currency }); } catch (e) { money = { format: function (n) { return n.toFixed(2) + ' €'; } }; }
  var sid = (function () { var a = new Uint8Array(12); (window.crypto || window.msCrypto).getRandomValues(a); return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); })();

  // ------------------------------------------------------------------ Feuille de style
  var css = document.createElement('link');
  css.rel = 'stylesheet';
  css.href = base + '/widget/deepfind.css?v=6'; // ajoutée seulement si le projet est actif (voir démarrage en bas)

  // ------------------------------------------------------------------ Utilitaires DOM
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = String(text); }
    return n;
  }
  function safeUrl(u) { return typeof u === 'string' && /^https?:\/\//i.test(u) ? u : ''; }
  function fmt(n) { return money.format(Number(n) || 0); }

  // ------------------------------------------------------------------ Structure du panneau
  var overlay = el('div', 'df-overlay');
  overlay.hidden = true;
  // Fond sombre sur toute la page, percé à l'emplacement de la barre de recherche (qui reste cliquable et mise en avant)
  var backdrop = el('div', 'df-backdrop');
  var ring = el('div', 'df-ring');
  overlay.appendChild(backdrop);
  overlay.appendChild(ring);
  var layer = el('div', 'df-layer');
  layer.setAttribute('role', 'dialog');
  layer.setAttribute('aria-label', t.products);
  overlay.appendChild(layer);

  var closeBtn = el('button', 'df-close', '×');
  closeBtn.type = 'button';
  closeBtn.setAttribute('aria-label', t.close);
  var sideBackdrop = el('div', 'df-side-backdrop');
  var side = el('aside', 'df-side');
  side.id = 'df-filters';
  var sideHead = el('div', 'df-side-head');
  sideHead.appendChild(el('h3', null, t.filters));
  var sideCloseBtn = el('button', 'df-side-close', t.close);
  sideCloseBtn.type = 'button';
  sideCloseBtn.setAttribute('aria-label', t.closeFilters);
  sideHead.appendChild(sideCloseBtn);
  var sideBody = el('div', 'df-side-body');
  var sideToggles = el('div', 'df-side-toggles');
  var sideContent = el('div');
  sideBody.appendChild(sideToggles);
  sideBody.appendChild(sideContent);
  side.appendChild(sideHead);
  side.appendChild(sideBody);
  var main = el('div', 'df-main');
  layer.appendChild(closeBtn);
  layer.appendChild(side);
  layer.appendChild(main);
  layer.appendChild(sideBackdrop);

  var head = el('div', 'df-head');
  var title = el('h2', 'df-title');
  var tools = el('div', 'df-tools');
  var filtersBtn = el('button', 'df-filters-btn', t.filters);
  filtersBtn.type = 'button';
  filtersBtn.setAttribute('aria-controls', side.id);
  filtersBtn.setAttribute('aria-expanded', 'false');
  function toggle(name, label) {
    var lab = el('label', 'df-toggle');
    var cb = el('input');
    cb.type = 'checkbox';
    cb.name = name;
    lab.appendChild(cb);
    lab.appendChild(document.createTextNode(' ' + label));
    var count = el('span', 'df-count');
    lab.appendChild(count);
    return { label: lab, input: cb, count: count };
  }
  var tgStock = toggle('in_stock', t.inStock);
  var tgSale = toggle('on_sale', t.onSale);
  var sortSel = el('select', 'df-sort');
  sortSel.setAttribute('aria-label', t.sort);
  [['relevance', t.relevance], ['price_asc', t.priceAsc], ['price_desc', t.priceDesc], ['newest', t.newest]].forEach(function (o) {
    var opt = el('option', null, o[1]); opt.value = o[0]; sortSel.appendChild(opt);
  });
  var seeAll = el('a', 'df-see-all');
  tools.appendChild(filtersBtn);
  tools.appendChild(sortSel);
  tools.appendChild(seeAll);
  head.appendChild(title);
  head.appendChild(tools);
  var notice = el('div', 'df-notice');
  notice.hidden = true;
  var grid = el('div', 'df-grid');
  var empty = el('div', 'df-empty');
  empty.hidden = true;
  var loader = el('div', 'df-loader', t.loading);
  loader.hidden = true;
  var sentinel = el('div', 'df-sentinel');
  main.appendChild(head);
  main.appendChild(notice);
  var bannerBox = el('div', 'df-banner');
  bannerBox.hidden = true;
  main.appendChild(bannerBox);
  main.appendChild(grid);
  main.appendChild(empty);
  main.appendChild(loader);
  main.appendChild(sentinel);

  // ------------------------------------------------------------------ État
  var state = { q: '', offset: 0, total: 0, hasMore: false, loading: false, reqId: 0, filters: {}, sort: 'relevance', redirect: null, redirectAuto: false, ids: {} };
  var controller = null;
  var cache = new Map();
  var timer = null;
  var activeInput = null;
  var mobile = window.matchMedia('(max-width: 900px)');

  function resetFilters() { state.filters = {}; }

  function buildUrl(offset) {
    var p = new URLSearchParams();
    p.set('key', cfg.key);
    p.set('lang', cfg.lang);
    p.set('q', state.q);
    p.set('offset', String(offset));
    p.set('limit', String(cfg.pageSize));
    p.set('sid', sid);
    if (offset > 0) { p.set('posts', '0'); }
    if (tgStock.input.checked) { p.set('in_stock', '1'); }
    if (tgSale.input.checked) { p.set('on_sale', '1'); }
    if (state.sort !== 'relevance') { p.set('sort', state.sort); }
    Object.keys(state.filters).sort().forEach(function (code) {
      state.filters[code].forEach(function (v) {
        if (code === 'brand' || code === 'category') { p.append(code + '[]', v); }
        else { p.append('attr[' + code.replace(/^attr\./, '') + '][]', v); }
      });
    });
    return cfg.api + '/search?' + p.toString();
  }

  function search(append) {
    if (state.q.length < cfg.minChars) { return; }
    var offset = append ? state.offset : 0;
    var url = buildUrl(offset);
    var id = ++state.reqId;
    if (!append && controller) { controller.abort(); }
    controller = window.AbortController ? new AbortController() : null;
    state.loading = true;
    loader.hidden = !append;
    var cached = cache.get(url);
    var p = cached ? Promise.resolve(cached) : fetch(url, { credentials: 'omit', signal: controller ? controller.signal : undefined, headers: { Accept: 'application/json' } })
      .then(function (r) {
        if (r.status === 429) { throw { busy: true }; }
        if (!r.ok) { throw { status: r.status }; }
        return r.json();
      });
    p.then(function (data) {
      if (cfg.enter === null && data && data.widget) { enterSubmit = data.widget.enter_submit !== false; }
      if (id !== state.reqId) { return; }
      if (!cached) { cache.set(url, data); if (cache.size > 60) { cache.delete(cache.keys().next().value); } }
      render(data, append);
    }).catch(function (err) {
      if (err && err.name === 'AbortError') { return; }
      if (id !== state.reqId) { return; }
      showNotice(err && err.busy ? t.busy : t.error, null);
    }).then(function () {
      if (id === state.reqId) { state.loading = false; loader.hidden = true; }
    });
  }

  function showNotice(text, href) {
    notice.textContent = '';
    if (!text) { notice.hidden = true; return; }
    if (href) {
      var a = el('a', null, text); a.href = href; notice.appendChild(a);
    } else {
      notice.textContent = text;
    }
    notice.hidden = false;
  }

  // ------------------------------------------------------------------ Rendu
  // ------------------------------------------------------------------ Bannière (haut des résultats)
  var BANNER_TAGS = { A: 1, IMG: 1, PICTURE: 1, SOURCE: 1, DIV: 1, SPAN: 1, P: 1, STRONG: 1, B: 1, EM: 1, I: 1, U: 1, BR: 1, H2: 1, H3: 1, H4: 1, UL: 1, OL: 1, LI: 1, SMALL: 1 };
  var BANNER_ATTRS = { href: 1, target: 1, rel: 1, src: 1, srcset: 1, sizes: 1, media: 1, alt: 1, title: 1, width: 1, height: 1, class: 1, style: 1, loading: 1 };
  // HTML déjà nettoyé par le serveur ; refiltré ici (balises / attributs autorisés, liens http(s) ou relatifs) avant insertion
  function cleanNode(src) {
    if (src.nodeType === 3) { return document.createTextNode(src.nodeValue); }
    if (src.nodeType !== 1 || !BANNER_TAGS[src.tagName]) {
      var frag = document.createDocumentFragment();
      if (src.nodeType === 1 && !/^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|FORM|SVG|TEMPLATE)$/.test(src.tagName)) {
        Array.prototype.forEach.call(src.childNodes, function (c) { frag.appendChild(cleanNode(c)); });
      }
      return frag;
    }
    var n = document.createElement(src.tagName.toLowerCase());
    Array.prototype.forEach.call(src.attributes, function (a) {
      var name = a.name.toLowerCase();
      var v = a.value;
      if (!BANNER_ATTRS[name]) { return; }
      if ((name === 'href' || name === 'src') && !/^(https?:\/\/|\/(?!\/)|#)/i.test(v.trim())) { return; }
      if ((name === 'srcset' || name === 'style') && /javascript:|url\s*\(|expression/i.test(v)) { return; }
      n.setAttribute(name, v);
    });
    if (n.tagName === 'A' && n.getAttribute('target') === '_blank') { n.setAttribute('rel', 'noopener'); }
    Array.prototype.forEach.call(src.childNodes, function (c) { n.appendChild(cleanNode(c)); });
    return n;
  }
  function renderBanner(b) {
    bannerBox.textContent = '';
    bannerBox.hidden = !b;
    if (!b) { return; }
    if (b.mode === 'code') {
      var doc = new DOMParser().parseFromString('<body>' + String(b.html || '') + '</body>', 'text/html');
      Array.prototype.forEach.call(doc.body.childNodes, function (c) { bannerBox.appendChild(cleanNode(c)); });
    } else {
      var desktop = safeUrl(b.desktop);
      if (!desktop) { bannerBox.hidden = true; return; }
      var pic = el('picture');
      var mobile = safeUrl(b.mobile);
      if (mobile && mobile !== desktop) {
        var srcEl = el('source');
        srcEl.media = '(max-width: 900px)';
        srcEl.srcset = mobile;
        pic.appendChild(srcEl);
      }
      var img = el('img', 'df-banner-img');
      img.src = desktop;
      img.alt = b.alt || '';
      pic.appendChild(img);
      var link = safeUrl(b.link);
      var wrap = link ? el('a', 'df-banner-link') : el('div');
      if (link) {
        wrap.href = link;
        if (b.new_window) { wrap.target = '_blank'; wrap.rel = 'noopener'; }
      }
      wrap.appendChild(pic);
      bannerBox.appendChild(wrap);
    }
    bannerBox.__dfBanner = b.id;
  }
  // Clic sur un lien de la bannière (y compris clic molette) : mesuré comme les clics produits
  function bannerClick(ev) {
    if ((ev.type === 'auxclick' && ev.button !== 1) || !ev.target.closest || !ev.target.closest('a') || !bannerBox.__dfBanner) { return; }
    track('banner', bannerBox.__dfBanner, 0);
  }
  bannerBox.addEventListener('click', bannerClick);
  bannerBox.addEventListener('auxclick', bannerClick);

  function render(data, append) {
    state.total = data.total || 0;
    state.offset = data.next_offset || 0;
    state.hasMore = !!data.has_more;
    state.redirect = safeUrl(data.redirect);
    state.redirectAuto = !!data.redirect_auto;
    if (!append) {
      renderBanner(data.banner || null);
      grid.textContent = '';
      state.ids = {};
      main.scrollTop = 0;
      if (state.redirect) { showNotice(t.goTo + ' « ' + state.q + ' » →', state.redirect); }
      else if (data.approximate) { showNotice(t.approx.replace('%s', state.q), null); }
      else { showNotice(null, null); }
      notice.classList.toggle('df-notice-approx', !!data.approximate && !state.redirect);
      renderSide(data);
      title.textContent = (data.approximate ? t.approxTitle : t.products) + ' (' + state.total + ')';
      tgStock.count.textContent = data.toggles && data.toggles.in_stock ? ' (' + data.toggles.in_stock + ')' : '';
      tgSale.count.textContent = data.toggles && data.toggles.on_sale ? ' (' + data.toggles.on_sale + ')' : '';
      var more = state.total - (data.products || []).length;
      if (cfg.resultsUrl && state.total > 0) {
        seeAll.hidden = false;
        seeAll.href = cfg.resultsUrl + encodeURIComponent(state.q);
        seeAll.textContent = t.seeAll + (more > 0 ? ' (+' + more + ')' : '');
      } else { seeAll.hidden = true; }
      empty.hidden = state.total > 0;
      if (!state.total) {
        empty.textContent = '';
        empty.appendChild(el('strong', null, t.none + ' « ' + state.q + ' »'));
        empty.appendChild(el('p', null, t.tryOther));
      }
    }
    var frag = document.createDocumentFragment();
    (data.products || []).forEach(function (p, i) {
      if (state.ids[p.id]) { return; }
      state.ids[p.id] = true;
      frag.appendChild(card(p, (data.offset || 0) + i + 1));
    });
    grid.appendChild(frag);
    // Si le panneau n'est pas encore rempli, on charge la page suivante directement.
    requestAnimationFrame(function () {
      if (state.hasMore && !state.loading && main.scrollHeight <= main.clientHeight + 200) { search(true); }
    });
  }

  function card(p, position) {
    var href = safeUrl(p.matched_variant && p.matched_variant.url) || safeUrl(p.url) || '#';
    var a = el('a', 'df-card' + (p.in_stock ? '' : ' df-oos'));
    a.href = href;
    var media = el('div', 'df-media');
    var src = safeUrl(p.image);
    if (src) {
      var img = el('img');
      img.loading = 'lazy';
      img.decoding = 'async';
      img.alt = '';
      img.src = src;
      img.onerror = function () { img.remove(); media.classList.add('df-noimg'); };
      media.appendChild(img);
      var hover = safeUrl(p.image_hover);
      if (hover) {
        a.addEventListener('mouseenter', function () { img.src = hover; });
        a.addEventListener('mouseleave', function () { img.src = src; });
      }
    } else { media.classList.add('df-noimg'); }
    if (p.on_sale && p.discount_percent > 0) { media.appendChild(el('span', 'df-badge df-badge-sale', '-' + p.discount_percent + '%')); }
    else if (p.is_new) { media.appendChild(el('span', 'df-badge df-badge-new', t.isNew)); }
    if (!p.in_stock) { media.appendChild(el('span', 'df-badge df-badge-oos', t.outOfStock)); }
    a.appendChild(media);
    if (p.brand) { a.appendChild(el('div', 'df-brand', p.brand)); }
    a.appendChild(el('div', 'df-name', p.name));
    if (p.matched_variant) { a.appendChild(el('div', 'df-variant', p.matched_variant.sku)); }
    var price = el('div', 'df-price' + (p.on_sale ? ' df-sale' : ''));
    var mv = p.matched_variant;
    var final = mv ? mv.final_price : p.final_price;
    var regular = mv ? mv.price : p.price;
    if (!mv && p.has_price_range) { price.appendChild(el('span', 'df-from', t.from + ' ')); }
    price.appendChild(el('span', 'df-final', fmt(final)));
    if (regular > final + 0.005) { price.appendChild(el('s', 'df-old', fmt(regular))); }
    a.appendChild(price);
    onOpen(a, function () { track('product', p.id, position, p.cr); });
    return a;
  }

  function renderSide(data) {
    sideContent.textContent = '';
    var facets = data.facets || [];
    var hasFilter = tgStock.input.checked || tgSale.input.checked || Object.keys(state.filters).some(function (k) { return state.filters[k].length; });
    if (hasFilter) {
      var clear = el('button', 'df-clear', t.clear);
      clear.type = 'button';
      clear.addEventListener('click', function () {
        tgStock.input.checked = false;
        tgSale.input.checked = false;
        resetFilters();
        search(false);
      });
      sideContent.appendChild(clear);
    }
    facets.forEach(function (f) {
      var sec = el('div', 'df-sec');
      sec.appendChild(el('h3', null, f.label));
      var list = el('ul', 'df-facet');
      var max = f.code === 'brand' ? 12 : 8;
      f.values.forEach(function (v, i) {
        var li = el('li');
        if (i >= max && !v.selected) { li.className = 'df-more-item'; li.hidden = true; }
        var b = el('button', 'df-fv' + (v.selected ? ' df-on' : ''));
        b.type = 'button';
        b.setAttribute('aria-pressed', v.selected ? 'true' : 'false');
        b.appendChild(el('span', 'df-fv-label', v.value));
        b.appendChild(el('span', 'df-fv-count', v.count));
        b.addEventListener('click', function () {
          var cur = state.filters[f.code] || [];
          var idx = cur.indexOf(v.value);
          if (idx >= 0) { cur.splice(idx, 1); } else { cur.push(v.value); }
          state.filters[f.code] = cur;
          search(false);
        });
        li.appendChild(b);
        list.appendChild(li);
      });
      sec.appendChild(list);
      if (f.values.length > max) {
        var more = el('button', 'df-more', '+ ' + (f.values.length - max));
        more.type = 'button';
        more.addEventListener('click', function () {
          list.querySelectorAll('.df-more-item').forEach(function (n) { n.hidden = false; });
          more.remove();
        });
        sec.appendChild(more);
      }
      sideContent.appendChild(sec);
    });
    var posts = data.posts || [];
    if (posts.length) {
      var ps = el('div', 'df-sec df-posts');
      ps.appendChild(el('h3', null, t.posts));
      posts.forEach(function (p, i) {
        var a = el('a', 'df-post');
        a.href = safeUrl(p.url) || '#';
        var im = safeUrl(p.image);
        if (im) { var img = el('img'); img.src = im; img.alt = ''; img.loading = 'lazy'; a.appendChild(img); }
        a.appendChild(el('span', null, p.title));
        onOpen(a, function () { track('post', p.id, i + 1); });
        ps.appendChild(a);
      });
      sideContent.appendChild(ps);
    }
  }

  // Ouverture d'un lien : clic (y compris Ctrl/Cmd + clic) et clic molette (nouvel onglet)
  function onOpen(a, fn) {
    a.addEventListener('click', fn);
    a.addEventListener('auxclick', function (ev) { if (ev.button === 1) { fn(); } });
  }

  function track(type, id, position, cr) {
    try {
      var body = new URLSearchParams({ key: cfg.key, lang: cfg.lang, q: state.q, doc_type: type, doc_id: id, position: String(position), sid: sid });
      if (cr) { body.set('cr', String(cr)); } // produit mis en avant par un résultat personnalisé
      if (navigator.sendBeacon) { navigator.sendBeacon(cfg.api + '/click', body); }
      else { fetch(cfg.api + '/click', { method: 'POST', body: body, keepalive: true, credentials: 'omit' }); }
    } catch (e) { /* sans importance */ }
  }

  // ------------------------------------------------------------------ Ouverture / fermeture
  // Barre de recherche à mettre en avant : le cadre qui contient le champ (bouton loupe compris), sans remonter plus haut
  function searchBox(input) {
    var box = input;
    var r = input.getBoundingClientRect();
    for (var n = input.parentElement, i = 0; n && i < 3; n = n.parentElement, i++) {
      var nr = n.getBoundingClientRect();
      if (nr.height > r.height * 1.6 || nr.width > r.width * 1.3) { break; }
      box = n; r = nr;
    }
    return box;
  }
  function position() {
    var w = window.innerWidth;
    var h = window.innerHeight;
    var spot = activeInput ? searchBox(activeInput).getBoundingClientRect() : null;
    var anchor = cfg.position === 'anchor' && cfg.anchor ? document.querySelector(cfg.anchor) : null;
    var top = anchor ? Math.round(anchor.getBoundingClientRect().bottom) : (spot ? Math.round(spot.bottom + cfg.gap) : 0);
    if (top < 0 || top > h * 0.6) { top = 0; }
    overlay.style.setProperty('--df-top', top + 'px');
    if (!spot || spot.bottom <= 0 || spot.top >= h) {
      backdrop.style.clipPath = '';
      ring.hidden = true;
      return;
    }
    var pad = 4;
    var x = Math.round(spot.left - pad), y = Math.round(spot.top - pad), sw = Math.round(spot.width + 2 * pad), sh = Math.round(spot.height + 2 * pad);
    var rad = Math.min(sh / 2, (parseFloat(getComputedStyle(activeInput).borderTopLeftRadius) || 0) + pad);
    // Plein écran moins un rectangle arrondi (règle evenodd) : le trou laisse passer clics et saisie
    var hole = 'M' + (x + rad) + ' ' + y + 'H' + (x + sw - rad) + 'A' + rad + ' ' + rad + ' 0 0 1 ' + (x + sw) + ' ' + (y + rad)
      + 'V' + (y + sh - rad) + 'A' + rad + ' ' + rad + ' 0 0 1 ' + (x + sw - rad) + ' ' + (y + sh)
      + 'H' + (x + rad) + 'A' + rad + ' ' + rad + ' 0 0 1 ' + x + ' ' + (y + sh - rad)
      + 'V' + (y + rad) + 'A' + rad + ' ' + rad + ' 0 0 1 ' + (x + rad) + ' ' + y + 'Z';
    backdrop.style.clipPath = 'path(evenodd, "M0 0H' + w + 'V' + h + 'H0Z' + hole + '")';
    ring.hidden = false;
    ring.style.cssText = 'left:' + x + 'px;top:' + y + 'px;width:' + sw + 'px;height:' + sh + 'px;border-radius:' + rad + 'px';
  }
  function open() {
    if (!overlay.parentNode) { document.body.appendChild(overlay); }
    position();
    if (overlay.hidden) {
      overlay.hidden = false;
      document.documentElement.classList.add('df-lock');
    }
  }
  function close() {
    overlay.hidden = true;
    setFiltersOpen(false);
    document.documentElement.classList.remove('df-lock');
  }
  function setFiltersOpen(isOpen, restoreFocus) {
    layer.classList.toggle('df-side-open', isOpen);
    filtersBtn.setAttribute('aria-expanded', String(isOpen));
    main.toggleAttribute('inert', isOpen);
    closeBtn.toggleAttribute('inert', isOpen);
    if (isOpen) { sideCloseBtn.focus({ preventScroll: true }); }
    else if (restoreFocus) { filtersBtn.focus({ preventScroll: true }); }
  }
  function syncFilterLayout() {
    if (mobile.matches) {
      sideToggles.appendChild(tgStock.label);
      sideToggles.appendChild(tgSale.label);
    } else {
      setFiltersOpen(false);
      tools.insertBefore(tgStock.label, sortSel);
      tools.insertBefore(tgSale.label, sortSel);
    }
  }
  function dismiss(ev) {
    if (overlay.hidden) { return; }
    if (layer.classList.contains('df-side-open')) {
      ev.preventDefault();
      ev.stopPropagation();
      setFiltersOpen(false, true);
    } else {
      close();
    }
  }

  function onInput(ev) {
    activeInput = ev.target;
    var q = ev.target.value.trim();
    clearTimeout(timer);
    if (q.length < cfg.minChars) {
      if (!q) { close(); }
      return;
    }
    timer = setTimeout(function () {
      if (q !== state.q) { resetFilters(); }
      state.q = q;
      open();
      search(false);
    }, cfg.debounce);
  }

  // Gestionnaires du champ de recherche, par type d'événement
  var handlers = {
    input: onInput,
    focus: function (ev) {
      var input = ev.target;
      activeInput = input;
      if (input.value.trim().length >= cfg.minChars && state.q === input.value.trim() && grid.childNodes.length) { open(); }
    },
    keydown: function (ev) {
      if (ev.key === 'Escape') { dismiss(ev); }
      if (ev.key !== 'Enter') { return; }
      // Redirection automatique : prioritaire, même si la touche Entrée n'ouvre pas la page de résultats
      var typed = ev.target.value.trim();
      if (state.redirect && state.redirectAuto && typed === state.q) { ev.preventDefault(); window.location.href = state.redirect; return; }
      if (!enterSubmit) {
        // Entrée désactivée : on reste sur la fenêtre DeepFind (recherche lancée tout de suite)
        ev.preventDefault();
        var q = ev.target.value.trim();
        if (q.length >= cfg.minChars) {
          clearTimeout(timer);
          if (q !== state.q) { resetFilters(); state.q = q; search(false); }
          open();
        }
        return;
      }
      if (goToResults(ev.target)) { ev.preventDefault(); }
    }
  };

  /**
   * Page à ouvrir pour la recherche validée (Entrée, bouton loupe) : redirection DeepFind, sinon data-results-url,
   * sinon (autocomplétion d'origine neutralisée) l'adresse du formulaire du site, que son propre script ne gère plus.
   * @return boolean vrai si la navigation est prise en charge
   */
  function goToResults(input) {
    var q = input.value.trim();
    if (!q) { return false; }
    var url = '';
    if (state.redirect && state.redirectAuto && q === state.q) {
      url = state.redirect;
    } else if (cfg.resultsUrl) {
      url = cfg.resultsUrl + encodeURIComponent(q);
    } else if (cfg.blockNative && input.form && (input.form.method || 'get').toLowerCase() === 'get') {
      try {
        var u = new URL(input.form.getAttribute('action') || window.location.href, window.location.href);
        u.searchParams.set(input.name || 'q', q);
        url = u.toString();
      } catch (e) { url = ''; }
    }
    if (url) { window.location.href = url; }
    return !!url;
  }

  function bind(input) {
    if (input.__df) { return; }
    input.__df = true;
    input.setAttribute('autocomplete', 'off');
    if (!cfg.blockNative) {
      Object.keys(handlers).forEach(function (type) { input.addEventListener(type, handlers[type]); });
    }
  }

  /*
   * Neutralise l'autocomplétion d'origine : les événements du champ sont interceptés tout en haut (window, phase de capture),
   * traités par DeepFind, puis arrêtés avant d'atteindre les scripts du site (jQuery, Alpine/Hyvä, Knockout…).
   * Le comportement par défaut du navigateur (saisie, curseur, envoi du formulaire) n'est pas touché.
   */
  /**
   * Bouton loupe du site : son script le désactive tant qu'il « voit » un champ vide, et il ne voit plus la saisie.
   * DeepFind l'active dès qu'il y a du texte (et le rend pleinement visible), le désactive si le champ est vide.
   */
  function syncButtons(input) {
    var form = input.form;
    if (!form) { return; }
    var buttons = Array.prototype.filter.call(form.elements, function (e) {
      return (e.tagName === 'BUTTON' && (e.getAttribute('type') || 'submit') === 'submit') || (e.tagName === 'INPUT' && e.type === 'submit');
    });
    var empty = !input.value.trim();
    buttons.forEach(function (b) {
      b.disabled = empty;
      b.setAttribute('aria-disabled', empty ? 'true' : 'false');
      b.style.opacity = empty ? '' : '1';
    });
  }

  function blockNative() {
    var types = ['focus', 'focusin', 'focusout', 'blur', 'click', 'mousedown', 'mouseup', 'pointerdown', 'pointerup', 'touchstart', 'touchend',
      'keydown', 'keyup', 'keypress', 'input', 'change', 'paste', 'cut', 'compositionstart', 'compositionend', 'search'];
    types.forEach(function (type) {
      window.addEventListener(type, function (ev) {
        var target = ev.target;
        if (!target || !target.__df) { return; }
        if (handlers[type]) { handlers[type](ev); }
        if (type === 'input' || type === 'change' || type === 'paste' || type === 'cut') { setTimeout(function () { syncButtons(target); }, 0); }
        ev.stopImmediatePropagation();
      }, true);
    });
    // Bouton loupe : le formulaire du site est envoyé par DeepFind (son script d'origine n'a plus l'état de la recherche)
    window.addEventListener('submit', function (ev) {
      var form = ev.target;
      var input = null;
      Array.prototype.forEach.call((form && form.elements) || [], function (e) { if (e.__df && !input) { input = e; } });
      if (!input) { return; }
      ev.stopImmediatePropagation();
      if (!input.value.trim() || goToResults(input)) { ev.preventDefault(); } // champ vide : rien, comme sur le site
    }, true);
    var hide = document.createElement('style');
    hide.textContent = NATIVE_PANELS + (cfg.hide ? ', ' + cfg.hide : '') + ' { display: none !important; }';
    document.head.appendChild(hide);
  }

  function init() {
    if (cfg.blockNative) { blockNative(); }
    syncFilterLayout();
    mobile.addEventListener('change', syncFilterLayout);
    document.querySelectorAll(cfg.input).forEach(bind);
    // Champs ajoutés plus tard (thèmes Magento qui reconstruisent l'en-tête)
    if (window.MutationObserver) {
      new MutationObserver(function () { document.querySelectorAll(cfg.input).forEach(bind); })
        .observe(document.body, { childList: true, subtree: true });
    }
    closeBtn.addEventListener('click', close);
    sideCloseBtn.addEventListener('click', function () { setFiltersOpen(false, true); });
    sideBackdrop.addEventListener('click', function () { setFiltersOpen(false, true); });
    // Clic sur le fond sombre : ferme la fenêtre sans déclencher ce qui se trouve dessous
    backdrop.addEventListener('mousedown', function (ev) { ev.preventDefault(); dismiss(ev); });
    backdrop.addEventListener('click', function (ev) { ev.preventDefault(); ev.stopPropagation(); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { dismiss(ev); } });
    window.addEventListener('resize', function () { if (!overlay.hidden) { position(); } });
    tgStock.input.addEventListener('change', function () { search(false); });
    tgSale.input.addEventListener('change', function () { search(false); });
    sortSel.addEventListener('change', function () { state.sort = sortSel.value; search(false); });
    filtersBtn.addEventListener('click', function () { setFiltersOpen(true); });
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        if (entries[0].isIntersecting && state.hasMore && !state.loading) { search(true); }
      }, { root: main, rootMargin: '600px 0px' }).observe(sentinel);
    } else {
      main.addEventListener('scroll', function () {
        if (state.hasMore && !state.loading && main.scrollTop + main.clientHeight > main.scrollHeight - 600) { search(true); }
      });
    }
  }

  // ------------------------------------------------------------------ Démarrage
  /*
   * Le widget ne prend la main (fenêtre de recherche, neutralisation de l'autocomplétion d'origine) que si l'API confirme
   * que le projet est actif. Projet désactivé dans l'extranet, ou API injoignable : rien n'est modifié, la recherche
   * d'origine du site fonctionne normalement. La réponse est gardée 5 min dans la session pour décider sans attendre.
   */
  var started = false;
  var cfgKey = 'df-cfg:' + cfg.key + ':' + cfg.lang;
  function readCfg() {
    try { var v = JSON.parse(window.sessionStorage.getItem(cfgKey) || 'null'); return v && Date.now() - v.t < 300000 ? v : null; } catch (e) { return null; }
  }
  function writeCfg(c) {
    try { window.sessionStorage.setItem(cfgKey, JSON.stringify({ enabled: !!c.enabled, enter_submit: c.enter_submit !== false, t: Date.now() })); } catch (e) { /* stockage indisponible */ }
  }
  function applyCfg(c) {
    if (cfg.enter === null && c && c.enter_submit !== undefined) { enterSubmit = c.enter_submit !== false; }
  }
  function fetchCfg() {
    return fetch(cfg.api + '/config?key=' + encodeURIComponent(cfg.key) + '&lang=' + encodeURIComponent(cfg.lang), { credentials: 'omit', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)); })
      .then(function (c) { writeCfg(c); applyCfg(c); return c; });
  }
  function activate() {
    if (started) { return; }
    started = true;
    document.head.appendChild(css);
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
  }

  window.DeepFind = {
    config: cfg,
    isActive: function () { return started; },
    open: function (q) { if (!started) { return; } if (q) { state.q = q; resetFilters(); } open(); search(false); },
    close: close
  };

  var known = readCfg();
  if (known) {
    applyCfg(known);
    if (known.enabled) { activate(); }
    fetchCfg().catch(function () { /* nouvel essai à la prochaine page */ });
  } else {
    fetchCfg().then(function (c) {
      if (c.enabled) { activate(); } else { console.info('[DeepFind] projet désactivé : la recherche du site reste inchangée'); }
    }).catch(function () { console.warn('[DeepFind] API injoignable : la recherche du site reste inchangée'); });
  }
})();
