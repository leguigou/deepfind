/* Recommandations › Campagnes : choix des produits à pousser (recherche, liste de SKU collée, retrait). textContent uniquement. */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-campaign-form]');
    if (!form) { return; }
    var list = form.querySelector('[data-items-list]');
    var search = form.querySelector('[data-item-search]');
    var results = form.querySelector('[data-item-results]');
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined && text !== null) { n.textContent = String(text); } return n; }
    function money(v) { return v === null || v === undefined || v === '' ? '' : Number(v).toFixed(2).replace('.', ',') + ' €'; }
    function thumb(src) {
      var box = el('span', 'ca-thumb');
      if (/^https?:\/\//i.test(src || '')) { var im = el('img'); im.src = src; im.alt = ''; im.loading = 'lazy'; box.appendChild(im); }
      return box;
    }
    function skus() { return Array.prototype.map.call(list.querySelectorAll('[data-sku]'), function (li) { return li.getAttribute('data-sku'); }); }
    function has(sku) { return skus().some(function (s) { return s.toUpperCase() === String(sku).toUpperCase(); }); }
    function addItem(p) {
      if (!p.sku || has(p.sku)) { return; }
      var li = el('li', 'ca-item' + (p.missing ? ' ca-missing' : ''));
      li.setAttribute('data-sku', p.sku);
      li.appendChild(thumb(p.image));
      var txt = el('span', 'ca-item-text');
      txt.appendChild(el('strong', null, p.name || p.sku));
      txt.appendChild(el('small', 'muted', p.missing ? p.sku + ' · introuvable dans le catalogue'
        : p.sku + (p.brand ? ' · ' + p.brand : '') + (p.price !== undefined && p.price !== null ? ' · ' + money(p.price) : '') + (p.in_stock === false ? ' · rupture' : '')));
      li.appendChild(txt);
      var rm = el('button', 'icon-btn', '✕');
      rm.type = 'button';
      rm.setAttribute('aria-label', 'Retirer ' + (p.name || p.sku));
      rm.addEventListener('click', function () { li.remove(); });
      li.appendChild(rm);
      list.appendChild(li);
    }
    JSON.parse(form.getAttribute('data-items') || '[]').forEach(addItem);

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
            if (has(p.sku)) { b.disabled = true; }
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

    // liste de SKU collée (export, tableur)
    form.querySelector('[data-bulk-add]').addEventListener('click', function () {
      var area = form.querySelector('[data-bulk]');
      area.value.split(/[\s,;]+/).map(function (s) { return s.trim(); }).filter(Boolean).forEach(function (sku) { addItem({ sku: sku }); });
      area.value = '';
    });

    form.addEventListener('submit', function (ev) {
      var list0 = skus();
      if (!list0.length) { ev.preventDefault(); window.DeepFindUI.alert('Ajoutez au moins un produit à pousser.'); return; }
      form.querySelector('[data-items-field]').value = JSON.stringify(list0);
    });
  });
})();
