/* Aide › wiki : recherche dans toutes les pages (titres puis contenu, sans tenir compte des accents), rubriques sur mobile,
 * section en cours surlignée dans le sommaire. Résultats affichés avec textContent uniquement. */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var pages = [];
    try { pages = JSON.parse(document.getElementById('wk-index').textContent || '[]'); } catch (e) { pages = []; }
    function fold(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
    pages.forEach(function (p) { p.ft = fold(p.title); p.fx = fold(p.text); });
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }

    // ------------------------------------------------------------------ Recherche
    var input = document.querySelector('[data-wk-search]');
    var box = document.querySelector('[data-wk-results]');
    var active = -1;
    function excerpt(p, words) {
      var i = p.fx.indexOf(words[0]);
      if (i < 0) { return p.text.slice(0, 140) + '…'; }
      var start = Math.max(0, i - 60);
      return (start > 0 ? '…' : '') + p.text.slice(start, start + 160) + '…';
    }
    function search() {
      var q = fold(input.value.trim());
      box.textContent = '';
      active = -1;
      if (q.length < 2) { box.hidden = true; return; }
      var words = q.split(/\s+/).filter(Boolean);
      var hits = pages.map(function (p) {
        var inTitle = words.every(function (w) { return p.ft.indexOf(w) !== -1; });
        var inText = words.every(function (w) { return p.ft.indexOf(w) !== -1 || p.fx.indexOf(w) !== -1; });
        return { p: p, score: inTitle ? 2 : (inText ? 1 : 0) };
      }).filter(function (h) { return h.score > 0; }).sort(function (a, b) { return b.score - a.score; }).slice(0, 12);
      if (!hits.length) { box.appendChild(el('p', 'wk-noresult', 'Aucune page ne contient « ' + input.value.trim() + ' ».')); }
      hits.forEach(function (h) {
        var a = el('a', 'wk-result');
        a.href = h.p.url;
        a.setAttribute('role', 'option');
        a.appendChild(el('strong', null, h.p.title));
        if (h.p.trail) { a.appendChild(el('span', 'wk-result-trail', h.p.trail)); }
        a.appendChild(el('span', 'wk-result-text', excerpt(h.p, words)));
        box.appendChild(a);
      });
      box.hidden = false;
    }
    function move(d) {
      var items = box.querySelectorAll('.wk-result');
      if (!items.length) { return; }
      active = (active + d + items.length) % items.length;
      items.forEach(function (it, i) { it.classList.toggle('is-active', i === active); });
      items[active].scrollIntoView({ block: 'nearest' });
    }
    input.addEventListener('input', search);
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'ArrowDown') { ev.preventDefault(); move(1); }
      else if (ev.key === 'ArrowUp') { ev.preventDefault(); move(-1); }
      else if (ev.key === 'Enter') {
        var items = box.querySelectorAll('.wk-result');
        var target = items[active >= 0 ? active : 0];
        if (target) { ev.preventDefault(); window.location.href = target.href; }
      } else if (ev.key === 'Escape') { box.hidden = true; input.blur(); }
    });
    document.addEventListener('click', function (ev) { if (!ev.target.closest('.wk-search')) { box.hidden = true; } });
    // raccourci : « / » place le curseur dans la recherche
    document.addEventListener('keydown', function (ev) {
      if (ev.key === '/' && document.activeElement && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) { ev.preventDefault(); input.focus(); }
    });

    // ------------------------------------------------------------------ Rubriques sur mobile
    var nav = document.querySelector('[data-wk-nav]');
    var menuBtn = document.querySelector('[data-wk-menu]');
    menuBtn.addEventListener('click', function () {
      var open = !document.body.classList.contains('wk-nav-open');
      document.body.classList.toggle('wk-nav-open', open);
      menuBtn.setAttribute('aria-expanded', String(open));
    });
    var cur = nav.querySelector('.is-current');
    if (cur) { cur.scrollIntoView({ block: 'center' }); }

    // ------------------------------------------------------------------ Sommaire : section en cours
    var links = Array.prototype.slice.call(document.querySelectorAll('.wk-toc a'));
    if (links.length && 'IntersectionObserver' in window) {
      var byId = {};
      links.forEach(function (a) { byId[a.getAttribute('href').slice(1)] = a; });
      var obs = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting && byId[en.target.id]) {
            links.forEach(function (a) { a.classList.remove('is-active'); });
            byId[en.target.id].classList.add('is-active');
          }
        });
      }, { rootMargin: '0px 0px -70% 0px' });
      Object.keys(byId).forEach(function (id) { var h = document.getElementById(id); if (h) { obs.observe(h); } });
    }
  });
})();
