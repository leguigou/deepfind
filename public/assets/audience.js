/*
 * Analyse › Audience : courbe quotidienne (visiteurs / visiteurs qui ont cherché), en SVG, sans bibliothèque.
 * Une seule échelle (mêmes unités), ligne de repère et infobulle au survol ou au clavier (flèches).
 * Le tableau « Jour par jour » de la page sert de vue texte.
 */
(function () {
  'use strict';
  var NS = 'http://www.w3.org/2000/svg';
  var SERIES = [{ key: 'v', label: 'Visiteurs', cls: 'aud-s1' }, { key: 's', label: 'Visiteurs qui ont cherché', cls: 'aud-s2' }];
  var nf = new Intl.NumberFormat('fr-FR');
  var df = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short' });
  var dfLong = new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

  function mk(tag, attrs, parent) {
    var n = document.createElementNS(NS, tag);
    Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
    if (parent) { parent.appendChild(n); }
    return n;
  }
  function day(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
  // graduation « ronde » : 1, 2, 5 × 10^n
  function niceMax(v) {
    if (v <= 4) { return 4; }
    var step = Math.pow(10, Math.floor(Math.log10(v / 4)));
    var m = [1, 2, 2.5, 5, 10].map(function (k) { return k * step; }).filter(function (s) { return s * 4 >= v; })[0];
    return m * 4;
  }

  function render(box, data) {
    box.textContent = '';
    var W = Math.max(280, box.clientWidth), H = 260;
    var m = { t: 12, r: 16, b: 28, l: 48 };
    var iw = W - m.l - m.r, ih = H - m.t - m.b;
    var max = niceMax(Math.max.apply(null, data.map(function (d) { return d.v; }).concat([1])));
    var n = data.length;
    var x = function (i) { return m.l + (n === 1 ? iw / 2 : i * iw / (n - 1)); };
    var y = function (v) { return m.t + ih - v / max * ih; };

    var svg = mk('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, 'class': 'aud-svg', focusable: 'false', 'aria-hidden': 'true' }, box);
    // grille et axe des valeurs (discrets)
    for (var k = 0; k <= 4; k++) {
      var v = max * k / 4, yy = Math.round(y(v)) + 0.5;
      mk('line', { x1: m.l, x2: W - m.r, y1: yy, y2: yy, 'class': k === 0 ? 'aud-axis' : 'aud-grid' }, svg);
      mk('text', { x: m.l - 8, y: yy + 4, 'text-anchor': 'end', 'class': 'aud-tick' }, svg).textContent = nf.format(v);
    }
    // dates : 6 repères au plus
    var every = Math.max(1, Math.ceil(n / 6));
    data.forEach(function (d, i) {
      if (i % every !== 0 && i !== n - 1) { return; }
      if (i !== n - 1 && n - 1 - i < every * 0.6) { return; } // évite le chevauchement avec le dernier repère
      mk('text', { x: x(i), y: H - 8, 'text-anchor': n === 1 ? 'middle' : (i === 0 ? 'start' : (i === n - 1 ? 'end' : 'middle')), 'class': 'aud-tick' }, svg).textContent = df.format(day(d.d));
    });
    // courbes (2 px) ; un seul jour : des points
    SERIES.forEach(function (s) {
      if (n === 1) {
        mk('circle', { cx: x(0), cy: y(data[0][s.key]), r: 4, 'class': 'aud-dot ' + s.cls }, svg);
        return;
      }
      var dPath = data.map(function (d, i) { return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(d[s.key]).toFixed(1); }).join('');
      mk('path', { d: dPath, 'class': 'aud-line ' + s.cls }, svg);
    });

    // survol : ligne de repère, points et infobulle
    var cross = mk('line', { y1: m.t, y2: m.t + ih, 'class': 'aud-cross' }, svg);
    var dots = SERIES.map(function (s) { return mk('circle', { r: 5, 'class': 'aud-dot aud-hover ' + s.cls }, svg); });
    var tip = document.createElement('div');
    tip.className = 'aud-tip';
    tip.hidden = true;
    box.appendChild(tip);
    var current = -1;
    function show(i) {
      current = i;
      var d = data[i], cx = x(i);
      cross.setAttribute('x1', cx); cross.setAttribute('x2', cx);
      cross.style.visibility = 'visible';
      dots.forEach(function (c, j) { c.setAttribute('cx', cx); c.setAttribute('cy', y(d[SERIES[j].key])); c.style.visibility = 'visible'; });
      tip.textContent = '';
      var label = dfLong.format(day(d.d));
      var h = document.createElement('div'); h.className = 'aud-tip-day'; h.textContent = label.charAt(0).toUpperCase() + label.slice(1); tip.appendChild(h);
      SERIES.forEach(function (s) {
        var r = document.createElement('div'); r.className = 'aud-tip-row';
        var sw = document.createElement('span'); sw.className = 'aud-swatch ' + s.cls; r.appendChild(sw);
        r.appendChild(document.createTextNode(s.label + ' : '));
        var b = document.createElement('strong'); b.textContent = nf.format(d[s.key]); r.appendChild(b);
        tip.appendChild(r);
      });
      var share = document.createElement('div'); share.className = 'aud-tip-row aud-tip-muted';
      share.textContent = d.v > 0 ? Math.round(100 * d.s / d.v) + ' % des visiteurs ont cherché' : '';
      tip.appendChild(share);
      tip.hidden = false;
      var left = cx + 14, tw = tip.offsetWidth;
      if (left + tw > W) { left = cx - 14 - tw; }
      tip.style.left = Math.max(0, left) + 'px';
      tip.style.top = m.t + 'px';
    }
    function hide() {
      current = -1;
      cross.style.visibility = 'hidden';
      dots.forEach(function (c) { c.style.visibility = 'hidden'; });
      tip.hidden = true;
    }
    hide();
    // zone de survol plus grande que les traits
    var hit = mk('rect', { x: m.l - 8, y: 0, width: iw + 16, height: H, 'class': 'aud-hit' }, svg);
    function nearest(ev) {
      var r = svg.getBoundingClientRect();
      var px = (ev.clientX - r.left) * (W / r.width);
      return n === 1 ? 0 : Math.max(0, Math.min(n - 1, Math.round((px - m.l) / (iw / (n - 1)))));
    }
    hit.addEventListener('pointermove', function (ev) { show(nearest(ev)); });
    hit.addEventListener('pointerleave', hide);
    // clavier : la zone du graphique est focusable, flèches gauche / droite
    box.tabIndex = 0;
    box.onkeydown = function (ev) {
      if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') {
        ev.preventDefault();
        show(current < 0 ? n - 1 : Math.max(0, Math.min(n - 1, current + (ev.key === 'ArrowRight' ? 1 : -1))));
      } else if (ev.key === 'Escape') { hide(); }
    };
    box.onblur = hide;
  }

  // Réglages : adresses à saisir seulement pour « Pages choisies »
  var paths = document.querySelector('.aud-paths');
  var radios = document.querySelectorAll('.aud-pages input[name=pages]');
  function syncPaths() {
    var v = (document.querySelector('.aud-pages input[name=pages]:checked') || {}).value;
    if (paths) { paths.hidden = v !== 'paths'; }
  }
  radios.forEach(function (r) { r.addEventListener('change', syncPaths); });
  syncPaths();

  document.querySelectorAll('[data-audience-chart]').forEach(function (box) {
    var data;
    try { data = JSON.parse(box.getAttribute('data-audience-chart')) || []; } catch (e) { data = []; }
    if (!data.length) { return; }
    render(box, data);
    var w = box.clientWidth;
    if (window.ResizeObserver) {
      new ResizeObserver(function () { if (Math.abs(box.clientWidth - w) > 4) { w = box.clientWidth; render(box, data); } }).observe(box);
    }
  });
})();
