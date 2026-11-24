/* Aide › wiki : recherche dans toutes les pages (titres puis contenu, sans tenir compte des accents), rubriques sur mobile,
 * lecture continue d'une rubrique (page lue surlignée à gauche, adresse mise à jour), section en cours surlignée dans le sommaire. Résultats affichés avec textContent uniquement. */
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
        if (target) { ev.preventDefault(); target.click(); } // même rubrique : défilement ; sinon ouverture de la page
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
    function closeMenu() { document.body.classList.remove('wk-nav-open'); menuBtn.setAttribute('aria-expanded', 'false'); }
    menuBtn.addEventListener('click', function () {
      var open = !document.body.classList.contains('wk-nav-open');
      document.body.classList.toggle('wk-nav-open', open);
      menuBtn.setAttribute('aria-expanded', String(open));
    });
    // garde un lien de la colonne visible, sans faire défiler la page
    function revealInNav(a) {
      var r = a.getBoundingClientRect();
      var n = nav.getBoundingClientRect();
      if (r.top < n.top + 8 || r.bottom > n.bottom - 8) { nav.scrollTop += r.top - n.top - n.height / 3; }
    }
    var cur = nav.querySelector('.is-current');
    if (cur) { revealInNav(cur); }

    // ------------------------------------------------------------------ Lecture continue d'une rubrique
    // Toutes les pages de la rubrique se suivent : la page lue est surlignée à gauche, l'adresse et le titre de l'onglet suivent.
    var sections = Array.prototype.slice.call(document.querySelectorAll('.wk-section[data-wk-url]'));
    var navLinks = Array.prototype.slice.call(nav.querySelectorAll('[data-wk-section]'));
    var tocBox = document.querySelector('[data-wk-toc]');
    var activeId = null;
    function setActive(sec, updateUrl) {
      if (!sec || sec.id === activeId) { return; }
      activeId = sec.id;
      nav.querySelectorAll('.is-current').forEach(function (n) { n.classList.remove('is-current'); });
      nav.querySelectorAll('[aria-current]').forEach(function (n) { n.removeAttribute('aria-current'); });
      navLinks.forEach(function (a) {
        if (a.getAttribute('data-wk-section') !== sec.id) { return; }
        var holder = a.closest('summary') || a;
        holder.classList.add('is-current');
        a.setAttribute('aria-current', 'page');
        for (var d = a.parentElement; d && d !== nav; d = d.parentElement) { if (d.tagName === 'DETAILS') { d.open = true; } }
        revealInNav(a);
      });
      if (tocBox) {
        var any = false;
        tocBox.querySelectorAll('[data-wk-toc-for]').forEach(function (ul) {
          var on = ul.getAttribute('data-wk-toc-for') === sec.id;
          ul.hidden = !on;
          any = any || on;
        });
        tocBox.classList.toggle('is-empty', !any);
      }
      if (updateUrl && window.history && history.replaceState) {
        history.replaceState(null, '', sec.getAttribute('data-wk-url'));
      }
      document.title = sec.getAttribute('data-wk-title') + ' · Aide DeepFind';
    }
    function sectionInView() {
      var line = Math.min(window.innerHeight * 0.35, 320); // une page devient « lue » quand son titre passe le tiers haut de l'écran
      var found = sections[0];
      for (var i = 0; i < sections.length; i++) {
        if (sections[i].getBoundingClientRect().top <= line) { found = sections[i]; } else { break; }
      }
      // tout en haut : l'introduction de la rubrique (souvent courte) reste la page lue
      if (window.scrollY < 40) { return sections[0]; }
      // en bas de page, une dernière page courte ne peut pas atteindre le haut : elle devient la page lue
      if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) {
        var last = sections[sections.length - 1];
        if (last.getBoundingClientRect().top < window.innerHeight) { found = last; }
      }
      return found;
    }
    if (sections.length > 1) {
      var main = document.querySelector('[data-wk-start]');
      var start = main && document.getElementById(main.getAttribute('data-wk-start'));
      if (start && !location.hash) { start.scrollIntoView(); }
      var ticking = false;
      var ready = false; // l'adresse ne change qu'après le placement initial
      window.addEventListener('scroll', function () {
        if (ticking) { return; }
        ticking = true;
        window.requestAnimationFrame(function () { ticking = false; setActive(sectionInView(), ready); });
      }, { passive: true });
      setActive(start || sectionInView(), false);
      setTimeout(function () { ready = true; }, 300);
      // lien vers une page de la rubrique (colonne, résultats de recherche, liens du texte) : défilement sans recharger
      document.addEventListener('click', function (ev) {
        var a = ev.target.closest('a[href]');
        if (!a || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button !== 0) { return; }
        var url = new URL(a.href, location.href);
        if (url.origin !== location.origin) { return; }
        var target = null;
        sections.forEach(function (s) { if (s.getAttribute('data-wk-url') === url.pathname) { target = s; } });
        if (!target) { return; }
        ev.preventDefault();
        var dest = (url.hash && document.getElementById(decodeURIComponent(url.hash.slice(1)))) || target;
        closeMenu();
        box.hidden = true;
        activeId = null;
        setActive(target, true);
        dest.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    } else if (sections.length === 1 && tocBox) {
      tocBox.querySelectorAll('[data-wk-toc-for]').forEach(function (ul) { ul.hidden = false; });
    }

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
