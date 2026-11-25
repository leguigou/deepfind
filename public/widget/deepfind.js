/*!
 * DeepFind — widget de recherche instantanée (sans dépendance).
 * <script src="https://search.example.com/widget/deepfind.js" defer
 *         data-key="pk_…" data-lang="fr" data-input="#search" data-anchor="header"
 *         data-results-url="https://www.example.com/catalogsearch/result/?q="></script>
 * data-native="keep"   : laisse fonctionner l'autocomplétion d'origine du site ; "block" : la neutralise
 *                        (sans attribut : réglage de l'extranet, Paramètres › Widget sur le site ; activé par défaut)
 * data-hide=".panneau" : sélecteurs CSS supplémentaires de panneaux d'origine à masquer
 * data-position="anchor" : panneau sous data-anchor (par défaut : juste sous la barre de recherche, avec data-gap px d'écart)
 * data-enter="off"     : la touche Entrée n'ouvre pas la page de résultats (sinon : réglage de l'extranet, Paramètres › Widget)
 * Le layer actif (extranet › Intégration) choisit l'affichage : below (sous la barre, par défaut), fullscreen, floating
 * (panneau centré), embedded (dans un bloc du site) ; il peut aussi remplacer data-input. Filtres et tris viennent aussi de l'extranet.
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
    blockNative: ds.native !== 'keep', // remplacé par le réglage de l'extranet si data-native est absent (voir applyCfg)
    hide: ds.hide || '',
    position: ds.position === 'anchor' ? 'anchor' : 'input',
    gap: parseInt(ds.gap || '12', 10),
    // Entrée → page de résultats : data-enter (on/off) prioritaire, sinon réglage renvoyé par l'API
    enter: ds.enter === 'off' ? false : (ds.enter === 'on' ? true : null),
    // Réglages de l'extranet (Intégration), reçus par /config : layer actif, filtres, tris
    type: 'below',
    container: '',
    urlState: false,
    filters: null,
    sorts: null,
    logo: '',
    colors: null,
    customCss: '',
    customCssMobile: '',
    cardTemplateDesktop: '',
    cardTemplateMobile: '',
    cardLink: '',
    maxWidth: 0,
    maxWidthUnit: '%',
    pageSizeMobile: 0,
    colsDesktop: 0,
    colsMobile: 2,
    hoverImage: true
  };
  var enterSubmit = cfg.enter !== null ? cfg.enter : true;
  var noAudience = ds.demo === '1'; // page Démo ou aperçu : pas de mesure d'audience (complété plus bas)
  // Panneaux d'autocomplétion connus (Magento Luma / Hyvä, Amasty, Mirasvit, Smile ElasticSuite) : masqués si présents
  var NATIVE_PANELS = '#search_autocomplete, .search-autocomplete, .amsearch-results, .amsearch-wrapper-block .amsearch-results, '
    + '.mst-searchautocomplete__autocomplete, .smile-elasticsuite-autocomplete-result';
  // Aperçu de l'extranet (data-render="1") : popin ou carrousel dessiné à partir des données de la fiche, sans API ni mesure
  var renderMode = ds.render === '1';
  if (!cfg.key && !renderMode) { console.warn('[DeepFind] data-key manquant'); return; }

  var T = {
    fr: { products: 'Produits', brands: 'Marques', posts: 'Articles de blog', filters: 'Filtres', inStock: 'En stock', onSale: 'En promotion',
      seeAll: 'Tout voir', sort: 'Trier', relevance: 'Pertinence', priceAsc: 'Prix croissant', priceDesc: 'Prix décroissant', newest: 'Nouveautés',
      none: 'Aucun résultat pour', tryOther: 'Vérifiez l\'orthographe ou essayez un terme plus général.', from: 'dès', outOfStock: 'Rupture',
      isNew: 'Nouveau', close: 'Fermer', busy: 'Trop de recherches en peu de temps, patientez quelques secondes…', error: 'Recherche momentanément indisponible.',
      goTo: 'Voir la page', closeFilters: 'Fermer les filtres', clear: 'Effacer les filtres', results: 'résultats', loading: 'Chargement…',
      approx: 'Aucun résultat exact pour « %s ». Produits approchants :', approxTitle: 'Produits approchants',
      price: 'Prix', min: 'Min.', max: 'Max.', apply: 'Valider', reset: 'Réinitialiser', search: 'Rechercher',
      pageLimited: 'Résultats limités à', pageRemove: 'Retirer le filtre « %s » et chercher dans tout le catalogue',
      pageAll: 'Résultats de tout le catalogue.', pageBack: 'Revenir à « %s »', popin: 'Message',
      carousel: 'Sélection de produits', prev: 'Produits précédents', next: 'Produits suivants', seeMore: 'Voir tout',
      freeShippingLeft: 'Plus que %s pour profiter de la livraison offerte', freeShippingDone: 'La livraison vous est offerte !' },
    en: { products: 'Products', brands: 'Brands', posts: 'Blog posts', filters: 'Filters', inStock: 'In stock', onSale: 'On sale',
      seeAll: 'See all', sort: 'Sort', relevance: 'Relevance', priceAsc: 'Price: low to high', priceDesc: 'Price: high to low', newest: 'Newest',
      none: 'No results for', tryOther: 'Check the spelling or try a more general term.', from: 'from', outOfStock: 'Out of stock',
      isNew: 'New', close: 'Close', busy: 'Too many searches, please wait a few seconds…', error: 'Search temporarily unavailable.',
      goTo: 'Go to page', closeFilters: 'Close filters', clear: 'Clear filters', results: 'results', loading: 'Loading…',
      approx: 'No exact match for “%s”. Similar products:', approxTitle: 'Similar products',
      price: 'Price', min: 'Min.', max: 'Max.', apply: 'Apply', reset: 'Reset', search: 'Search',
      pageLimited: 'Results limited to', pageRemove: 'Remove the “%s” filter and search the whole catalogue',
      pageAll: 'Results from the whole catalogue.', pageBack: 'Back to “%s”', popin: 'Message',
      carousel: 'Product selection', prev: 'Previous products', next: 'Next products', seeMore: 'See all',
      freeShippingLeft: 'Only %s away from free delivery', freeShippingDone: 'You get free delivery!' }
  };
  var t = T[cfg.lang] || T.fr;
  // Paramètres › Variables : textes modifiés pour la langue, variables personnalisées {{code}} (texte brut)
  var customVars = {};
  function applyTexts(c) {
    if (!c) { return; }
    if (c.texts && typeof c.texts === 'object') {
      Object.keys(c.texts).forEach(function (k) {
        if (Object.prototype.hasOwnProperty.call(t, k) && typeof c.texts[k] === 'string' && c.texts[k] !== '') { t[k] = c.texts[k]; }
      });
    }
    if (c.vars && typeof c.vars === 'object') {
      customVars = {};
      Object.keys(c.vars).forEach(function (k) { if (/^[a-z][a-z0-9_]{1,30}$/.test(k) && typeof c.vars[k] === 'string') { customVars[k] = c.vars[k]; } });
    }
  }
  // {{code}} d'une variable personnalisée dans un texte (titre de carrousel…) ; les codes inconnus restent tels quels
  function withVars(s) {
    return String(s || '').replace(/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/g, function (m, k) { return Object.prototype.hasOwnProperty.call(customVars, k) ? customVars[k] : m; });
  }
  var money;
  try { money = new Intl.NumberFormat(cfg.locale, { style: 'currency', currency: cfg.currency }); } catch (e) { money = { format: function (n) { return n.toFixed(2) + ' €'; } }; }
  var sid = (function () { var a = new Uint8Array(12); (window.crypto || window.msCrypto).getRandomValues(a); return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); })();

  // ------------------------------------------------------------------ Feuille de style
  var css = document.createElement('link');
  css.rel = 'stylesheet';
  css.href = base + '/widget/deepfind.css?v=29'; // ajoutée seulement si le projet est actif (voir démarrage en bas)

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

  // Barre de recherche du layer (plein écran, flottant) : le champ du site est alors masqué par la fenêtre
  var bar = el('div', 'df-bar');
  var barInput = el('input', 'df-bar-input');
  barInput.type = 'search';
  barInput.setAttribute('aria-label', t.search);
  barInput.placeholder = t.search;
  barInput.autocomplete = 'off';
  bar.appendChild(barInput);
  layer.appendChild(bar);

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
    var text = document.createTextNode(' ' + label);
    lab.appendChild(text);
    var count = el('span', 'df-count');
    lab.appendChild(count);
    return { label: lab, input: cb, count: count, text: text };
  }
  var tgStock = toggle('in_stock', t.inStock);
  var tgSale = toggle('on_sale', t.onSale);
  var sortSel = el('select', 'df-sort');
  sortSel.setAttribute('aria-label', t.sort);
  function setupSorts() {
    var list = [['relevance', t.relevance], ['price_asc', t.priceAsc], ['price_desc', t.priceDesc], ['newest', t.newest]];
    if (cfg.sorts && cfg.sorts.items) {
      list = [['relevance', cfg.sorts.relevance || t.relevance]].concat(cfg.sorts.items.map(function (o) { return [o.key, o.label]; }));
    }
    sortSel.textContent = '';
    list.forEach(function (o) { var opt = el('option', null, o[1]); opt.value = o[0]; sortSel.appendChild(opt); });
    sortSel.hidden = list.length < 2;
  }
  var seeAll = el('a', 'df-see-all');
  tools.appendChild(filtersBtn);
  tools.appendChild(sortSel);
  tools.appendChild(seeAll);
  head.appendChild(title);
  head.appendChild(tools);
  var notice = el('div', 'df-notice');
  notice.hidden = true;
  // Filtre pré-coché d'une règle selon la page (extranet › Recommandations › Règles) : le visiteur peut le retirer
  var pageBar = el('div', 'df-pagefilter');
  pageBar.hidden = true;
  var grid = el('div', 'df-grid');
  var empty = el('div', 'df-empty');
  empty.hidden = true;
  var loader = el('div', 'df-loader', t.loading);
  loader.hidden = true;
  var sentinel = el('div', 'df-sentinel');
  main.appendChild(head);
  main.appendChild(pageBar);
  main.appendChild(notice);
  var bannerBox = el('div', 'df-banner');
  bannerBox.hidden = true;
  main.appendChild(bannerBox);
  main.appendChild(grid);
  main.appendChild(empty);
  main.appendChild(loader);
  main.appendChild(sentinel);

  // ------------------------------------------------------------------ État
  var state = { pageOff: false, q: '', offset: 0, total: 0, hasMore: false, loading: false, reqId: 0, filters: {}, sort: 'relevance', redirect: null, redirectAuto: false, redirectNewTab: false, ids: {} };
  state.priceMin = '';
  state.priceMax = '';
  state.open = {};          // blocs de filtres dépliés / repliés par le visiteur (code => bool)
  state.priceBounds = null; // bornes du curseur de prix : celles de la recherche sans filtre de prix
  var controller = null;
  var cache = new Map();
  var timer = null;
  var activeInput = null;
  var mobile = window.matchMedia('(max-width: 900px)');
  // Mesure d'audience anonyme : type d'appareil (m = mobile, t = tablette, d = ordinateur), d'après l'écran
  var device = (function () {
    try {
      var coarse = window.matchMedia('(pointer: coarse)').matches;
      var side = Math.min(window.screen.width, window.screen.height);
      return coarse && side < 600 ? 'm' : (coarse && side < 1100 ? 't' : 'd');
    } catch (e) { return ''; }
  })();

  function resetFilters() { state.filters = {}; state.priceMin = ''; state.priceMax = ''; state.priceBounds = null; }

  function buildUrl(offset) {
    var p = new URLSearchParams();
    p.set('key', cfg.key);
    p.set('lang', cfg.lang);
    p.set('q', state.q);
    p.set('offset', String(offset));
    p.set('limit', String(mobile.matches && cfg.pageSizeMobile ? cfg.pageSizeMobile : cfg.pageSize));
    p.set('sid', sid);
    if (device) { p.set('d', device); }
    // page où se trouve le visiteur (chemin seul, sans paramètres) : règles selon la page
    p.set('page', location.pathname || '/');
    if (state.pageOff) { p.set('pr', '0'); }
    if (noAudience) { p.set('na', '1'); }
    if (offset > 0) { p.set('posts', '0'); }
    if (tgStock.input.checked) { p.set('in_stock', '1'); }
    if (tgSale.input.checked) { p.set('on_sale', '1'); }
    if (state.sort !== 'relevance') { p.set('sort', state.sort); }
    if (state.priceMin !== '') { p.set('price_min', state.priceMin); }
    if (state.priceMax !== '') { p.set('price_max', state.priceMax); }
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
    if (!append) { searchedSinceOpen = true; clickedSinceSearch = false; }
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
      if (!append) { saveUrlState(); }
    }).catch(function (err) {
      if (err && err.name === 'AbortError') { return; }
      if (id !== state.reqId) { return; }
      showNotice(err && err.busy ? t.busy : t.error, null);
    }).then(function () {
      if (id === state.reqId) { state.loading = false; loader.hidden = true; }
    });
  }

  function showNotice(text, href, newTab) {
    notice.textContent = '';
    if (!text) { notice.hidden = true; return; }
    if (href) {
      var a = el('a', null, text); a.href = href;
      if (newTab) { a.target = '_blank'; a.rel = 'noopener'; }
      notice.appendChild(a);
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
  // Contenu d'une bannière ou d'une popin dans box : HTML nettoyé (mode code) ou image ordinateur / mobile + lien. false si rien à afficher.
  function fillMedia(box, b, prefix) {
    if (b.mode === 'code') {
      // variables personnalisées : valeurs échappées avant l'analyse du HTML
      var html = String(b.html || '').replace(/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/g, function (m, k) { return Object.prototype.hasOwnProperty.call(customVars, k) ? esc(customVars[k]) : m; });
      var doc = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html');
      Array.prototype.forEach.call(doc.body.childNodes, function (c) { box.appendChild(cleanNode(c)); });
      return box.childNodes.length > 0;
    }
    var desktop = safeUrl(b.desktop);
    if (!desktop) { return false; }
    var pic = el('picture');
    var small = safeUrl(b.mobile);
    if (small && small !== desktop) {
      var srcEl = el('source');
      srcEl.media = '(max-width: 900px)';
      srcEl.srcset = small;
      pic.appendChild(srcEl);
    }
    var img = el('img', prefix + '-img');
    img.src = desktop;
    img.alt = b.alt || '';
    pic.appendChild(img);
    var link = safeUrl(b.link);
    var wrap = link ? el('a', prefix + '-link') : el('div');
    if (link) {
      wrap.href = link;
      if (b.new_window) { wrap.target = '_blank'; wrap.rel = 'noopener'; }
    }
    wrap.appendChild(pic);
    box.appendChild(wrap);
    return true;
  }
  function renderBanner(b) {
    bannerBox.textContent = '';
    bannerBox.hidden = !b || !fillMedia(bannerBox, b, 'df-banner');
    bannerBox.__dfBanner = b ? b.id : null;
  }
  // Clic sur un lien de la bannière (y compris clic molette) : mesuré comme les clics produits
  function bannerClick(ev) {
    if ((ev.type === 'auxclick' && ev.button !== 1) || !ev.target.closest || !ev.target.closest('a') || !bannerBox.__dfBanner) { return; }
    track('banner', bannerBox.__dfBanner, 0);
  }
  bannerBox.addEventListener('click', bannerClick);
  bannerBox.addEventListener('auxclick', bannerClick);

  function renderPageFilter(pf) {
    pageBar.textContent = '';
    pageBar.hidden = !pf;
    if (!pf) { return; }
    var label = String(pf.label || '');
    var btn = el('button', 'df-pagefilter-btn');
    btn.type = 'button';
    if (!pf.off) {
      pageBar.appendChild(el('span', 'df-pagefilter-text', t.pageLimited + ' '));
      btn.appendChild(el('span', null, label));
      btn.appendChild(el('span', 'df-pagefilter-x', '✕'));
      btn.setAttribute('aria-label', t.pageRemove.replace('%s', label));
      btn.title = t.pageRemove.replace('%s', label);
    } else {
      pageBar.appendChild(el('span', 'df-pagefilter-text', t.pageAll + ' '));
      btn.className = 'df-pagefilter-back';
      btn.textContent = t.pageBack.replace('%s', label);
    }
    btn.addEventListener('click', function () {
      state.pageOff = !pf.off;
      search(false);
    });
    pageBar.appendChild(btn);
  }

  function render(data, append) {
    state.total = data.total || 0;
    state.offset = data.next_offset || 0;
    state.hasMore = !!data.has_more;
    state.redirect = safeUrl(data.redirect);
    state.redirectAuto = !!data.redirect_auto;
    state.redirectNewTab = !!data.redirect_new_tab;
    if (!append) {
      renderBanner(data.banner || null);
      renderPageFilter(data.page_filter || null);
      grid.textContent = '';
      state.ids = {};
      main.scrollTop = 0;
      if (state.redirect) { showNotice(t.goTo + ' « ' + state.q + ' » →', state.redirect, state.redirectNewTab); }
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
      if (state.hasMore && !state.loading && needMore()) { search(true); }
    });
  }

  // Modèle de carte (extranet › Intégration › layer) : variables remplacées par des valeurs échappées,
  // puis HTML refiltré (mêmes règles que les bannières) ; pas de lien imbriqué, la carte entière est déjà un lien.
  function esc(v) { return String(v === undefined || v === null ? '' : v).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); }
  // Carte produit par défaut = ce modèle (identique à WidgetConfig::DEFAULT_CARD_TEMPLATE, affiché et modifiable dans l'extranet).
  // Un élément de classe df-if-<variable> est retiré quand la variable est vide ; img.df-img change d'image au survol.
  var DEFAULT_CARD = '<div class="df-media {{media_class}}">\n'
    + '  <img class="df-img df-if-image" src="{{image}}" alt="">\n'
    + '  <span class="df-badge df-badge-sale df-if-discount">{{discount}}</span>\n'
    + '  <span class="df-badge df-badge-new df-if-new">{{new}}</span>\n'
    + '  <span class="df-badge df-badge-oos df-if-out_of_stock">{{out_of_stock}}</span>\n'
    + '</div>\n'
    + '<div class="df-brand df-if-brand">{{brand}}</div>\n'
    + '<div class="df-name">{{name}}</div>\n'
    + '<div class="df-variant df-if-variant">{{variant}}</div>\n'
    + '<div class="df-price {{price_class}}">\n'
    + '  <span class="df-from df-if-from">{{from}}</span>\n'
    + '  <span class="df-final">{{price}}</span>\n'
    + '  <span class="df-old df-if-old_price">{{old_price}}</span>\n'
    + '</div>';

  // Modèle de carte : variables remplacées par des valeurs échappées, puis HTML refiltré (mêmes règles que les bannières) ;
  // pas de lien imbriqué, la carte entière est déjà un lien.
  function esc(v) { return String(v === undefined || v === null ? '' : v).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); }
  function cardVars(p) {
    var mv = p.matched_variant;
    var final = mv ? mv.final_price : p.final_price;
    var regular = mv ? mv.price : p.price;
    var discount = p.on_sale && p.discount_percent > 0 ? '-' + p.discount_percent + '%' : '';
    var image = safeUrl(p.image);
    var vars = {};
    Object.keys(customVars).forEach(function (k) { vars[k] = customVars[k]; });
    var sys = {
      name: p.name, brand: p.brand || '', image: image, price: fmt(final),
      // adresse de la page produit : celle du lien posé autour de la carte (déclinaison trouvée, sinon produit)
      url: safeUrl(mv && mv.url) || safeUrl(p.url),
      old_price: regular > final + 0.005 ? fmt(regular) : '', discount: discount,
      sku: (mv && mv.sku) || p.sku || '', variant: mv ? mv.sku || '' : '',
      stock: p.in_stock ? t.inStock : t.outOfStock, out_of_stock: p.in_stock ? '' : t.outOfStock,
      'new': p.is_new && !discount ? t.isNew : '', rating: p.rating || '',
      from: !mv && p.has_price_range ? t.from : '',
      price_class: p.on_sale ? 'df-sale' : '', media_class: image ? '' : 'df-noimg'
    };
    Object.keys(sys).forEach(function (k) { vars[k] = sys[k]; });
    return vars;
  }
  function templateCard(a, p, tpl) {
    var v = cardVars(p);
    var html = tpl.replace(/\{\{\s*([a-z_]+)\s*\}\}/g, function (m, k) { return Object.prototype.hasOwnProperty.call(v, k) ? esc(v[k]) : ''; });
    var doc = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html');
    Array.prototype.forEach.call(doc.body.childNodes, function (c) { a.appendChild(cleanNode(c)); });
    Array.prototype.forEach.call(a.querySelectorAll('[class*="df-if-"]'), function (n) {
      var keep = n.className.split(/\s+/).every(function (c) { return c.indexOf('df-if-') !== 0 || !!v[c.slice(6)]; });
      if (!keep) { n.remove(); }
    });
    Array.prototype.forEach.call(a.querySelectorAll('a'), function (n) {
      var span = el('span', n.className || null);
      while (n.firstChild) { span.appendChild(n.firstChild); }
      n.parentNode.replaceChild(span, n);
    });
    Array.prototype.forEach.call(a.querySelectorAll('img'), function (img) {
      if (!img.getAttribute('src')) { img.remove(); return; }
      img.loading = 'lazy';
      img.decoding = 'async';
      img.onerror = function () { var m = img.closest('.df-media'); img.remove(); if (m) { m.classList.add('df-noimg'); } };
    });
    // image au survol (img.df-img)
    var main = a.querySelector('img.df-img');
    var hover = safeUrl(p.image_hover);
    if (main && hover && cfg.hoverImage) {
      var src = main.src;
      a.addEventListener('mouseenter', function () { main.src = hover; });
      a.addEventListener('mouseleave', function () { main.src = src; });
    }
  }

  /*
   * Lien de la carte (Intégration › modèle de carte, ou carrousel) : {{url}} par défaut.
   * « {{url}}?utm_source=… » : paramètres ajoutés à l'adresse du produit (avant un éventuel #, sans doubler le ?) ;
   * « {{url_path}}… » : chemin seul, sur le domaine de la page ; variables {{sku}}, {{id}} encodées. Adresse non http(s) : lien par défaut.
   */
  function cardHref(p, tpl) {
    var base = safeUrl(p.matched_variant && p.matched_variant.url) || safeUrl(p.url);
    if (!tpl || tpl === '{{url}}') { return base || '#'; }
    var path = '';
    try { if (base) { path = new URL(base).pathname; } } catch (e) { path = ''; }
    var vars = { url: base, url_path: path, sku: (p.matched_variant && p.matched_variant.sku) || p.sku || '', id: p.id === undefined || p.id === null ? '' : String(p.id) };
    var fill = function (s, encodeAll) {
      return s.replace(/\{\{(url|url_path|sku|id)\}\}/g, function (x, k) { return encodeAll || k === 'sku' || k === 'id' ? encodeURIComponent(vars[k]) : vars[k]; });
    };
    var extra = /^\{\{url\}\}([?&].*)$/.exec(tpl);
    if (extra) {
      if (!base) { return '#'; }
      try {
        var u = new URL(base);
        new URLSearchParams(fill(extra[1].slice(1), true)).forEach(function (val, key) { u.searchParams.set(key, val); });
        return u.toString();
      } catch (e2) { return base; }
    }
    var out = fill(tpl, false);
    if (/^\/(?!\/)/.test(out)) { out = location.origin + out; }
    return safeUrl(out) || base || '#';
  }

  function card(p, position) {
    var href = cardHref(p, cfg.cardLink);
    var a = el('a', 'df-card' + (p.in_stock ? '' : ' df-oos'));
    a.href = href;
    var custom = (mobile.matches && cfg.cardTemplateMobile) || cfg.cardTemplateDesktop;
    if (custom) { a.classList.add('df-card-tpl'); }
    templateCard(a, p, custom || DEFAULT_CARD);
    onOpen(a, function () { track('product', p.id, position, p.cr, p.name); });
    return a;
  }

  function renderSide(data) {
    sideContent.textContent = '';
    var facets = data.facets || [];
    var hasFilter = tgStock.input.checked || tgSale.input.checked || state.priceMin !== '' || state.priceMax !== ''
      || Object.keys(state.filters).some(function (k) { return state.filters[k].length; });
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
    // Ordre des filtres choisi dans l'extranet (Intégration › Filtres) ; le prix y prend sa place
    var byCode = {};
    facets.forEach(function (f) { byCode[f.code] = f; });
    var ordered = [];
    var limits = {};
    var opens = {};
    if (cfg.filters) {
      // seuls les filtres ajoutés et actifs dans l'extranet, dans leur ordre
      cfg.filters.forEach(function (c) {
        if (!c.visible) { return; }
        if (c.limit) { limits[c.code] = c.limit; }
        opens[c.code] = c.open !== false;
        if (c.code === 'price') { ordered.push({ price: true, label: c.label }); }
        else if (byCode[c.code]) { ordered.push(byCode[c.code]); }
      });
    } else {
      ordered = facets;
    }
    ordered.forEach(function (f) {
      if (f.price) { renderPrice(f.label, data.price_range, opens.price); return; }
      var parts = section(f.code, f.label, '', opens[f.code]);
      var sec = parts.sec;
      var list = el('ul', 'df-facet');
      var max = limits[f.code] || (f.code === 'brand' ? 12 : 8);
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
      parts.body.appendChild(list);
      if (f.values.length > max) {
        var more = el('button', 'df-more', '+ ' + (f.values.length - max));
        more.type = 'button';
        more.addEventListener('click', function () {
          list.querySelectorAll('.df-more-item').forEach(function (n) { n.hidden = false; });
          more.remove();
        });
        parts.body.appendChild(more);
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
        onOpen(a, function () { track('post', p.id, i + 1, 0, p.title); });
        ps.appendChild(a);
      });
      sideContent.appendChild(ps);
    }
  }

  // Bloc de filtre repliable : titre cliquable ; état choisi par le visiteur gardé pendant la visite, sinon réglage de l'extranet
  var secSeq = 0;
  function section(code, label, cls, openByDefault) {
    var sec = el('div', 'df-sec' + (cls ? ' ' + cls : ''));
    var head = el('button', 'df-sec-head');
    head.type = 'button';
    head.appendChild(el('span', null, label));
    head.appendChild(el('span', 'df-chev'));
    var body = el('div', 'df-sec-body');
    body.id = 'df-sec-' + (++secSeq);
    head.setAttribute('aria-controls', body.id);
    var isOpen = state.open[code] !== undefined ? state.open[code] : openByDefault !== false;
    var apply = function () { head.setAttribute('aria-expanded', String(isOpen)); body.hidden = !isOpen; sec.classList.toggle('df-closed', !isOpen); };
    head.addEventListener('click', function () { isOpen = !isOpen; state.open[code] = isOpen; apply(); });
    apply();
    var h = el('h3', 'df-sec-title');
    h.appendChild(head);
    sec.appendChild(h);
    sec.appendChild(body);
    return { sec: sec, body: body };
  }

  // Filtre prix : deux champs (min / max) + curseur à deux poignées, bornes = prix de la recherche sans filtre de prix
  var currencySymbol = (function () {
    try { var p = new Intl.NumberFormat(cfg.locale, { style: 'currency', currency: cfg.currency }).formatToParts(0); for (var i = 0; i < p.length; i++) { if (p[i].type === 'currency') { return p[i].value; } } } catch (e) { /* ancien navigateur */ }
    return cfg.currency === 'EUR' ? '€' : cfg.currency;
  })();
  function renderPrice(label, range, openByDefault) {
    var filtered = state.priceMin !== '' || state.priceMax !== '';
    if (range && (!filtered || !state.priceBounds)) { state.priceBounds = { min: Math.floor(range.min), max: Math.ceil(range.max) }; }
    var bounds = state.priceBounds;
    if (!bounds && !filtered) { return; }
    if (bounds && bounds.max <= bounds.min && !filtered) { return; } // un seul prix : rien à filtrer
    var parts = section('price', label || t.price, 'df-price-sec', openByDefault);
    var lo0 = bounds ? bounds.min : 0;
    var hi0 = bounds ? Math.max(bounds.max, lo0 + 1) : 1000;
    var f = el('form', 'df-range');
    var fields = el('div', 'df-range-fields');
    var mk = function (name, val, ph) {
      var wrap = el('label', 'df-range-field');
      wrap.appendChild(el('span', 'df-range-label', name === 'min' ? t.min : t.max));
      var box = el('span', 'df-range-box');
      var i = el('input', 'df-range-input');
      i.type = 'number'; i.min = '0'; i.step = 'any'; i.inputMode = 'decimal'; i.name = name;
      i.value = val; i.placeholder = String(ph);
      i.setAttribute('aria-label', (label || t.price) + ' ' + (name === 'min' ? t.min : t.max));
      box.appendChild(i);
      box.appendChild(el('span', 'df-range-cur', currencySymbol));
      wrap.appendChild(box);
      fields.appendChild(wrap);
      return i;
    };
    var lo = mk('min', state.priceMin, lo0);
    var hi = mk('max', state.priceMax, hi0);
    f.appendChild(fields);
    // curseur : deux <input type="range"> superposés, la plage choisie est colorée
    var slider = el('div', 'df-slider');
    var fill = el('div', 'df-slider-fill');
    var mkRange = function (val, lbl) {
      var r = el('input', 'df-slider-input');
      r.type = 'range'; r.min = String(lo0); r.max = String(hi0); r.step = '1'; r.value = String(val);
      r.setAttribute('aria-label', (label || t.price) + ' ' + lbl);
      slider.appendChild(r);
      return r;
    };
    slider.appendChild(fill);
    var num = function (v, d) { v = parseFloat(String(v).replace(',', '.')); return isFinite(v) ? v : d; };
    var rLo = mkRange(Math.max(lo0, Math.min(hi0, num(state.priceMin, lo0))), t.min);
    var rHi = mkRange(Math.max(lo0, Math.min(hi0, num(state.priceMax, hi0))), t.max);
    var paint = function () {
      var span = hi0 - lo0;
      var a = (Number(rLo.value) - lo0) / span * 100;
      var b = (Number(rHi.value) - lo0) / span * 100;
      fill.style.left = a + '%';
      fill.style.width = Math.max(0, b - a) + '%';
    };
    rLo.addEventListener('input', function () {
      if (Number(rLo.value) > Number(rHi.value)) { rLo.value = rHi.value; }
      lo.value = Number(rLo.value) <= lo0 ? '' : rLo.value;
      paint();
    });
    rHi.addEventListener('input', function () {
      if (Number(rHi.value) < Number(rLo.value)) { rHi.value = rLo.value; }
      hi.value = Number(rHi.value) >= hi0 ? '' : rHi.value;
      paint();
    });
    lo.addEventListener('input', function () { rLo.value = String(Math.max(lo0, Math.min(Number(rHi.value), num(lo.value, lo0)))); paint(); });
    hi.addEventListener('input', function () { rHi.value = String(Math.min(hi0, Math.max(Number(rLo.value), num(hi.value, hi0)))); paint(); });
    paint();
    f.appendChild(slider);
    var btns = el('div', 'df-range-btns');
    var reset = el('button', 'df-range-reset', t.reset);
    reset.type = 'button';
    var ok = el('button', 'df-range-ok', t.apply);
    ok.type = 'submit';
    btns.appendChild(reset);
    btns.appendChild(ok);
    f.appendChild(btns);
    reset.addEventListener('click', function () {
      var had = state.priceMin !== '' || state.priceMax !== '';
      state.priceMin = ''; state.priceMax = '';
      lo.value = ''; hi.value = ''; rLo.value = String(lo0); rHi.value = String(hi0); paint();
      if (had) { search(false); }
    });
    f.addEventListener('submit', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      var clean = function (v) { v = String(v).replace(',', '.').trim(); return v !== '' && isFinite(v) && Number(v) >= 0 ? String(Number(v)) : ''; };
      var a = clean(lo.value), b = clean(hi.value);
      if (a !== '' && b !== '' && Number(a) > Number(b)) { var x = a; a = b; b = x; } // min et max inversés
      state.priceMin = a !== '' && Number(a) <= lo0 ? '' : a;
      state.priceMax = b !== '' && Number(b) >= hi0 ? '' : b;
      search(false);
    });
    parts.body.appendChild(f);
    sideContent.appendChild(parts.sec);
  }

  // Ouverture d'un lien : clic (y compris Ctrl/Cmd + clic) et clic molette (nouvel onglet)
  function onOpen(a, fn) {
    a.addEventListener('click', fn);
    a.addEventListener('auxclick', function (ev) { if (ev.button === 1) { fn(); } });
  }

  function track(type, id, position, cr, name) {
    if (type === 'product') { historyAdd(id); }
    clickedSinceSearch = true;
    try {
      var body = new URLSearchParams({ key: cfg.key, lang: cfg.lang, q: state.q, doc_type: type, doc_id: id, position: String(position), sid: sid, d: device });
      if (name) { body.set('n', String(name).slice(0, 200)); } // nom du produit (parcours)
      if (noAudience) { body.set('na', '1'); }
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
  function usesBar() { return cfg.type === 'fullscreen' || cfg.type === 'floating'; }
  function needMore() {
    if (cfg.type === 'embedded') { return sentinel.getBoundingClientRect().top < window.innerHeight + 600; }
    return main.scrollHeight <= main.clientHeight + 200;
  }
  function position() {
    if (cfg.type !== 'below') {
      overlay.style.setProperty('--df-top', '0px');
      backdrop.style.clipPath = '';
      ring.hidden = true;
      return;
    }
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
  // Layer intégré : les résultats prennent la place du contenu du bloc choisi (contenu masqué puis rendu à la fermeture)
  var host = null;
  // Bouton fermer : au-dessus de la fenêtre « sous la barre » quand il y a la place (sans toucher la barre de recherche)
  function placeClose() {
    var out = false;
    if (cfg.type === 'below' && !overlay.hidden) {
      var lr = layer.getBoundingClientRect();
      var spot = activeInput ? searchBox(activeInput).getBoundingClientRect() : null;
      var maxPx = !cfg.maxWidth ? 1440 : (cfg.maxWidthUnit === '%' ? window.innerWidth * cfg.maxWidth / 100 : cfg.maxWidth);
      var w = mobile.matches ? 10 : Math.max(16, (window.innerWidth - maxPx) / 2);
      var left = window.innerWidth - w - 40;
      out = lr.top >= 56 && (!spot || spot.right < left - 8 || spot.bottom < lr.top - 52 || spot.top > lr.top - 6);
    }
    // pastille d'aperçu (toujours en haut à droite) : le bouton fermer reste dans la fenêtre s'il la toucherait
    var badge = document.querySelector('.df-preview-bar');
    if (out && badge) {
      var br = badge.getBoundingClientRect();
      var top = layer.getBoundingClientRect().top - 50;
      if (top < br.bottom + 6) { out = false; }
    }
    var parent = out ? overlay : layer;
    if (closeBtn.parentNode !== parent) { parent.appendChild(closeBtn); }
    // bouton fermer dans la fenêtre mais sous la pastille (plein écran, flottant sur mobile) : décalé à sa gauche,
    // et la barre de recherche du layer garde la place du bouton
    closeBtn.style.right = '';
    bar.style.paddingRight = '';
    if (badge && !out && !overlay.hidden) {
      var b2 = badge.getBoundingClientRect();
      var c2 = closeBtn.getBoundingClientRect();
      if (c2.width && !(c2.right < b2.left || c2.left > b2.right || c2.bottom < b2.top || c2.top > b2.bottom)) {
        var right = Math.round(layer.getBoundingClientRect().right - b2.left + 8);
        closeBtn.style.right = right + 'px';
        if (usesBar()) { bar.style.paddingRight = (right + c2.width + 12) + 'px'; }
      }
    }
  }
  function open() {
    if (overlay.hidden) { barOpened(); }
    if (cfg.type === 'embedded') {
      host = host && host.isConnected ? host : document.querySelector(cfg.container);
      if (!host) { cfg.type = 'below'; overlay.className = 'df-overlay df-type-below'; } // bloc introuvable : affichage par défaut
    }
    if (cfg.type === 'embedded') {
      if (overlay.parentNode !== host) { host.appendChild(overlay); }
      if (overlay.hidden) {
        Array.prototype.forEach.call(host.children, function (c) {
          if (c === overlay || c.hasAttribute('data-df-hidden')) { return; }
          c.setAttribute('data-df-hidden', c.style.display);
          c.style.display = 'none';
        });
        overlay.hidden = false;
        var top = host.getBoundingClientRect().top;
        if (top < 0 || top > window.innerHeight * 0.5) { host.scrollIntoView({ block: 'start' }); }
      }
      return;
    }
    if (!overlay.parentNode || overlay.parentNode !== document.body) { document.body.appendChild(overlay); }
    position();
    if (overlay.hidden) {
      overlay.hidden = false;
      placeClose();
      document.documentElement.classList.add('df-lock');
      if (usesBar()) {
        barInput.value = activeInput ? activeInput.value : state.q;
        barInput.focus({ preventScroll: true });
        try { barInput.setSelectionRange(barInput.value.length, barInput.value.length); } catch (e) { /* type search */ }
      }
    }
  }
  function close() {
    var wasOpen = !overlay.hidden;
    // fenêtre fermée après une recherche, sans clic sur un résultat (parcours d'achat)
    if (wasOpen && searchedSinceOpen && !clickedSinceSearch && state.q.length >= cfg.minChars) { sendEvent('close', { q: state.q }); }
    searchedSinceOpen = false;
    overlay.hidden = true;
    placeClose();
    setFiltersOpen(false);
    document.documentElement.classList.remove('df-lock');
    if (host) {
      Array.prototype.forEach.call(host.querySelectorAll('[data-df-hidden]'), function (c) {
        c.style.display = c.getAttribute('data-df-hidden');
        c.removeAttribute('data-df-hidden');
      });
    }
    if (wasOpen && usesBar() && activeInput) { activeInput.value = barInput.value; }
    clearUrlState();
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
    if (ev.target !== barInput) { activeInput = ev.target; }
    var q = ev.target.value.trim();
    clearTimeout(timer);
    if (q.length < cfg.minChars) {
      if (!q && ev.target !== barInput) { close(); }
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
      if (state.redirect && state.redirectAuto && typed === state.q) { ev.preventDefault(); openRedirect(); return; }
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
  // Redirection DeepFind : nouvel onglet (si choisi et autorisé par le navigateur), sinon la page actuelle est remplacée
  function openRedirect() {
    if (state.redirectNewTab) {
      // pas d'option « noopener » ici : avec elle, window.open renvoie toujours null et on ne saurait pas si l'onglet est bloqué
      var w = window.open(state.redirect, '_blank');
      if (w) { try { w.opener = null; } catch (e) { /* ignoré */ } return; }
    }
    window.location.href = state.redirect;
  }

  function goToResults(input) {
    var q = input.value.trim();
    if (!q) { return false; }
    var url = '';
    if (state.redirect && state.redirectAuto && q === state.q) {
      openRedirect();
      return true;
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

  // ------------------------------------------------------------------ État dans l'URL (réglage du layer)
  // #df=<JSON> : recherche, tri, filtres ; remplacé sans créer d'entrée d'historique, retiré à la fermeture
  function saveUrlState() {
    if (!cfg.urlState || !window.history || !history.replaceState) { return; }
    var st = { q: state.q };
    if (state.sort !== 'relevance') { st.s = state.sort; }
    var f = {};
    Object.keys(state.filters).forEach(function (k) { if (state.filters[k].length) { f[k] = state.filters[k]; } });
    if (Object.keys(f).length) { st.f = f; }
    if (tgStock.input.checked) { st.st = 1; }
    if (tgSale.input.checked) { st.sa = 1; }
    if (state.priceMin !== '') { st.pmin = state.priceMin; }
    if (state.priceMax !== '') { st.pmax = state.priceMax; }
    try { history.replaceState(history.state, '', location.pathname + location.search + '#df=' + encodeURIComponent(JSON.stringify(st))); } catch (e) { /* ignoré */ }
  }
  function clearUrlState() {
    if (!cfg.urlState || location.hash.indexOf('#df=') !== 0) { return; }
    try { history.replaceState(history.state, '', location.pathname + location.search); } catch (e) { /* ignoré */ }
  }
  function restoreUrlState() {
    if (!cfg.urlState || location.hash.indexOf('#df=') !== 0) { return; }
    var st;
    try { st = JSON.parse(decodeURIComponent(location.hash.slice(4))); } catch (e) { return; }
    if (!st || typeof st.q !== 'string' || st.q.trim().length < cfg.minChars) { return; }
    state.q = st.q.trim().slice(0, 200);
    state.sort = typeof st.s === 'string' ? st.s : 'relevance';
    if (!Array.prototype.some.call(sortSel.options, function (o) { return o.value === state.sort; })) { state.sort = 'relevance'; }
    sortSel.value = state.sort;
    state.filters = {};
    if (st.f && typeof st.f === 'object') {
      Object.keys(st.f).forEach(function (k) { if (Array.isArray(st.f[k])) { state.filters[k] = st.f[k].map(String).slice(0, 50); } });
    }
    tgStock.input.checked = !!st.st;
    tgSale.input.checked = !!st.sa;
    state.priceMin = st.pmin ? String(Number(st.pmin) || '') : '';
    state.priceMax = st.pmax ? String(Number(st.pmax) || '') : '';
    activeInput = document.querySelector(cfg.input);
    if (activeInput) { activeInput.value = state.q; }
    open();
    search(false);
  }

  // Réglages de l'extranet appliqués à l'interface : libellés et visibilité de « En stock » / « En promotion », tris
  function applyDisplay() {
    overlay.className = 'df-overlay df-type-' + cfg.type;
    bar.hidden = !usesBar();
    if (cfg.maxWidth) { overlay.style.setProperty('--df-width', cfg.maxWidth + cfg.maxWidthUnit); }
    if (cfg.colsDesktop) { overlay.style.setProperty('--df-cols', String(cfg.colsDesktop)); overlay.classList.add('df-has-cols'); }
    overlay.style.setProperty('--df-cols-m', String(cfg.colsMobile || 2));
    var hex = function (c) { return typeof c === 'string' && /^#[0-9a-f]{6}$/i.test(c) ? c : ''; };
    if (cfg.colors) {
      if (hex(cfg.colors.primary)) { overlay.style.setProperty('--df-accent', cfg.colors.primary); }
      if (hex(cfg.colors.contrast)) { overlay.style.setProperty('--df-on-accent', cfg.colors.contrast); }
      if (hex(cfg.colors.text)) { overlay.style.setProperty('--df-text', cfg.colors.text); }
    }
    if (cfg.logo) {
      var logo = el('img', 'df-logo' + (usesBar() ? '' : ' df-logo-top'));
      logo.src = cfg.logo;
      logo.alt = '';
      logo.onerror = function () { logo.remove(); };
      if (usesBar()) { bar.insertBefore(logo, barInput); } else { main.insertBefore(logo, main.firstChild); }
    }
    // CSS de l'extranet, inséré comme texte (aucun HTML interprété) ; version mobile = écrans ≤ 900 px
    var css = cfg.customCssMobile
      ? (cfg.customCss ? '@media (min-width: 901px) {\n' + cfg.customCss + '\n}\n' : '') + '@media (max-width: 900px) {\n' + cfg.customCssMobile + '\n}'
      : cfg.customCss;
    if (css) {
      var st = document.createElement('style');
      st.setAttribute('data-deepfind', 'custom');
      st.textContent = css;
      document.head.appendChild(st);
    }
    setupSorts();
    if (cfg.filters) {
      // « En stock » / « En promotion » : masqués s'ils ne sont pas ajoutés (ou désactivés) dans l'extranet
      tgStock.label.hidden = true;
      tgSale.label.hidden = true;
      cfg.filters.forEach(function (f) {
        var tg = f.code === 'in_stock' ? tgStock : (f.code === 'on_sale' ? tgSale : null);
        if (!tg) { return; }
        tg.text.nodeValue = ' ' + f.label;
        tg.label.hidden = !f.visible;
      });
    }
  }

  // éléments créés avant la configuration : textes (éventuellement modifiés dans l'extranet) remis au démarrage
  function refreshTexts() {
    layer.setAttribute('aria-label', t.products);
    barInput.setAttribute('aria-label', t.search);
    barInput.placeholder = t.search;
    closeBtn.setAttribute('aria-label', t.close);
    sideHead.querySelector('h3').textContent = t.filters;
    sideCloseBtn.textContent = t.close;
    sideCloseBtn.setAttribute('aria-label', t.closeFilters);
    filtersBtn.textContent = t.filters;
    tgStock.text.nodeValue = ' ' + t.inStock;
    tgSale.text.nodeValue = ' ' + t.onSale;
    sortSel.setAttribute('aria-label', t.sort);
    loader.textContent = t.loading;
  }

  function init() {
    refreshTexts();
    applyDisplay();
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
    window.addEventListener('resize', function () { if (!overlay.hidden) { position(); placeClose(); } });
    tgStock.input.addEventListener('change', function () { search(false); });
    tgSale.input.addEventListener('change', function () { search(false); });
    sortSel.addEventListener('change', function () { state.sort = sortSel.value; search(false); });
    filtersBtn.addEventListener('click', function () { setFiltersOpen(true); });
    barInput.addEventListener('input', onInput);
    barInput.addEventListener('keydown', handlers.keydown);
    if ('IntersectionObserver' in window) {
      // Layer intégré : la page défile (et non le panneau)
      new IntersectionObserver(function (entries) {
        if (entries[0].isIntersecting && state.hasMore && !state.loading) { search(true); }
      }, { root: cfg.type === 'embedded' ? null : main, rootMargin: '600px 0px' }).observe(sentinel);
    } else {
      (cfg.type === 'embedded' ? window : main).addEventListener('scroll', function () {
        if (state.hasMore && !state.loading && needMore()) { search(true); }
      });
    }
    restoreUrlState();
    pageReady = true;
    schedulePopins();
    startCarousels();
  }

  // ------------------------------------------------------------------ Popins (extranet › Recommandations › Popins)
  /*
   * La liste des popins en cours arrive avec /config ; la popin de la page est choisie ici (aucune requête de plus par page vue) :
   * pages (toutes, accueil, adresses ; adresses exclues), appareil, puis la première de la liste qui n'a été ni fermée ni déjà vue.
   * Mémorisation dans le navigateur du visiteur, sans cookie ni identifiant : fermeture ou clic pendant N jours (localStorage),
   * « déjà vue » et « fermée » pendant la visite (sessionStorage). Une seule popin par page.
   * Aperçu (favori « Tester DeepFind ») : rien n'est mémorisé ni compté, la popin revient à chaque page.
   */
  var popinList = [];
  var popinTimer = null;
  var popinDone = false; // une popin déjà affichée sur cette page
  var pageReady = false;
  var POPIN_KEY = 'df-popin:' + cfg.key + ':' + cfg.lang + ':';
  // Chemin de la page comme côté serveur (règles selon la page) : décodé, en minuscules
  function currentPath() {
    var path = location.pathname || '/';
    try { path = decodeURIComponent(path); } catch (e) { /* encodage invalide : chemin brut */ }
    return path.toLowerCase();
  }
  // Accueil : « / » ou la racine d'une langue / d'une boutique Magento (« /fr », « /fr_fr »)
  function isHomePath(path) {
    path = path.replace(/\/index\.php$/i, '').replace(/\/+$/, '');
    return path === '' || /^\/[a-z]{2}([_-][a-z]{2})?$/.test(path);
  }
  function urlMatch(u, path) {
    var txt = u && typeof u.text === 'string' ? u.text.trim().toLowerCase() : '';
    if (!txt) { return false; }
    if (u.match === 'starts') { return path.indexOf(txt) === 0; }
    if (u.match === 'ends') { return path.length >= txt.length && path.slice(-txt.length) === txt; }
    // « /fr/nautisme » et « /fr/nautisme/ » désignent la même page
    if (u.match === 'equals') { return path.replace(/\/+$/, '') === txt.replace(/\/+$/, ''); }
    return path.indexOf(txt) !== -1;
  }
  function popinForPage(p, path) {
    if ((p.device === 'desktop' && mobile.matches) || (p.device === 'mobile' && !mobile.matches)) { return false; }
    if (!segmentMatch(p.segments)) { return false; }
    if ((p.exclude || []).some(function (u) { return urlMatch(u, path); })) { return false; }
    if (p.pages !== 'custom') { return true; }
    return (!!p.home && isHomePath(path)) || (p.urls || []).some(function (u) { return urlMatch(u, path); });
  }
  function popinMem(p) { return POPIN_KEY + p.id + ':' + (parseInt(p.rev, 10) || 1); }
  function popinHidden(p) {
    // fréquence « toujours » : affichée à chaque page, fermeture jamais mémorisée
    if (preview || renderMode || p.repeat === 'always') { return false; }
    var k = popinMem(p);
    try {
      var until = parseInt(window.localStorage.getItem(k) || '0', 10);
      if (until > Date.now()) { return true; }
      if (until) { window.localStorage.removeItem(k); } // mémorisation expirée
      if (window.sessionStorage.getItem(k + ':x')) { return true; }
      if (p.repeat !== 'page' && window.sessionStorage.getItem(k + ':v')) { return true; }
    } catch (e) { /* stockage indisponible : la popin s'affiche normalement */ }
    return false;
  }
  function popinRemember(p, flag) {
    if (preview || renderMode || p.repeat === 'always') { return; }
    var k = popinMem(p);
    try {
      window.sessionStorage.setItem(k + ':' + flag, '1');
      var days = parseInt(p.remember_days, 10) || 0;
      if (flag === 'x' && days > 0) { window.localStorage.setItem(k, String(Date.now() + days * 86400000)); }
    } catch (e) { /* stockage indisponible */ }
  }
  function popinEvent(p, e) {
    if (noAudience) { return; } // page Démo ou aperçu : non compté
    try {
      var body = new URLSearchParams({ key: cfg.key, lang: cfg.lang, id: String(p.id), e: e, d: device });
      if (!(navigator.sendBeacon && navigator.sendBeacon(cfg.api + '/popin', body))) {
        fetch(cfg.api + '/popin', { method: 'POST', body: body, keepalive: true, credentials: 'omit' }).catch(function () { /* sans importance */ });
      }
    } catch (e2) { /* mesure facultative */ }
  }
  function pickPopin() {
    var path = currentPath();
    for (var i = 0; i < popinList.length; i++) {
      var p = popinList[i];
      if (p && p.id && popinForPage(p, path) && !popinHidden(p)) { return p; }
    }
    return null;
  }
  function showPopin(p) {
    var placement = { center: 1, bottom_right: 1, bottom_left: 1 }[p.placement] ? p.placement : 'center';
    var modal = placement === 'center';
    var root = el('div', 'df-popin df-popin-' + placement);
    // cadre (fenêtre, croix de fermeture au-dessus, à l'extérieur) + boîte qui défile si le contenu est plus haut que l'écran
    var frame = el('div', 'df-popin-frame');
    frame.setAttribute('role', 'dialog');
    if (modal) { frame.setAttribute('aria-modal', 'true'); }
    frame.setAttribute('aria-label', p.alt || t.popin);
    frame.style.setProperty('--df-popin-w', Math.min(1200, Math.max(280, parseInt(p.max_width, 10) || 600)) + 'px');
    var box = el('div', 'df-popin-box');
    var minH = Math.min(1200, Math.max(0, parseInt(p.min_height, 10) || 0));
    if (minH) { box.style.setProperty('--df-popin-h', minH + 'px'); box.classList.add('df-popin-minh'); }
    var content = el('div', 'df-popin-content');
    if (!fillMedia(content, p, 'df-popin')) { return false; }
    if (p.mode === 'code') { content.classList.add('df-popin-html'); } // texte : marges intérieures
    var closeX = el('button', 'df-popin-close', '✕');
    closeX.type = 'button';
    closeX.setAttribute('aria-label', t.close);
    box.appendChild(content);
    frame.appendChild(closeX);
    frame.appendChild(box);
    var backdrop = modal ? el('div', 'df-popin-backdrop') : null;
    if (backdrop) { root.appendChild(backdrop); }
    root.appendChild(frame);
    var before = document.activeElement;
    var closed = false;
    function onKey(ev) { if (ev.key === 'Escape') { finish('x'); } }
    // e = x (fermeture) ou c (clic) : mémorisée dans les deux cas pour ne pas réapparaître.
    // Clic sur un lien : compté tout de suite (la page peut changer aussitôt), retiré juste après
    // (un lien retiré du document pendant son clic n'ouvrirait plus sa page).
    function finish(e) {
      if (closed) { return; }
      closed = true;
      popinRemember(p, 'x');
      popinEvent(p, e);
      document.removeEventListener('keydown', onKey, true);
      if (e === 'c') { setTimeout(function () { root.remove(); }, 0); return; }
      root.remove();
      if (modal && before && before.focus && document.contains(before)) { try { before.focus({ preventScroll: true }); } catch (e3) { /* ignoré */ } }
    }
    closeX.addEventListener('click', function () { finish('x'); });
    if (backdrop) { backdrop.addEventListener('click', function () { finish('x'); }); }
    var linkClick = function (ev) {
      if ((ev.type === 'auxclick' && ev.button !== 1) || !ev.target.closest || !ev.target.closest('a')) { return; }
      finish('c');
    };
    box.addEventListener('click', linkClick);
    box.addEventListener('auxclick', linkClick);
    document.addEventListener('keydown', onKey, true);
    document.body.appendChild(root);
    popinRemember(p, 'v');
    popinEvent(p, 'v');
    if (modal) { try { closeX.focus({ preventScroll: true }); } catch (e4) { closeX.focus(); } }
    return true;
  }
  function firePopin() {
    popinTimer = null;
    if (popinDone) { return; }
    // fenêtre de recherche ouverte : la popin attend qu'elle se referme
    if (!overlay.hidden) { popinTimer = setTimeout(firePopin, 2000); return; }
    var p = pickPopin(); // liste éventuellement rafraîchie pendant le délai
    if (p && showPopin(p)) { popinDone = true; }
  }
  function schedulePopins() {
    if (!pageReady || demo || popinDone || popinTimer !== null || !document.body) { return; }
    var p = pickPopin();
    if (p) { popinTimer = setTimeout(firePopin, Math.min(600, Math.max(0, parseInt(p.delay, 10) || 0)) * 1000); }
  }

  // ------------------------------------------------------------------ Carrousels de produits (extranet › Recommandations › Carrousels)
  /*
   * Si le projet a des carrousels actifs, le widget demande ceux de la page (/carousels : chemin de la page, SKU du produit
   * si le site l'indique, historique du visiteur s'il a consenti) et les insère à l'emplacement choisi (sélecteur CSS + position).
   * Cartes produit identiques à celles de la recherche (modèle et couleurs du layer actif) ; défilement au doigt, à la souris
   * (glisser), par les flèches, ou automatique. Mesure : carrousel vu à l'écran, produits vus, clics (sans identifiant).
   * Historique (« vus récemment », « vous aimerez aussi ») : dans le navigateur du visiteur seulement (localStorage), uniquement
   * si le réglage du projet l'autorise : « always », ou « consent » après DeepFind.consent(true) appelé par le bandeau cookies du site.
   */
  var carouselsWanted = false;
  var carouselsLoaded = false;
  var personalMode = 'consent';
  var CONSENT_KEY = 'df-consent';
  var HISTORY_KEY = 'df-hist:' + cfg.key + ':' + cfg.lang;
  function consentOk() {
    if (personalMode === 'always') { return true; }
    if (personalMode !== 'consent') { return false; }
    try { return window.localStorage.getItem(CONSENT_KEY) === '1'; } catch (e) { return false; }
  }
  function historyGet() {
    try {
      var list = JSON.parse(window.localStorage.getItem(HISTORY_KEY) || '[]');
      var limit = Date.now() - 30 * 86400000; // 30 jours
      return Array.isArray(list) ? list.filter(function (h) { return h && typeof h.id === 'string' && h.t > limit; }) : [];
    } catch (e) { return []; }
  }
  function historyAdd(id) {
    if (!id || !consentOk() || preview || renderMode) { return; }
    id = String(id);
    if (!/^[A-Za-z0-9_.:-]{1,64}$/.test(id)) { return; }
    try {
      var list = historyGet().filter(function (h) { return h.id !== id; });
      list.unshift({ id: id, t: Date.now() });
      window.localStorage.setItem(HISTORY_KEY, JSON.stringify(list.slice(0, 20)));
    } catch (e) { /* stockage indisponible */ }
  }
  // Consentement donné ou retiré par le site (bandeau cookies) : DeepFind.consent(true | false)
  function setConsent(on) {
    try {
      if (on) { window.localStorage.setItem(CONSENT_KEY, '1'); }
      else {
        window.localStorage.removeItem(CONSENT_KEY);
        Object.keys(window.localStorage).forEach(function (k) { if (k.indexOf('df-hist:') === 0) { window.localStorage.removeItem(k); } });
      }
    } catch (e) { /* stockage indisponible */ }
  }
  // SKU du produit de la page, si le site l'indique (<meta name="deepfind:sku" content="…"> ou data-df-sku) : sinon, l'adresse suffit
  function pageSku() {
    var m = document.querySelector('meta[name="deepfind:sku"]');
    var d = document.querySelector('[data-df-sku]');
    var v = (m && m.getAttribute('content')) || (d && d.getAttribute('data-df-sku')) || '';
    return String(v).trim().slice(0, 64);
  }
  function carouselEvent(c, e, ids, name) {
    if (noAudience || !ids.length) { return; }
    try {
      var body = new URLSearchParams({ key: cfg.key, lang: cfg.lang, id: String(c.id), e: e, d: device });
      if (name) { body.set('n', String(name).slice(0, 200)); }
      ids.forEach(function (id) { body.append('p[]', String(id)); });
      if (!(navigator.sendBeacon && navigator.sendBeacon(cfg.api + '/carousel', body))) {
        fetch(cfg.api + '/carousel', { method: 'POST', body: body, keepalive: true, credentials: 'omit' }).catch(function () { /* sans importance */ });
      }
    } catch (e2) { /* mesure facultative */ }
  }
  function startCarousels() {
    if (!carouselsWanted || carouselsLoaded || !pageReady || demo) { return; }
    carouselsLoaded = true;
    var p = new URLSearchParams({ key: cfg.key, lang: cfg.lang, page: location.pathname || '/' });
    var sku = pageSku();
    if (sku) { p.set('sku', sku); }
    // historique envoyé seulement avec le consentement (vide : sert à reconnaître le produit de la page pour l'historique)
    if (consentOk() && !preview) { p.set('h', historyGet().map(function (h) { return h.id; }).join(',')); }
    addSource(p);
    if (cartWanted) { addCart(p); }
    fetch(cfg.api + '/carousels?' + p.toString(), { credentials: 'omit', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)); })
      .then(function (d) {
        if (d.page_product) { historyAdd(d.page_product); }
        (d.carousels || []).forEach(placeCarousel);
      })
      .catch(function () { /* carrousels facultatifs */ });
  }
  // Emplacement : le bloc peut apparaître après le chargement (thèmes qui construisent la page en JavaScript) : attente 10 s au plus
  function placeCarousel(c) {
    if ((c.device === 'desktop' && mobile.matches) || (c.device === 'mobile' && !mobile.matches) || !c.products || !c.products.length) { return; }
    var find = function () { try { return document.querySelector(c.selector); } catch (e) { return null; } };
    var put = function (target) {
      if (document.querySelector('.df-carousel[data-df-carousel="' + c.id + '"]')) { return; }
      var box = renderCarousel(c);
      var mode = c.insert;
      if (mode === 'before') { target.parentNode.insertBefore(box, target); }
      else if (mode === 'after') { target.parentNode.insertBefore(box, target.nextSibling); }
      else if (mode === 'prepend') { target.insertBefore(box, target.firstChild); }
      else if (mode === 'replace') { target.textContent = ''; target.appendChild(box); }
      else { target.appendChild(box); }
      box.__dfStart();
    };
    var target = find();
    if (target) { put(target); return; }
    if (!window.MutationObserver) { return; }
    var obs = new MutationObserver(function () { var t0 = find(); if (t0) { obs.disconnect(); put(t0); } });
    obs.observe(document.body, { childList: true, subtree: true });
    setTimeout(function () { obs.disconnect(); }, 10000);
  }
  function renderCarousel(c) {
    var box = el('section', 'df-carousel df-carousel-' + c.id);
    box.setAttribute('data-df-carousel', String(c.id));
    box.setAttribute('aria-label', c.title || t.carousel);
    box.style.setProperty('--df-per-d', String(Math.min(8, Math.max(1, parseInt(c.per_view_desktop, 10) || 4))));
    box.style.setProperty('--df-per-m', String(Math.min(4, Math.max(1, parseInt(c.per_view_mobile, 10) || 2))));
    // couleurs du layer actif (comme la fenêtre de recherche)
    var hex = function (v) { return typeof v === 'string' && /^#[0-9a-f]{6}$/i.test(v) ? v : ''; };
    if (cfg.colors) {
      if (hex(cfg.colors.primary)) { box.style.setProperty('--df-accent', cfg.colors.primary); }
      if (hex(cfg.colors.contrast)) { box.style.setProperty('--df-on-accent', cfg.colors.contrast); }
      if (hex(cfg.colors.text)) { box.style.setProperty('--df-text', cfg.colors.text); }
    }
    if (c.css && !document.querySelector('style[data-deepfind="carousel-' + c.id + '"]')) {
      var st = document.createElement('style');
      st.setAttribute('data-deepfind', 'carousel-' + c.id);
      st.textContent = String(c.css); // texte CSS (aucun HTML interprété)
      document.head.appendChild(st);
    }
    var more = safeUrl(c.more_url) || (/^\/(?!\/)/.test(c.more_url || '') ? c.more_url : '');
    if (c.title || more) {
      var head = el('div', 'df-car-head');
      if (c.title) {
        // classes CSS ajoutées depuis l'extranet (ex. celles du thème) : lettres, chiffres, - _ : / [ ] . % # uniquement
        var own = typeof c.title_class === 'string' ? c.title_class.split(/\s+/).filter(function (x) { return /^[A-Za-z0-9_\-:\/\[\].%#!]{1,80}$/.test(x); }).slice(0, 20) : [];
        head.appendChild(el('h2', 'df-car-title' + (own.length ? ' df-car-title-own ' + own.join(' ') : ''), withVars(c.title)));
      }
      if (more) { var a = el('a', 'df-car-more', withVars(c.more_label) || t.seeMore); a.href = more; head.appendChild(a); }
      box.appendChild(head);
    }
    // page panier : « plus que X € pour la livraison offerte » (montants envoyés par le serveur, en centimes)
    if (c.free_shipping && c.free_shipping.threshold > 0) {
      var leftC = parseInt(c.free_shipping.left, 10) || 0;
      var fsBox = el('p', 'df-car-ship' + (leftC > 0 ? '' : ' df-car-ship-done'), leftC > 0 ? t.freeShippingLeft.replace('%s', fmt(leftC / 100)) : t.freeShippingDone);
      if (leftC > 0) {
        var bar = el('span', 'df-car-ship-bar');
        var fill = el('span', 'df-car-ship-fill');
        fill.style.width = Math.max(4, Math.min(100, Math.round(100 * (c.free_shipping.total || 0) / c.free_shipping.threshold))) + '%';
        bar.appendChild(fill);
        fsBox.appendChild(bar);
      }
      box.appendChild(fsBox);
    }
    var viewport = el('div', 'df-car-viewport');
    var track = el('div', 'df-car-track');
    track.setAttribute('role', 'list');
    var prev = el('button', 'df-car-btn df-car-prev');
    var next = el('button', 'df-car-btn df-car-next');
    // chevron fin (même dessin que les sliders du thème), dessiné en SVG sans HTML inséré ; couleur = currentColor
    var chevron = function (d) {
      var NS = 'http://www.w3.org/2000/svg';
      var svg = document.createElementNS(NS, 'svg');
      svg.setAttribute('viewBox', '0 0 24 32');
      svg.setAttribute('fill', 'none');
      svg.setAttribute('aria-hidden', 'true');
      svg.setAttribute('focusable', 'false');
      svg.setAttribute('class', 'df-car-chevron');
      var path = document.createElementNS(NS, 'path');
      path.setAttribute('d', d);
      path.setAttribute('stroke', 'currentColor');
      path.setAttribute('stroke-width', '2');
      path.setAttribute('stroke-linecap', 'round');
      path.setAttribute('stroke-linejoin', 'round');
      svg.appendChild(path);
      return svg;
    };
    prev.appendChild(chevron('M15 28L7 16 15 4'));
    next.appendChild(chevron('M9 4l8 12-8 12'));
    [[prev, t.prev], [next, t.next]].forEach(function (b) { b[0].type = 'button'; b[0].setAttribute('aria-label', b[1]); });
    var cards = [];
    c.products.forEach(function (p, i) {
      var a = el('a', 'df-card' + (p.in_stock ? '' : ' df-oos'));
      a.href = cardHref(p, c.card_link || cfg.cardLink); // lien du carrousel, sinon celui de la recherche
      a.setAttribute('role', 'listitem');
      // modèle du carrousel (mobile, sinon ordinateur), à défaut celui de la fenêtre de recherche
      var own = typeof c.card_template === 'string' ? c.card_template : '';
      var ownMobile = typeof c.card_template_mobile === 'string' ? c.card_template_mobile : '';
      var custom = mobile.matches ? (ownMobile || own || cfg.cardTemplateMobile || cfg.cardTemplateDesktop) : (own || cfg.cardTemplateDesktop);
      if (custom) { a.classList.add('df-card-tpl'); }
      templateCard(a, p, custom || DEFAULT_CARD);
      a.__dfId = String(p.id);
      onOpen(a, function () {
        if (a.__dfDragged) { return; }
        historyAdd(p.id);
        carouselEvent(c, 'c', [p.id], p.name);
      });
      cards.push(a);
      track.appendChild(a);
    });
    viewport.appendChild(prev);
    viewport.appendChild(track);
    viewport.appendChild(next);
    box.appendChild(viewport);

    // ---- flèches
    var page = function (dir) { track.scrollBy({ left: dir * track.clientWidth * 0.9, behavior: 'smooth' }); };
    prev.addEventListener('click', function () { page(-1); });
    next.addEventListener('click', function () { page(1); });
    var atEnd = function () { return track.scrollLeft + track.clientWidth >= track.scrollWidth - 4; };
    var sync = function () { prev.disabled = track.scrollLeft <= 4; next.disabled = atEnd(); };

    // ---- glisser à la souris (au doigt : défilement natif) ; un glissement n'ouvre pas le produit
    var drag = null;
    track.addEventListener('pointerdown', function (ev) {
      if (ev.pointerType !== 'mouse' || ev.button !== 0) { return; }
      drag = { x: ev.clientX, left: track.scrollLeft, moved: false };
    });
    window.addEventListener('pointermove', function (ev) {
      if (!drag) { return; }
      var dx = ev.clientX - drag.x;
      if (!drag.moved && Math.abs(dx) > 5) { drag.moved = true; track.classList.add('df-dragging'); }
      if (drag.moved) { track.scrollLeft = drag.left - dx; ev.preventDefault(); }
    });
    window.addEventListener('pointerup', function () {
      if (!drag) { return; }
      var moved = drag.moved;
      drag = null;
      track.classList.remove('df-dragging');
      if (moved) {
        cards.forEach(function (a) { a.__dfDragged = true; });
        setTimeout(function () { cards.forEach(function (a) { a.__dfDragged = false; }); }, 50);
      }
    });
    track.addEventListener('click', function (ev) { if (ev.target.closest && ev.target.closest('a') && ev.target.closest('a').__dfDragged) { ev.preventDefault(); } }, true);
    track.addEventListener('dragstart', function (ev) { ev.preventDefault(); }); // images et liens : pas de glisser-déposer du navigateur

    // ---- produits vus : visibles dans le bandeau au moment où il est à l'écran, puis au fil du défilement
    var seen = {};
    var visibleIds = function () {
      var r = track.getBoundingClientRect();
      return cards.filter(function (a) {
        var b = a.getBoundingClientRect();
        return b.right > r.left + b.width * 0.5 && b.left < r.right - b.width * 0.5;
      }).map(function (a) { return a.__dfId; }).filter(function (id) { if (seen[id]) { return false; } seen[id] = true; return true; });
    };
    var onScreen = false;
    var scrollTimer = null;
    track.addEventListener('scroll', function () {
      sync();
      clearTimeout(scrollTimer);
      scrollTimer = setTimeout(function () { if (onScreen) { carouselEvent(c, 'p', visibleIds()); } }, 600);
    }, { passive: true });

    // ---- défilement automatique (pas si le visiteur préfère moins d'animations ; en pause au survol, au toucher, hors écran)
    var auto = Math.max(0, parseInt(c.autoplay, 10) || 0);
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var paused = false;
    ['mouseenter', 'focusin', 'touchstart'].forEach(function (e) { box.addEventListener(e, function () { paused = true; }, { passive: true }); });
    ['mouseleave', 'focusout'].forEach(function (e) { box.addEventListener(e, function () { paused = false; }); });
    box.__dfStart = function () {
      sync();
      if ('IntersectionObserver' in window) {
        var counted = false;
        new IntersectionObserver(function (entries) {
          onScreen = entries[0].isIntersecting;
          if (onScreen && !counted) { counted = true; carouselEvent(c, 'v', visibleIds()); }
        }, { threshold: 0.5 }).observe(box);
      } else { onScreen = true; carouselEvent(c, 'v', visibleIds()); }
      if (auto && !reduced && cards.length > 1) {
        setInterval(function () {
          if (paused || !onScreen || document.hidden) { return; }
          if (atEnd()) { track.scrollTo({ left: 0, behavior: 'smooth' }); } else { page(1); }
        }, auto * 1000);
      }
      window.addEventListener('resize', sync);
    };
    return box;
  }

  // ------------------------------------------------------------------ Démarrage
  /*
   * Le widget ne prend la main (fenêtre de recherche, neutralisation de l'autocomplétion d'origine) que si l'API confirme
   * que le projet est actif. Projet désactivé dans l'extranet, ou API injoignable : rien n'est modifié, la recherche
   * d'origine du site fonctionne normalement. La réponse est gardée 5 min dans la session pour décider sans attendre.
   */
  var started = false;
  var cfgKey = 'df-cfg:' + cfg.key + ':' + cfg.lang;
  /*
   * Aperçu (favori « Tester DeepFind » de l'extranet) : jeton dans data-preview (widget ajouté par le favori) ou gardé
   * dans la session de l'onglet (widget déjà installé sur le site : le favori recharge la page). Seul ce navigateur voit
   * le layer testé ; un bandeau permet de quitter l'aperçu.
   */
  var PREVIEW_KEY = 'df-preview';
  var preview = '';
  // Page Démo de l'extranet (data-demo) : layer choisi dans la page, ni pastille ni mémorisation dans la session
  var demo = ds.demo === '1';
  try {
    if (demo) { preview = ds.preview || ''; }
    else {
      if (ds.preview) { window.sessionStorage.setItem(PREVIEW_KEY, ds.preview); }
      preview = ds.preview || window.sessionStorage.getItem(PREVIEW_KEY) || '';
    }
  } catch (e) { preview = ds.preview || ''; }
  if (preview && !/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9a-f]{24}$/.test(preview)) { preview = ''; }
  noAudience = demo || preview !== '';
  // consentement déclaré par le site avant le chargement du widget : window.deepfindConsent = true | false
  if (window.deepfindConsent === true || window.deepfindConsent === false) { setConsent(window.deepfindConsent); }
  // Logo DeepFind (loupe à cercles), dessiné en SVG sans HTML inséré
  function dfLogo() {
    var NS = 'http://www.w3.org/2000/svg';
    var mk = function (tag, attrs, parent) {
      var n = document.createElementNS(NS, tag);
      Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); });
      if (parent) { parent.appendChild(n); }
      return n;
    };
    var svg = mk('svg', { 'class': 'df-preview-logo', width: '20', height: '20', viewBox: '0 0 32 32', 'aria-hidden': 'true', focusable: 'false' });
    var grad = mk('linearGradient', { id: 'df-preview-g', x1: '0', y1: '0', x2: '1', y2: '1' }, mk('defs', {}, svg));
    mk('stop', { offset: '0', 'stop-color': '#ff4f8b' }, grad);
    mk('stop', { offset: '1', 'stop-color': '#ff6a5c' }, grad);
    var paint = 'url(#df-preview-g)';
    mk('circle', { cx: '13.5', cy: '13.5', r: '10', fill: 'none', stroke: paint, 'stroke-width': '3.4' }, svg);
    mk('circle', { cx: '13.5', cy: '13.5', r: '5.6', fill: 'none', stroke: paint, 'stroke-width': '1.6', opacity: '.6' }, svg);
    mk('circle', { cx: '13.5', cy: '13.5', r: '2.4', fill: paint }, svg);
    mk('path', { d: 'M21.2 21.2 27.6 27.6', stroke: paint, 'stroke-width': '4.2', 'stroke-linecap': 'round' }, svg);
    return svg;
  }
  // Pastille discrète, toujours en haut à droite (détail au survol)
  function previewBadge(detail, isError) {
    if (isError) { console.warn('[DeepFind] ' + detail); }
    var show = function () {
      var bar = el('div', 'df-preview-bar' + (isError ? ' df-preview-error' : ''));
      bar.setAttribute('role', 'status');
      bar.title = detail;
      bar.appendChild(dfLogo());
      bar.appendChild(el('span', 'df-preview-name', isError ? 'DeepFind : aperçu indisponible' : 'DeepFind'));
      var quit = el('button', 'df-preview-quit', 'Quitter l\'aperçu');
      quit.title = 'Quitter l\'aperçu';
      quit.setAttribute('aria-label', 'Quitter l\'aperçu DeepFind');
      quit.type = 'button';
      quit.addEventListener('click', function () {
        try { window.sessionStorage.removeItem(PREVIEW_KEY); } catch (e) { /* ignoré */ }
        window.location.reload();
      });
      bar.appendChild(quit);
      document.body.appendChild(bar);
    };
    if (!document.querySelector('link[href*="/widget/deepfind.css"]')) { document.head.appendChild(css); }
    if (document.body) { show(); } else { document.addEventListener('DOMContentLoaded', show); }
  }
  function readCfg() {
    if (preview) { return null; } // aperçu : configuration toujours relue
    try { var v = JSON.parse(window.sessionStorage.getItem(cfgKey) || 'null'); return v && Date.now() - v.t < 300000 ? v : null; } catch (e) { return null; }
  }
  function writeCfg(c) {
    if (preview) { return; } // ne pas mélanger l'aperçu avec la configuration du site
    try {
      window.sessionStorage.setItem(cfgKey, JSON.stringify({ enabled: !!c.enabled, enter_submit: c.enter_submit !== false, block_native: c.block_native !== false, hover_image: c.hover_image !== false, audience: c.audience || false,
        layer: c.layer || null, filters: c.filters || null, sorts: c.sorts || null, popins: Array.isArray(c.popins) ? c.popins : [],
        carousels: c.carousels === true, personal: typeof c.personal === 'string' ? c.personal : 'consent',
        journey: c.journey === true, events: c.events === true, commerce: c.commerce || false,
        segments: c.segments === true, segment: c.segment || null, cart_context: c.cart_context === true,
        texts: c.texts || null, vars: c.vars || null, t: Date.now() }));
    } catch (e) { /* stockage indisponible */ }
  }
  var TYPES = { below: 1, fullscreen: 1, floating: 1, embedded: 1 };
  function applyCfg(c) {
    if (!c) { return; }
    applyTexts(c);
    if (cfg.enter === null && c.enter_submit !== undefined) { enterSubmit = c.enter_submit !== false; }
    // photo au survol : réglage de l'extranet (Paramètres › Widget sur le site), appliqué aux cartes affichées ensuite
    if (c.hover_image !== undefined) { cfg.hoverImage = c.hover_image !== false; }
    // popins : la liste la plus récente sert au moment de l'affichage (après le délai)
    if (Array.isArray(c.popins)) { popinList = c.popins; schedulePopins(); }
    // carrousels : demandés une fois par page, dès que la configuration indique qu'il en existe
    if (typeof c.personal === 'string') { personalMode = c.personal; }
    if (c.carousels === true) { carouselsWanted = true; startCarousels(); }
    // parcours d'achat : événements (barre ouverte, fermeture sans clic), panier et commandes
    trackCfg = { events: c.events === true, journey: c.journey === true, commerce: !!c.commerce };
    // ciblage selon la provenance (popins, carrousels) et panier du visiteur (recommandations)
    segmentsWanted = c.segments === true;
    if (c.segment && typeof c.segment === 'object') { visitorSegment = c.segment; }
    cartWanted = c.cart_context === true;
    hookCommerce(c);
    // Affichage : pris en compte au démarrage seulement (une modification s'applique à la page suivante)
    if (started) { return; }
    if (ds.native !== 'keep' && ds.native !== 'block' && c.block_native !== undefined) { cfg.blockNative = c.block_native !== false; }
    if (ds.native === 'block') { cfg.blockNative = true; }
    var l = c.layer;
    if (l && TYPES[l.type]) {
      cfg.type = l.type;
      if (l.input && !demo) { cfg.input = l.input; } // page Démo : son propre champ de recherche
      cfg.container = l.container || '';
      cfg.urlState = !!l.url_state;
      if (l.type === 'below' && ds.gap === undefined && l.gap !== undefined) { cfg.gap = parseInt(l.gap, 10) || 0; }
      if (l.type === 'embedded' && !cfg.container) { cfg.type = 'below'; }
      if (ds.minChars === undefined && l.min_chars) { cfg.minChars = Math.max(1, parseInt(l.min_chars, 10) || 2); }
      cfg.logo = safeUrl(l.logo);
      cfg.colors = { primary: l.color_primary, contrast: l.color_contrast, text: l.color_text };
      // largeur maximale : % de l'écran (ou du bloc pour Intégré) ou pixels
      cfg.maxWidth = l.type !== 'fullscreen' && l.max_width > 0 ? parseInt(l.max_width, 10) : 0;
      cfg.maxWidthUnit = l.max_width_unit === 'px' ? 'px' : '%';
      // nombre de produits (Personnalisation avancée) : chargés à la fois (data-page-size prioritaire) et par ligne
      if (ds.pageSize === undefined) {
        if (l.per_page_desktop) { cfg.pageSize = Math.min(48, Math.max(4, parseInt(l.per_page_desktop, 10) || 20)); }
        if (l.per_page_mobile) { cfg.pageSizeMobile = Math.min(48, Math.max(4, parseInt(l.per_page_mobile, 10) || 20)); }
      }
      cfg.colsDesktop = Math.min(8, Math.max(0, parseInt(l.cols_desktop, 10) || 0));
      cfg.colsMobile = Math.min(3, Math.max(1, parseInt(l.cols_mobile, 10) || 2));
      cfg.customCss = typeof l.css === 'string' ? l.css : '';
      cfg.customCssMobile = typeof l.css_mobile === 'string' ? l.css_mobile : '';
      cfg.cardTemplateDesktop = typeof l.card_template === 'string' ? l.card_template : '';
      cfg.cardTemplateMobile = typeof l.card_template_mobile === 'string' ? l.card_template_mobile : '';
      cfg.cardLink = typeof l.card_link === 'string' ? l.card_link : '';
    }
    if (Array.isArray(c.filters)) { cfg.filters = c.filters; }
    if (c.sorts && Array.isArray(c.sorts.items)) { cfg.sorts = c.sorts; }
  }
  function fetchCfg() {
    var sp = new URLSearchParams();
    addSource(sp, true);
    return fetch(cfg.api + '/config?key=' + encodeURIComponent(cfg.key) + '&lang=' + encodeURIComponent(cfg.lang) + (preview ? '&preview=' + encodeURIComponent(preview) : '')
      + (sp.toString() ? '&' + sp.toString() : ''),
      { credentials: 'omit', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)); })
      .then(function (c) { writeCfg(c); applyCfg(c); return c; });
  }
  // ------------------------------------------------------------------ Provenance du visiteur (ciblage des popins et carrousels)
  // Lue sur la page d'ARRIVÉE uniquement (paramètres utm_*, clic publicitaire, site d'origine) ; rien n'est stocké sur l'appareil :
  // le serveur la garde pour la journée sous l'empreinte anonyme du visiteur.
  var segmentsWanted = false;
  var visitorSegment = null;
  var cartWanted = false;
  var landing = null;
  function landingSource() {
    if (landing) { return landing; }
    landing = {};
    try {
      var q = new URLSearchParams(location.search);
      [['s', 'utm_source'], ['m', 'utm_medium'], ['c', 'utm_campaign']].forEach(function (k) {
        var v = q.get(k[1]);
        if (v) { landing[k[0]] = v.slice(0, 100); }
      });
      if (['gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'dclid', 'li_fat_id'].some(function (k) { return q.get(k); })) { landing.ad = '1'; }
      if (document.referrer) {
        var host = new URL(document.referrer).hostname.replace(/^www\./, '');
        if (host && host !== location.hostname.replace(/^www\./, '')) { landing.r = host.slice(0, 100); }
      }
    } catch (e) { /* provenance inconnue */ }
    return landing;
  }
  // force : page d'arrivée, demande de configuration (le serveur ne garde la provenance que si un ciblage l'utilise)
  function addSource(p, force) {
    if (!force && !segmentsWanted && !(readCfg() || {}).segments) { return; }
    var src = landingSource();
    Object.keys(src).forEach(function (k) { p.set('src_' + k, src[k]); });
  }
  function segmentMatch(rule) {
    if (!rule || ((!rule.channels || !rule.channels.length) && (!rule.utm || !rule.utm.length))) { return true; }
    var s = visitorSegment || { channel: 'direct', s: '', m: '', c: '' };
    if (rule.channels && rule.channels.length && rule.channels.indexOf(s.channel) === -1) { return false; }
    return (rule.utm || []).every(function (u) {
      var v = String(s[u.field] || '').toLowerCase();
      if (u.op === 'equals') { return v === u.text; }
      if (u.op === 'not_contains') { return v.indexOf(u.text) === -1; }
      return v !== '' && v.indexOf(u.text) !== -1;
    });
  }
  // Panier du visiteur (recommandations « achetés ensemble », page panier) : donné par le site (DeepFind.cart) ou lu dans les données
  // que Magento garde déjà dans le navigateur (mage-cache-storage, section cart) ; seuls les SKU et le total sont envoyés.
  var siteCart = null;
  function cartData() {
    if (siteCart) { return siteCart; }
    try {
      var raw = window.localStorage.getItem('mage-cache-storage');
      var mc = raw ? JSON.parse(raw).cart : null;
      if (mc && Array.isArray(mc.items)) {
        return { items: mc.items.map(function (it) { return { sku: it.product_sku, qty: it.qty }; }),
          total: mc.subtotalAmount !== undefined ? mc.subtotalAmount : mc.subtotal_incl_tax };
      }
    } catch (e) { /* pas de panier Magento lisible */ }
    return null;
  }
  function addCart(p) {
    var cd = cartData();
    if (!cd) { return; }
    var skus = (cd.items || []).map(function (it) { return String(it.sku || '').trim(); }).filter(Boolean).slice(0, 30);
    p.set('ci', skus.join(','));
    if (cd.total !== undefined && cd.total !== null && cd.total !== '') { p.set('ct', String(cd.total)); }
    if (cd.freeShipping !== undefined && cd.freeShipping !== null && cd.freeShipping !== '') { p.set('fs', String(cd.freeShipping)); }
    else if (ds.freeShipping) { p.set('fs', ds.freeShipping); }
  }

  // ------------------------------------------------------------------ Parcours d'achat (sans cookie ni stockage sur l'appareil)
  // Le serveur reconnaît le visiteur pendant une journée par une empreinte anonyme ; rien n'est envoyé depuis la Démo ni en aperçu.
  var trackCfg = { events: false, journey: false, commerce: false };
  var searchedSinceOpen = false;
  var clickedSinceSearch = false;
  var lastBar = 0;
  function sendEvent(e, data) {
    if (noAudience || !trackCfg.events && e !== 'cart' && e !== 'order') { return; }
    if ((e === 'cart' || e === 'order') && !trackCfg.commerce) { return; }
    try {
      var body = new URLSearchParams({ key: cfg.key, lang: cfg.lang, e: e, d: device });
      Object.keys(data || {}).forEach(function (k) {
        var v = data[k];
        if (v === undefined || v === null || v === '') { return; }
        body.set(k, typeof v === 'object' ? JSON.stringify(v) : String(v).slice(0, 300));
      });
      if (!(navigator.sendBeacon && navigator.sendBeacon(cfg.api + '/event', body))) {
        fetch(cfg.api + '/event', { method: 'POST', body: body, keepalive: true, credentials: 'omit' }).catch(function () { /* sans importance */ });
      }
    } catch (err) { /* mesure facultative */ }
  }
  function barOpened() {
    var now = Date.now();
    if (now - lastBar < 10000) { return; } // ouvertures rapprochées : une seule
    lastBar = now;
    sendEvent('bar', {});
  }
  // Ajouts au panier et commandes : DeepFind.track() appelé par le site, événements GA4 du dataLayer, événement Magento (Luma)
  var lastCart = {};
  function cartAdd(sku, qty, price, name) {
    sku = String(sku || '').trim();
    if (!sku) { return; }
    qty = Math.max(1, parseInt(qty, 10) || 1);
    var k = sku + '|' + qty;
    var now = Date.now();
    if (lastCart[k] && now - lastCart[k] < 3000) { return; } // même ajout signalé deux fois (dataLayer et Magento)
    lastCart[k] = now;
    sendEvent('cart', { sku: sku, qty: qty, price: price, name: name });
  }
  function orderDone(o) {
    o = o || {};
    var items = (Array.isArray(o.items) ? o.items : []).slice(0, 100).map(function (it) {
      return { sku: String(it.sku || it.item_id || it.id || ''), qty: parseInt(it.qty || it.quantity, 10) || 1, price: it.price };
    });
    sendEvent('order', { tid: o.id || o.transaction_id || '', value: o.total !== undefined ? o.total : o.value, items: items });
  }
  function fromGa(name, ec) {
    ec = ec || {};
    var items = Array.isArray(ec.items) ? ec.items : [];
    if (name === 'add_to_cart') {
      items.forEach(function (it) { cartAdd(it.item_id || it.id, it.quantity, it.price, it.item_name || it.name); });
    } else if (name === 'purchase') {
      orderDone({ id: ec.transaction_id, value: ec.value, items: items });
    }
  }
  function readDl(entry, purchaseOnly) {
    if (!entry || typeof entry !== 'object') { return; }
    // gtag('event', 'purchase', {...}) : objet « arguments » ; Google Tag Manager : { event: 'purchase', ecommerce: {...} }
    if (entry.length !== undefined && entry[0] === 'event' && typeof entry[1] === 'string') {
      if (!purchaseOnly || entry[1] === 'purchase') { fromGa(entry[1], entry[2]); }
    } else if (typeof entry.event === 'string') {
      if (!purchaseOnly || entry.event === 'purchase') { fromGa(entry.event, entry.ecommerce); }
    }
  }
  var commerceHooked = false;
  function hookCommerce(c) {
    if (commerceHooked || noAudience || !c || !c.enabled || !c.commerce) { return; }
    commerceHooked = true;
    if (c.commerce.datalayer) {
      try {
        var dl = window.dataLayer = window.dataLayer || [];
        dl.forEach(function (entry) { readDl(entry, true); }); // commande déjà annoncée avant le chargement du widget
        var push = dl.push;
        dl.push = function () {
          var r = push.apply(dl, arguments);
          try { Array.prototype.forEach.call(arguments, function (entry) { readDl(entry, false); }); } catch (err) { /* sans importance */ }
          return r;
        };
      } catch (err) { /* dataLayer indisponible */ }
    }
    // Magento (thème Luma) : événement « ajax:addToCart » de jQuery
    try {
      if (window.jQuery) {
        window.jQuery(document).on('ajax:addToCart', function (ev, d) {
          d = d || {};
          var info = Array.isArray(d.productInfo) && d.productInfo[0] ? d.productInfo[0] : {};
          cartAdd(d.sku, info.qty, undefined, undefined);
        });
      }
    } catch (err) { /* sans importance */ }
  }
  // Pages où le titre peut contenir des informations du client : seul le chemin est envoyé
  var PRIVATE_PATH = /(customer|account|compte|checkout|sales|wishlist|password|login|connexion|paiement|payment)/i;

  // Page vue (mesure d'audience anonyme, une fois par page) : ni cookie ni donnée personnelle envoyés.
  // Jamais depuis la page Démo de l'extranet ni pendant un aperçu (favori « Tester DeepFind »).
  var visitSent = false;
  // Pages mesurées (réglage de l'extranet) : toutes, la page d'accueil, ou les adresses qui commencent par l'un des chemins choisis
  function audiencePage(a) {
    var path = (location.pathname || '/').replace(/\/index\.php$/i, '').replace(/\/+$/, '').toLowerCase();
    var home = isHomePath(path);
    if (a.pages === 'home') { return home; }
    if (a.pages === 'paths') {
      if (a.home && home) { return true; }
      // « commence par » : /fr/plongee couvre /fr/plongee.html et /fr/plongee/palmes.html
      return (a.paths || []).some(function (p) {
        p = typeof p === 'string' ? p.toLowerCase().replace(/\/+$/, '') : '';
        return p.length > 1 && path.indexOf(p) === 0;
      });
    }
    return true;
  }
  function sendVisit(c) {
    if (visitSent || noAudience || !c || !c.enabled) { return; }
    // page mesurée par l'audience, ou (parcours activés) toute page : le serveur ne garde que les visiteurs qui cherchent
    var counted = !!(c.audience && audiencePage(c.audience));
    var src = landingSource();
    var hasSrc = c.segments === true && Object.keys(src).length > 0;
    if (!counted && !c.journey && !hasSrc) { return; }
    visitSent = true;
    try {
      var path = location.pathname || '/';
      var body = new URLSearchParams({ key: cfg.key, lang: cfg.lang, d: device, p: path, a: counted ? '1' : '0' });
      if (c.journey && !PRIVATE_PATH.test(path)) { body.set('t', String(document.title || '').slice(0, 200)); }
      if (c.segments === true) { addSource(body); }
      var psku = pageSku();
      if (psku) { body.set('s', psku); } // produit de la page (« les visiteurs ont aussi regardé »)
      if (!(navigator.sendBeacon && navigator.sendBeacon(cfg.api + '/visit', body))) {
        fetch(cfg.api + '/visit', { method: 'POST', body: body, keepalive: true, credentials: 'omit' }).catch(function () { /* sans importance */ });
      }
    } catch (e) { /* mesure facultative */ }
  }
  function activate() {
    if (started) { return; }
    started = true;
    document.head.appendChild(css);
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
  }

  window.DeepFind = {
    config: cfg,
    preview: preview,
    isActive: function () { return started; },
    open: function (q) { if (!started) { return; } if (q) { state.q = q; resetFilters(); } open(); search(false); },
    close: close,
    // consentement du visiteur aux carrousels personnalisés (à appeler par le bandeau cookies du site) ; false efface l'historique
    consent: function (on) { setConsent(!!on); },
    // parcours d'achat, appelé par le site : DeepFind.track('cart', { sku, qty, price, name }) ;
    // DeepFind.track('order', { id, total, items: [{ sku, qty, price }] }) (numéro de commande : seulement pour ne pas compter deux fois)
    // panier du visiteur pour les recommandations : DeepFind.cart({ items: [{ sku, qty }], total: 42.5, freeShipping: 50 })
    cart: function (data) {
      siteCart = data && Array.isArray(data.items) ? { items: data.items.slice(0, 30), total: data.total, freeShipping: data.freeShipping } : null;
    },
    track: function (type, data) {
      data = data || {};
      if (type === 'cart') { cartAdd(data.sku, data.qty, data.price, data.name); } else if (type === 'order') { orderDone(data); }
    }
  };

  /*
   * Aperçu de l'extranet : DeepFind.render({ config, type: 'popin' | 'carousel', item, slot }) dessine la popin (sans délai) ou le
   * carrousel (dans l'élément slot) exactement comme sur le site, avec le modèle de carte et les couleurs du layer (config).
   * Rien n'est mémorisé ni compté ; aucune requête vers l'API.
   */
  if (renderMode) {
    noAudience = true;
    window.DeepFind.render = function (p) {
      if (!p || !p.item) { return; }
      if (!started) { applyCfg(p.config || null); started = true; document.head.appendChild(css); }
      Array.prototype.forEach.call(document.querySelectorAll('.df-popin, .df-carousel, style[data-deepfind^="carousel-"]'), function (n) { n.remove(); });
      if (p.type === 'popin') { showPopin(p.item); return; }
      var box = renderCarousel(p.item);
      (p.slot || document.body).appendChild(box);
      box.__dfStart();
    };
    return;
  }

  var known = readCfg();
  if (known) {
    applyCfg(known);
    if (known.enabled) { activate(); }
    sendVisit(known);
    fetchCfg().catch(function () { /* nouvel essai à la prochaine page */ });
  } else {
    fetchCfg().then(function (c) {
      if (preview && !demo) {
        if (!c.enabled) { previewBadge('Aperçu DeepFind impossible : le projet est désactivé dans l\'extranet.', true); }
        else if (c.preview && c.preview.layer) { previewBadge('Layer « ' + c.preview.layer + ' » — visible seulement dans ce navigateur', false); }
        else { previewBadge('Lien de test DeepFind expiré ou non valable pour ce site : recréez le favori dans l\'extranet. Affichage actuel du site.', true); }
      }
      sendVisit(c);
      if (c.enabled) { activate(); } else { console.info('[DeepFind] projet désactivé : la recherche du site reste inchangée'); }
    }).catch(function () {
      if (preview) { previewBadge('Aperçu DeepFind impossible : API injoignable, ou ce domaine n\'est pas autorisé pour la clé choisie.', true); }
      console.warn('[DeepFind] API injoignable : la recherche du site reste inchangée');
    });
  }
})();
