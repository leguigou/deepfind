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
    document.querySelectorAll('code.select').forEach(function (el) {
      el.addEventListener('click', function () {
        var r = document.createRange(); r.selectNodeContents(el);
        var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
      });
    });
  });
})();
