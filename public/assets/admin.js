/* Extranet DeepFind : thème clair/sombre, menu repliable, confirmations. */
(function () {
  'use strict';
  var root = document.documentElement;
  function read(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function write(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* stockage indisponible */ } }

  // Appliqué avant l'affichage pour éviter un flash de thème.
  var theme = read('df-theme');
  if (theme !== 'light' && theme !== 'dark') {
    theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  }
  root.setAttribute('data-theme', theme);
  if (read('df-menu') === 'collapsed') { root.classList.add('menu-collapsed'); }

  document.addEventListener('DOMContentLoaded', function () {
    function label() {
      document.querySelectorAll('[data-theme-label]').forEach(function (el) {
        el.textContent = root.getAttribute('data-theme') === 'light' ? 'clair' : 'sombre';
      });
    }
    label();
    document.querySelectorAll('[data-toggle-theme]').forEach(function (b) {
      b.addEventListener('click', function () {
        var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        root.setAttribute('data-theme', next);
        write('df-theme', next);
        label();
      });
    });
    document.querySelectorAll('[data-toggle-menu]').forEach(function (b) {
      b.addEventListener('click', function () {
        var collapsed = root.classList.toggle('menu-collapsed');
        write('df-menu', collapsed ? 'collapsed' : 'open');
      });
    });
    document.addEventListener('submit', function (ev) {
      var btn = ev.submitter;
      var msg = (btn && btn.getAttribute('data-confirm')) || ev.target.getAttribute('data-confirm');
      if (msg && !window.confirm(msg)) { ev.preventDefault(); }
    });
    document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
      el.addEventListener('change', function () { el.form.submit(); });
    });
    // Suivi des imports en cours (interrogation toutes les 2 s, arrêt quand tout est terminé)
    var progEls = document.querySelectorAll('[data-run-progress]');
    if (progEls.length && window.fetch) {
      var base = (document.querySelector('link[href*="/assets/admin.css"]') || {}).href || '';
      var statusUrl = base.replace(/\/assets\/admin\.css.*$/, '/admin/imports/status');
      var ids = Array.prototype.map.call(progEls, function (n) { return n.getAttribute('data-run-progress'); });
      var timer = setInterval(poll, 2000);
      poll();
      function poll() {
        fetch(statusUrl + '?ids=' + ids.join(','), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (data) {
            if (!data) { return; }
            var stillActive = false;
            data.runs.forEach(function (run) {
              document.querySelectorAll('[data-run-progress="' + run.id + '"]').forEach(function (box) {
                var bar = box.querySelector('.prog-bar span');
                if (bar) { bar.style.width = run.percent + '%'; }
                ['percent', 'label', 'detail', 'elapsed', 'eta'].forEach(function (f) {
                  var el = box.querySelector('[data-f="' + f + '"]');
                  if (el && run[f] !== null && run[f] !== undefined) { el.textContent = run[f]; }
                });
                var w = box.querySelector('[data-f-wrap="eta"]');
                if (w) { w.hidden = !run.eta; }
                var steps = box.querySelectorAll('[data-step]');
                var idx = -1;
                steps.forEach(function (st, i) { if (st.getAttribute('data-step') === run.phase) { idx = i; } });
                steps.forEach(function (st, i) {
                  st.className = run.status === 'success' || (idx >= 0 && i < idx) ? 'done' : (i === idx && run.active ? 'on' : '');
                });
              });
              if (run.active) { stillActive = true; }
            });
            if (!stillActive) {
              clearInterval(timer);
              setTimeout(function () { window.location.reload(); }, 800);
            }
          })
          .catch(function () { /* nouvel essai au prochain tour */ });
      }
    }

    document.querySelectorAll('[data-dim-row] input').forEach(function (cb) {
      cb.addEventListener('change', function () { var tr = cb.closest('tr'); if (tr) { tr.classList.toggle('dim', !cb.checked); } });
    });

    // Planification : un choix prédéfini remplit l'expression cron
    document.querySelectorAll('select[data-cron-preset]').forEach(function (sel) {
      var input = document.getElementById(sel.getAttribute('data-cron-preset'));
      sel.addEventListener('change', function () {
        if (sel.value !== '__custom') { input.value = sel.value; } else { input.focus(); }
      });
      input.addEventListener('input', function () {
        var match = Array.prototype.some.call(sel.options, function (o) { return o.value === input.value.trim(); });
        sel.value = match ? input.value.trim() : '__custom';
      });
    });
    // Mapping visuel : exemple de valeur du champ choisi
    function showSample(sel) {
      var opt = sel.options[sel.selectedIndex];
      var out = sel.parentNode.querySelector('[data-sample-out]');
      if (out) {
        var txt = opt && opt.getAttribute('data-sample') ? 'ex. ' + opt.getAttribute('data-sample') : '';
        var interp = opt && opt.getAttribute('data-interpret');
        out.textContent = txt + (interp ? '  →  ' + interp : '');
      }
      var row = sel.closest('tr');
      if (row && row.classList.contains('map-missing') && sel.value) { row.classList.remove('map-missing'); }
    }
    document.querySelectorAll('select[data-sample-select]').forEach(function (sel) {
      showSample(sel);
      sel.addEventListener('change', function () {
        showSample(sel);
        var badge = sel.parentNode.querySelector('.badge-auto');
        if (badge) { badge.remove(); }
      });
    });
    document.querySelectorAll('code.select').forEach(function (el) {
      el.addEventListener('click', function () {
        var r = document.createRange(); r.selectNodeContents(el);
        var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
      });
    });
  });
})();
