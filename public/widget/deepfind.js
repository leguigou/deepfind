/*!
 * DeepFind — widget de recherche instantanée (sans dépendance).
 * <script src="https://search.example.com/widget/deepfind.js" defer
 *         data-key="pk_…" data-lang="fr" data-input="#search" data-anchor="header"
 *         data-results-url="https://www.example.com/catalogsearch/result/?q="></script>
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
    debounce: parseInt(ds.debounce || '180', 10)
  };
  if (!cfg.key) { console.warn('[DeepFind] data-key manquant'); return; }

  var T = {
    fr: { products: 'Produits', brands: 'Marques', posts: 'Articles de blog', filters: 'Filtres', inStock: 'En stock', onSale: 'En promotion',
      seeAll: 'Tout voir', sort: 'Trier', relevance: 'Pertinence', priceAsc: 'Prix croissant', priceDesc: 'Prix décroissant', newest: 'Nouveautés',
      none: 'Aucun résultat pour', tryOther: 'Vérifiez l\'orthographe ou essayez un terme plus général.', from: 'dès', outOfStock: 'Rupture',
      isNew: 'Nouveau', close: 'Fermer', busy: 'Trop de recherches en peu de temps, patientez quelques secondes…', error: 'Recherche momentanément indisponible.',
      goTo: 'Voir la page', showFilters: 'Filtres', clear: 'Effacer les filtres', results: 'résultats', loading: 'Chargement…',
      approx: 'Aucun résultat exact pour « %s ». Produits approchants :', approxTitle: 'Produits approchants' },
    en: { products: 'Products', brands: 'Brands', posts: 'Blog posts', filters: 'Filters', inStock: 'In stock', onSale: 'On sale',
      seeAll: 'See all', sort: 'Sort', relevance: 'Relevance', priceAsc: 'Price: low to high', priceDesc: 'Price: high to low', newest: 'Newest',
      none: 'No results for', tryOther: 'Check the spelling or try a more general term.', from: 'from', outOfStock: 'Out of stock',
      isNew: 'New', close: 'Close', busy: 'Too many searches, please wait a few seconds…', error: 'Search temporarily unavailable.',
      goTo: 'Go to page', showFilters: 'Filters', clear: 'Clear filters', results: 'results', loading: 'Loading…',
      approx: 'No exact match for “%s”. Similar products:', approxTitle: 'Similar products' }
  };
  var t = T[cfg.lang] || T.fr;
  var money;
  try { money = new Intl.NumberFormat(cfg.locale, { style: 'currency', currency: cfg.currency }); } catch (e) { money = { format: function (n) { return n.toFixed(2) + ' €'; } }; }
  var sid = (function () { var a = new Uint8Array(12); (window.crypto || window.msCrypto).getRandomValues(a); return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); })();

  // ------------------------------------------------------------------ Feuille de style
  var css = document.createElement('link');
  css.rel = 'stylesheet';
  css.href = base + '/widget/deepfind.css?v=2';
  document.head.appendChild(css);

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
  var layer = el('div', 'df-layer');
  layer.setAttribute('role', 'dialog');
  layer.setAttribute('aria-label', t.products);
  overlay.appendChild(layer);

  var closeBtn = el('button', 'df-close', '×');
  closeBtn.type = 'button';
  closeBtn.setAttribute('aria-label', t.close);
  var side = el('aside', 'df-side');
  var main = el('div', 'df-main');
  layer.appendChild(closeBtn);
  layer.appendChild(side);
  layer.appendChild(main);

  var head = el('div', 'df-head');
  var title = el('h2', 'df-title');
  var tools = el('div', 'df-tools');
  var filtersBtn = el('button', 'df-filters-btn', t.showFilters);
  filtersBtn.type = 'button';
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
  tools.appendChild(tgStock.label);
  tools.appendChild(tgSale.label);
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
  main.appendChild(grid);
  main.appendChild(empty);
  main.appendChild(loader);
  main.appendChild(sentinel);

  // ------------------------------------------------------------------ État
  var state = { q: '', offset: 0, total: 0, hasMore: false, loading: false, reqId: 0, filters: {}, sort: 'relevance', redirect: null, ids: {} };
  var controller = null;
  var cache = new Map();
  var timer = null;
  var activeInput = null;

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
  function render(data, append) {
    state.total = data.total || 0;
    state.offset = data.next_offset || 0;
    state.hasMore = !!data.has_more;
    state.redirect = safeUrl(data.redirect);
    if (!append) {
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
    a.addEventListener('click', function () { track('product', p.id, position); });
    return a;
  }

  function renderSide(data) {
    side.textContent = '';
    var facets = data.facets || [];
    var hasFilter = Object.keys(state.filters).some(function (k) { return state.filters[k].length; });
    if (hasFilter) {
      var clear = el('button', 'df-clear', t.clear);
      clear.type = 'button';
      clear.addEventListener('click', function () { resetFilters(); search(false); });
      side.appendChild(clear);
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
      side.appendChild(sec);
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
        a.addEventListener('click', function () { track('post', p.id, i + 1); });
        ps.appendChild(a);
      });
      side.appendChild(ps);
    }
  }

  function track(type, id, position) {
    try {
      var body = new URLSearchParams({ key: cfg.key, lang: cfg.lang, q: state.q, doc_type: type, doc_id: id, position: String(position), sid: sid });
      if (navigator.sendBeacon) { navigator.sendBeacon(cfg.api + '/click', body); }
      else { fetch(cfg.api + '/click', { method: 'POST', body: body, keepalive: true, credentials: 'omit' }); }
    } catch (e) { /* sans importance */ }
  }

  // ------------------------------------------------------------------ Ouverture / fermeture
  function position() {
    var anchor = (cfg.anchor && document.querySelector(cfg.anchor)) || activeInput;
    var top = anchor ? Math.max(0, Math.round(anchor.getBoundingClientRect().bottom)) : 0;
    if (top > window.innerHeight * 0.6) { top = 0; }
    overlay.style.setProperty('--df-top', top + 'px');
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
    layer.classList.remove('df-side-open');
    document.documentElement.classList.remove('df-lock');
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

  function bind(input) {
    if (input.__df) { return; }
    input.__df = true;
    input.setAttribute('autocomplete', 'off');
    input.addEventListener('input', onInput);
    input.addEventListener('focus', function () {
      activeInput = input;
      if (input.value.trim().length >= cfg.minChars && state.q === input.value.trim() && grid.childNodes.length) { open(); }
    });
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { close(); }
      if (ev.key === 'Enter') {
        var q = input.value.trim();
        if (state.redirect && q === state.q) { ev.preventDefault(); window.location.href = state.redirect; return; }
        if (cfg.resultsUrl && q) { ev.preventDefault(); window.location.href = cfg.resultsUrl + encodeURIComponent(q); }
      }
    });
  }

  function init() {
    document.querySelectorAll(cfg.input).forEach(bind);
    // Champs ajoutés plus tard (thèmes Magento qui reconstruisent l'en-tête)
    if (window.MutationObserver) {
      new MutationObserver(function () { document.querySelectorAll(cfg.input).forEach(bind); })
        .observe(document.body, { childList: true, subtree: true });
    }
    closeBtn.addEventListener('click', close);
    overlay.addEventListener('mousedown', function (ev) { if (ev.target === overlay) { close(); } });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !overlay.hidden) { close(); } });
    window.addEventListener('resize', function () { if (!overlay.hidden) { position(); } });
    tgStock.input.addEventListener('change', function () { search(false); });
    tgSale.input.addEventListener('change', function () { search(false); });
    sortSel.addEventListener('change', function () { state.sort = sortSel.value; search(false); });
    filtersBtn.addEventListener('click', function () { layer.classList.toggle('df-side-open'); });
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

  window.DeepFind = {
    config: cfg,
    open: function (q) { if (q) { state.q = q; resetFilters(); } open(); search(false); },
    close: close
  };

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
