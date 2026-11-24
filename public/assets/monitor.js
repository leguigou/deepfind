/* Analyse › Temps réel & charge : indicateurs et graphiques (SVG dessinés ici, sans bibliothèque : la CSP de l'extranet
 * n'autorise que les scripts du site). Données : /admin/monitor/data, rafraîchies toutes les 15 s en mode « En direct »
 * (la fenêtre glisse avec le temps). Couleurs = variables CSS (thèmes clair et sombre) ; textes avec textContent uniquement. */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('[data-monitor]');
    if (!root) { return; }
    var NS = 'http://www.w3.org/2000/svg';
    var nf = new Intl.NumberFormat('fr-FR');
    var nf1 = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 });
    var DAYS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
    var state = { range: '1h', since: '', live: true, data: null, timer: null, busy: false };
    try { state.range = window.localStorage.getItem('df-mon-range') || '1h'; } catch (e) { /* ignoré */ }

    function v(name) { return getComputedStyle(root).getPropertyValue(name).trim(); }
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined && text !== null) { n.textContent = String(text); } return n; }
    function sv(tag, attrs, parent) {
      var n = document.createElementNS(NS, tag);
      Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
      if (parent) { parent.appendChild(n); }
      return n;
    }
    function num(x, dec) { return x === null || x === undefined ? '—' : (dec ? nf1 : nf).format(x); }
    function compact(x) { return Math.abs(x) >= 10000 ? nf1.format(x / 1000) + ' k' : nf.format(Math.round(x * 10) / 10); }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function niceMax(m) {
      if (!(m > 0)) { return 1; }
      var p = Math.pow(10, Math.floor(Math.log10(m)));
      var r = m / p;
      return (r <= 1 ? 1 : r <= 2 ? 2 : r <= 2.5 ? 2.5 : r <= 5 ? 5 : 10) * p;
    }
    function timeLabel(t, step, withDay) {
      var d = new Date(t * 1000);
      if (step >= 3600 || withDay) { return DAYS[d.getDay()] + ' ' + pad(d.getHours()) + 'h' + (step < 3600 ? pad(d.getMinutes()) : ''); }
      return pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    function fullTime(t, step) {
      var d = new Date(t * 1000);
      var e = new Date((t + step) * 1000);
      return DAYS[d.getDay()] + ' ' + pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + ', ' + pad(d.getHours()) + ':' + pad(d.getMinutes())
        + ' → ' + pad(e.getHours()) + ':' + pad(e.getMinutes());
    }

    // ------------------------------------------------------------------ Infobulle commune
    var tip = el('div', 'mon-tip');
    tip.hidden = true;
    tip.setAttribute('role', 'tooltip');
    document.body.appendChild(tip);
    function showTip(x, y, title, rows) {
      tip.textContent = '';
      tip.appendChild(el('strong', null, title));
      rows.forEach(function (r) {
        var line = el('div', 'mon-tip-row');
        if (r.color) { var sw = el('span', 'mon-sw'); sw.style.background = r.color; line.appendChild(sw); }
        line.appendChild(el('span', null, r.label));
        line.appendChild(el('b', null, r.value));
        tip.appendChild(line);
      });
      tip.hidden = false;
      var w = tip.offsetWidth;
      var h = tip.offsetHeight;
      var left = x + 14 + w > window.innerWidth - 8 ? x - w - 14 : x + 14;
      tip.style.left = Math.max(8, left) + 'px';
      tip.style.top = Math.max(8, Math.min(window.innerHeight - h - 8, y - h / 2)) + 'px';
    }
    function hideTip() { tip.hidden = true; }

    // ------------------------------------------------------------------ Courbes (une échelle, 1 à 3 séries)
    function lineChart(box, o) {
      box.textContent = '';
      var t = o.t;
      var n = t.length;
      var series = o.series;
      if (series.length > 1) {
        var legend = el('div', 'mon-legend');
        series.forEach(function (s) {
          var it = el('span', 'mon-legend-item');
          var key = el('span', 'mon-key');
          key.style.background = s.color;
          it.appendChild(key);
          it.appendChild(document.createTextNode(s.name));
          legend.appendChild(it);
        });
        box.appendChild(legend);
      }
      var W = Math.max(280, box.clientWidth);
      var H = o.height || 230;
      var endLabels = series.length > 1;
      var m = { l: 46, r: endLabels ? 96 : 18, t: 14, b: 26 };
      var all = [];
      series.forEach(function (s) { s.values.forEach(function (x) { if (x !== null && x !== undefined) { all.push(x); } }); });
      var max = niceMax(Math.max.apply(null, all.concat([o.minMax || 0, o.ref ? o.ref.value * 1.15 : 0, 0])));
      var px = function (i) { return m.l + (n <= 1 ? 0 : i * (W - m.l - m.r) / (n - 1)); };
      var py = function (x) { return m.t + (H - m.t - m.b) * (1 - x / max); };
      var svg = sv('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, class: 'mon-svg', role: 'img', 'aria-label': o.label || '' });
      // grille et graduations (4 intervalles)
      for (var k = 0; k <= 4; k++) {
        var val = max * k / 4;
        var yy = Math.round(py(val)) + 0.5;
        sv('line', { x1: m.l, x2: W - m.r, y1: yy, y2: yy, class: k === 0 ? 'mon-base' : 'mon-grid' }, svg);
        sv('text', { x: m.l - 8, y: yy + 4, 'text-anchor': 'end', class: 'mon-axis' }, svg).textContent = compact(val); // unité : dans le titre du graphique
      }
      var every = Math.max(1, Math.ceil(n / Math.max(2, Math.floor((W - m.l - m.r) / 90))));
      for (var i = 0; i < n; i += every) {
        sv('text', { x: px(i), y: H - 8, 'text-anchor': i === 0 ? 'start' : 'middle', class: 'mon-axis' }, svg).textContent = timeLabel(t[i], o.step);
      }
      if (o.ref) {
        var ry = Math.round(py(o.ref.value)) + 0.5;
        sv('line', { x1: m.l, x2: W - m.r, y1: ry, y2: ry, class: 'mon-ref' }, svg);
        sv('text', { x: W - m.r - 4, y: ry - 5, 'text-anchor': 'end', class: 'mon-axis' }, svg).textContent = o.ref.label;
      }
      var ends = [];
      series.forEach(function (s) {
        var d = '';
        var area = '';
        var open = false;
        var first = -1;
        var last = -1;
        s.values.forEach(function (x, i2) {
          if (x === null || x === undefined) { if (open && o.area) { area += 'L' + px(last) + ',' + py(0) + 'Z'; } open = false; return; }
          d += (open ? 'L' : 'M') + px(i2).toFixed(1) + ',' + py(x).toFixed(1);
          if (o.area) { area += (open ? 'L' : 'M' + px(i2).toFixed(1) + ',' + py(0) + 'L') + px(i2).toFixed(1) + ',' + py(x).toFixed(1); }
          if (first < 0) { first = i2; }
          open = true;
          last = i2;
        });
        if (open && o.area) { area += 'L' + px(last).toFixed(1) + ',' + py(0) + 'Z'; }
        if (o.area && area) { sv('path', { d: area, fill: s.color, 'fill-opacity': '0.12', stroke: 'none' }, svg); }
        if (d) { sv('path', { d: d, fill: 'none', stroke: s.color, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, svg); }
        if (last >= 0) {
          sv('circle', { cx: px(last), cy: py(s.values[last]), r: 4, fill: s.color, class: 'mon-ring' }, svg);
          ends.push({ s: s, y: py(s.values[last]), x: px(last) });
        }
      });
      // étiquettes en bout de courbe, seulement si elles ne se chevauchent pas (sinon : légende et infobulle)
      if (endLabels && ends.length) {
        var ys = ends.map(function (e) { return e.y; }).sort(function (a, b) { return a - b; });
        var clash = ys.some(function (y, j) { return j > 0 && y - ys[j - 1] < 15; });
        if (!clash) {
          ends.forEach(function (e) { sv('text', { x: e.x + 9, y: e.y + 4, class: 'mon-end' }, svg).textContent = e.s.name; });
        }
      }
      // survol : ligne verticale, points et infobulle
      var cross = sv('line', { y1: m.t, y2: H - m.b, class: 'mon-cross', visibility: 'hidden' }, svg);
      var dots = series.map(function (s) { return sv('circle', { r: 4, fill: s.color, class: 'mon-ring', visibility: 'hidden' }, svg); });
      var hit = sv('rect', { x: m.l, y: m.t, width: Math.max(1, W - m.l - m.r), height: H - m.t - m.b, fill: 'transparent' }, svg);
      function move(ev) {
        var r = svg.getBoundingClientRect();
        var i3 = n <= 1 ? 0 : Math.round((ev.clientX - r.left - m.l) / ((W - m.l - m.r) / (n - 1)));
        i3 = Math.max(0, Math.min(n - 1, i3));
        cross.setAttribute('x1', px(i3));
        cross.setAttribute('x2', px(i3));
        cross.setAttribute('visibility', 'visible');
        series.forEach(function (s, j) {
          var x = s.values[i3];
          dots[j].setAttribute('visibility', x === null || x === undefined ? 'hidden' : 'visible');
          if (x !== null && x !== undefined) { dots[j].setAttribute('cx', px(i3)); dots[j].setAttribute('cy', py(x)); }
        });
        showTip(ev.clientX, ev.clientY, fullTime(t[i3], o.step), series.map(function (s) {
          var x = s.values[i3];
          return { color: s.color, label: s.name, value: x === null || x === undefined ? '—' : num(x, true) + (o.unit ? ' ' + o.unit : '') };
        }));
      }
      hit.addEventListener('mousemove', move);
      hit.addEventListener('mouseleave', function () { cross.setAttribute('visibility', 'hidden'); dots.forEach(function (d0) { d0.setAttribute('visibility', 'hidden'); }); hideTip(); });
      box.appendChild(svg);
    }

    // ------------------------------------------------------------------ Colonnes (une série)
    function columnChart(box, o) {
      box.textContent = '';
      var W = Math.max(260, box.clientWidth);
      var H = 230;
      var m = { l: 46, r: 12, t: 22, b: 30 };
      var n = o.labels.length;
      var max = niceMax(Math.max.apply(null, o.values.concat([0])));
      var band = (W - m.l - m.r) / n;
      var bw = Math.min(24, band * 0.6);
      var py = function (x) { return m.t + (H - m.t - m.b) * (1 - x / max); };
      var svg = sv('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, class: 'mon-svg', role: 'img', 'aria-label': o.label || '' });
      for (var k = 0; k <= 4; k++) {
        var yy = Math.round(py(max * k / 4)) + 0.5;
        sv('line', { x1: m.l, x2: W - m.r, y1: yy, y2: yy, class: k === 0 ? 'mon-base' : 'mon-grid' }, svg);
        sv('text', { x: m.l - 8, y: yy + 4, 'text-anchor': 'end', class: 'mon-axis' }, svg).textContent = compact(max * k / 4);
      }
      o.values.forEach(function (x, i) {
        var cx = m.l + band * i + band / 2;
        var y0 = py(0);
        var y1 = py(x);
        var h = y0 - y1;
        if (h > 0.5) {
          var rr = Math.min(4, h, bw / 2);
          var x0 = cx - bw / 2;
          sv('path', { d: 'M' + x0 + ',' + y0 + 'V' + (y1 + rr) + 'Q' + x0 + ',' + y1 + ' ' + (x0 + rr) + ',' + y1 + 'H' + (x0 + bw - rr) + 'Q' + (x0 + bw) + ',' + y1 + ' ' + (x0 + bw) + ',' + (y1 + rr) + 'V' + y0 + 'Z', fill: o.color }, svg);
        }
        if (x > 0) { sv('text', { x: cx, y: y1 - 6, 'text-anchor': 'middle', class: 'mon-val' }, svg).textContent = compact(x); }
        sv('text', { x: cx, y: H - 10, 'text-anchor': 'middle', class: 'mon-axis' }, svg).textContent = o.labels[i];
        var hit = sv('rect', { x: cx - band / 2, y: m.t, width: band, height: H - m.t - m.b, fill: 'transparent' }, svg);
        hit.addEventListener('mousemove', function (ev) {
          var total = o.values.reduce(function (a, b) { return a + b; }, 0);
          showTip(ev.clientX, ev.clientY, o.full[i], [{ color: o.color, label: 'Recherches', value: num(x) + (total ? ' (' + nf1.format(100 * x / total) + ' %)' : '') }]);
        });
        hit.addEventListener('mouseleave', hideTip);
      });
      box.appendChild(svg);
    }

    // ------------------------------------------------------------------ Carte jour × heure (une teinte, du clair au foncé)
    function heatmap(box, rows) {
      box.textContent = '';
      var max = 0;
      rows.forEach(function (r) { r.hours.forEach(function (x) { max = Math.max(max, x); }); });
      var ramp = [1, 2, 3, 4, 5, 6, 7].map(function (k) { return v('--mon-seq-' + k); });
      var grid = el('div', 'mon-heat-grid');
      grid.appendChild(el('span'));
      for (var h = 0; h < 24; h++) { grid.appendChild(el('span', 'mon-heat-h', h % 3 === 0 ? h + 'h' : '')); }
      rows.forEach(function (r) {
        var d = new Date(r.day + 'T12:00:00');
        grid.appendChild(el('span', 'mon-heat-d', DAYS[d.getDay()] + ' ' + pad(d.getDate()) + '/' + pad(d.getMonth() + 1)));
        r.hours.forEach(function (x, hh) {
          var c = el('span', 'mon-heat-c');
          var idx = x <= 0 || max <= 0 ? -1 : Math.min(6, Math.floor(Math.sqrt(x / max) * 6.999));
          c.style.background = idx < 0 ? v('--mon-seq-0') : ramp[idx];
          c.addEventListener('mousemove', function (ev) {
            showTip(ev.clientX, ev.clientY, DAYS[d.getDay()] + ' ' + pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + ', ' + pad(hh) + 'h – ' + pad((hh + 1) % 24) + 'h',
              [{ label: 'Requêtes de recherche', value: num(x) }]);
          });
          c.addEventListener('mouseleave', hideTip);
          grid.appendChild(c);
        });
      });
      box.appendChild(grid);
      var leg = el('div', 'mon-heat-legend');
      leg.appendChild(el('span', 'muted small', '0'));
      var bar = el('span', 'mon-heat-bar');
      bar.style.background = 'linear-gradient(90deg, ' + [v('--mon-seq-0')].concat(ramp).join(', ') + ')';
      leg.appendChild(bar);
      leg.appendChild(el('span', 'muted small', num(max) + ' / heure'));
      box.appendChild(leg);
    }

    // ------------------------------------------------------------------ Petite courbe des indicateurs
    function spark(values, color) {
      var vals = values.filter(function (x) { return x !== null && x !== undefined; });
      var svg = sv('svg', { width: 96, height: 28, viewBox: '0 0 96 28', class: 'mon-spark', 'aria-hidden': 'true' });
      if (vals.length < 2) { return svg; }
      var max = Math.max.apply(null, vals.concat([1]));
      var n = values.length;
      var d = '';
      var lastX = 0;
      var lastY = 0;
      values.forEach(function (x, i) {
        if (x === null || x === undefined) { return; }
        lastX = 2 + i * 92 / (n - 1);
        lastY = 25 - 22 * x / max;
        d += (d ? 'L' : 'M') + lastX.toFixed(1) + ',' + lastY.toFixed(1);
      });
      sv('path', { d: d, fill: 'none', stroke: v('--mon-muted-line'), 'stroke-width': 1.5, 'stroke-linejoin': 'round' }, svg);
      sv('circle', { cx: lastX, cy: lastY, r: 3, fill: color }, svg);
      return svg;
    }

    // ------------------------------------------------------------------ Indicateurs clés
    function delta(cur, prev, upIsBad) {
      if (cur === null || prev === null || prev === undefined || cur === undefined || !(prev > 0)) { return null; }
      var p = (cur - prev) / prev * 100;
      if (Math.abs(p) < 0.5) { return { text: '= période précédente', cls: '' }; }
      var bad = upIsBad === null ? null : (p > 0) === upIsBad;
      return { text: (p > 0 ? '▲ +' : '▼ ') + nf1.format(p) + ' % vs période précédente', cls: bad === null ? '' : (bad ? 'is-bad' : 'is-good') };
    }
    function tile(o) {
      var t0 = el('div', 'mon-kpi' + (o.cls ? ' ' + o.cls : ''));
      var head = el('div', 'mon-kpi-head');
      head.appendChild(el('span', 'mon-kpi-label', o.label));
      if (o.live) { head.appendChild(el('span', 'mon-dot', '')); }
      t0.appendChild(head);
      var row = el('div', 'mon-kpi-row');
      var val = el('div', 'mon-kpi-value', o.value);
      if (o.unit) { val.appendChild(el('small', null, ' ' + o.unit)); }
      row.appendChild(val);
      if (o.spark) { row.appendChild(spark(o.spark, o.sparkColor || v('--mon-s1'))); }
      t0.appendChild(row);
      if (o.meter !== undefined && o.meter !== null) {
        var mt = el('div', 'mon-meter');
        var fill = el('span', o.meter >= 85 ? 'is-crit' : (o.meter >= 70 ? 'is-warn' : ''));
        fill.style.width = Math.min(100, Math.max(0, o.meter)) + '%';
        mt.appendChild(fill);
        t0.appendChild(mt);
      }
      if (o.sub) { t0.appendChild(el('div', 'mon-kpi-sub', o.sub)); }
      if (o.delta) { t0.appendChild(el('div', 'mon-kpi-delta ' + o.delta.cls, o.delta.text)); }
      return t0;
    }
    function renderKpis(d) {
      var box = root.querySelector('[data-kpis]');
      var k = d.kpi;
      var p = d.previous;
      var s = d.series;
      box.textContent = '';
      box.appendChild(tile({ label: 'Visiteurs actifs', live: true, value: num(k.active), sub: 'sur le site ces 5 dernières minutes', spark: s.visitors.slice(-30), sparkColor: v('--mon-s1') }));
      box.appendChild(tile({ label: 'Recherches par minute', value: num(k.searches_per_min, true), sub: 'moyenne des 5 dernières minutes (frappes)', spark: s.searches.slice(-30) }));
      box.appendChild(tile({ label: 'Temps de réponse moyen', value: num(k.avg_ms), unit: 'ms', sub: k.p95_ms === null ? 'aucune recherche sur la période' : '95 % des recherches en moins de ' + num(k.p95_ms) + ' ms',
        delta: delta(k.avg_ms, p.avg_ms, true), spark: s.avg_ms.slice(-30), cls: k.p95_ms >= 1000 ? 'is-alert' : '' }));
      box.appendChild(tile({ label: k.final_searches !== null ? 'Recherches' : 'Requêtes de recherche', value: num(k.final_searches !== null ? k.final_searches : k.searches),
        sub: k.final_searches !== null ? num(k.searches) + ' requêtes (frappes) · ' + num(k.cache_rate, true) + ' % depuis le cache' : 'journal des recherches désactivé : frappes comptées',
        delta: delta(k.searches, p.searches, null) }));
      box.appendChild(tile({ label: 'Pages vues', value: num(k.pageviews), sub: num(k.clicks) + ' clics sur un produit', delta: delta(k.pageviews, p.pageviews, null) }));
      box.appendChild(tile({ label: 'Requêtes API', value: num(k.requests), sub: num(k.req_per_min, true) + ' par minute en moyenne', delta: delta(k.requests, p.requests, null) }));
      box.appendChild(tile({ label: 'Charge du processeur', value: num(k.cpu_now, true), unit: '%', meter: k.cpu_now,
        sub: 'charge ' + nf1.format(k.load1) + ' pour ' + k.cpus + ' cœur' + (k.cpus > 1 ? 's' : '') + (k.mem_now !== null ? ' · mémoire ' + nf1.format(k.mem_now) + ' %' : ''),
        cls: k.cpu_now >= 85 ? 'is-alert' : '' }));
      box.appendChild(tile({ label: 'Erreurs', value: num(k.error_rate === null ? 0 : k.error_rate, true), unit: '%',
        sub: num(k.errors) + ' erreur' + (k.errors > 1 ? 's' : '') + ' serveur · ' + num(k.denied) + ' requête' + (k.denied > 1 ? 's' : '') + ' refusée' + (k.denied > 1 ? 's' : '') + ' (robots, limites)',
        cls: k.error_rate >= 1 ? 'is-alert' : '' }));
      box.appendChild(tile({ label: 'Recherches sans résultat', value: num(k.empty_rate === null ? 0 : k.empty_rate, true), unit: '%', sub: 'des requêtes de recherche de la période',
        delta: delta(k.empty_rate, p.empty_rate, true) }));
    }

    // ------------------------------------------------------------------ Tableau (accessibilité, export à l'œil)
    function renderTable(d) {
      var box = root.querySelector('[data-table]');
      box.textContent = '';
      var tb = el('table', 'table');
      var head = el('tr');
      ['Période', 'Recherches (frappes)', 'Pages vues', 'Clics', 'Visiteurs / min', 'Temps moyen (ms)', '95e percentile (ms)', 'Processeur (%)', 'Mémoire (%)', 'Erreurs', 'Refusées']
        .forEach(function (h) { head.appendChild(el('th', null, h)); });
      var thead = el('thead');
      thead.appendChild(head);
      tb.appendChild(thead);
      var body = el('tbody');
      var s = d.series;
      for (var i = s.t.length - 1; i >= 0; i--) {
        var tr = el('tr');
        [fullTime(s.t[i], d.step), num(s.searches[i]), num(s.pageviews[i]), num(s.clicks[i]), num(s.visitors[i], true), num(s.avg_ms[i]), num(s.p95_ms[i]),
          num(s.cpu[i], true), num(s.mem[i], true), num(s.errors[i]), num(s.denied[i])].forEach(function (c, j) { tr.appendChild(el('td', j ? 'num' : 'nowrap', c)); });
        body.appendChild(tr);
      }
      tb.appendChild(body);
      box.appendChild(tb);
    }

    // ------------------------------------------------------------------ Rendu complet
    var HIST = ['< 50 ms', '50–100', '100–200', '200–500', '0,5–1 s', '> 1 s'];
    var HIST_FULL = ['Moins de 50 ms', '50 à 100 ms', '100 à 200 ms', '200 à 500 ms', '0,5 à 1 seconde', 'Plus d\'1 seconde'];
    function render() {
      var d = state.data;
      if (!d) { return; }
      var s = d.series;
      renderKpis(d);
      var stepTxt = d.step >= 3600 ? 'heure' : (d.step / 60) + ' min';
      root.querySelector('[data-step]').textContent = 'un point par ' + (d.step >= 3600 ? stepTxt : 'tranche de ' + stepTxt);
      lineChart(root.querySelector('[data-chart=activity]'), { t: s.t, step: d.step, label: 'Activité sur le site', series: [
        { name: 'Recherches', color: v('--mon-s1'), values: s.searches },
        { name: 'Pages vues', color: v('--mon-s2'), values: s.pageviews },
        { name: 'Clics', color: v('--mon-s3'), values: s.clicks }] });
      lineChart(root.querySelector('[data-chart=visitors]'), { t: s.t, step: d.step, area: true, label: 'Visiteurs actifs',
        series: [{ name: 'Visiteurs', color: v('--mon-s1'), values: s.visitors }] });
      lineChart(root.querySelector('[data-chart=latency]'), { t: s.t, step: d.step, unit: 'ms', label: 'Temps de réponse', ref: { value: 500, label: '500 ms : lent' }, series: [
        { name: 'Moyenne', color: v('--mon-s1'), values: s.avg_ms },
        { name: '95e percentile', color: v('--mon-s2'), values: s.p95_ms }] });
      lineChart(root.querySelector('[data-chart=server]'), { t: s.t, step: d.step, unit: '%', minMax: 100, label: 'Charge du serveur', series: [
        { name: 'Processeur', color: v('--mon-s1'), values: s.cpu },
        { name: 'Mémoire', color: v('--mon-s2'), values: s.mem }] });
      var h = d.histogram;
      columnChart(root.querySelector('[data-chart=histogram]'), { labels: HIST, full: HIST_FULL, color: v('--mon-s1'), label: 'Répartition des temps de réponse',
        values: [h.h50, h.h100, h.h200, h.h500, h.h1000, h.hmore] });
      heatmap(root.querySelector('[data-chart=heatmap]'), d.heatmap);
      renderTable(d);
    }

    function load() {
      if (state.busy) { return; }
      state.busy = true;
      var q = state.since ? 'since=' + encodeURIComponent(state.since) : 'range=' + encodeURIComponent(state.range);
      fetch(root.getAttribute('data-url') + '?' + q, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)); })
        .then(function (d) {
          state.data = d;
          render();
          var at = new Date(d.generated_at * 1000);
          root.querySelector('[data-updated]').textContent = 'Mis à jour à ' + pad(at.getHours()) + ':' + pad(at.getMinutes()) + ':' + pad(at.getSeconds());
        })
        .catch(function () { root.querySelector('[data-updated]').textContent = 'Données indisponibles : nouvel essai dans 15 s.'; })
        .then(function () { state.busy = false; });
    }
    function schedule() {
      clearInterval(state.timer);
      if (state.live) { state.timer = setInterval(function () { if (!document.hidden) { load(); } }, 15000); }
      root.classList.toggle('is-live', state.live);
    }

    // ------------------------------------------------------------------ Filtres
    var chips = root.querySelectorAll('[data-range]');
    function syncChips() { chips.forEach(function (c) { c.setAttribute('aria-checked', !state.since && c.getAttribute('data-range') === state.range ? 'true' : 'false'); }); }
    chips.forEach(function (c) {
      c.addEventListener('click', function () {
        state.range = c.getAttribute('data-range');
        state.since = '';
        root.querySelector('[data-since]').value = '';
        try { window.localStorage.setItem('df-mon-range', state.range); } catch (e) { /* ignoré */ }
        syncChips();
        load();
      });
    });
    var since = root.querySelector('[data-since]');
    var min = new Date(Date.now() - 5 * 86400000);
    since.min = min.getFullYear() + '-' + pad(min.getMonth() + 1) + '-' + pad(min.getDate()) + 'T' + pad(min.getHours()) + ':' + pad(min.getMinutes());
    since.addEventListener('change', function () { state.since = since.value; syncChips(); load(); });
    root.querySelector('[data-live]').addEventListener('change', function (ev) { state.live = ev.target.checked; schedule(); if (state.live) { load(); } });
    document.addEventListener('visibilitychange', function () { if (!document.hidden && state.live) { load(); } });
    var rt = null;
    window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(render, 150); });
    // thème clair / sombre changé : couleurs relues
    new MutationObserver(function () { render(); }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    if (!document.querySelector('[data-range="' + state.range + '"]')) { state.range = '1h'; }
    syncChips();
    schedule();
    load();
  });
})();
