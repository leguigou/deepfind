/*
 * Flux & mapping › source : bouton « Tester la connexion » (flux complet et delta, avec l'utilisateur / mot de passe
 * .htaccess et les en-têtes saisis, sans enregistrer). Le serveur ne lit que les premiers Ko du fichier.
 */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var box = document.querySelector('[data-src-access]');
    if (!box) { return; }
    var form = box.closest('form');
    var btn = box.querySelector('[data-src-check]');
    var out = box.querySelector('[data-src-check-results]');
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }

    btn.addEventListener('click', function () {
      var data = new FormData(form);
      data.delete('mapping'); // inutile pour le test
      btn.disabled = true;
      btn.textContent = 'Test en cours…';
      out.textContent = '';
      fetch(box.getAttribute('data-check-url'), { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) {
          var type = r.headers.get('Content-Type') || '';
          if (!r.ok || type.indexOf('application/json') === -1) { throw new Error('HTTP ' + r.status); }
          return r.json();
        })
        .then(function (d) {
          (d.results || []).forEach(function (res) {
            var item = el('div', 'src-result ' + (res.ok ? 'is-ok' : 'is-ko'));
            var head = el('div', 'src-result-head');
            head.appendChild(el('span', 'src-result-icon', res.ok ? '✓' : '✕'));
            head.appendChild(el('strong', null, res.label + ' : '));
            head.appendChild(document.createTextNode(' ' + res.message));
            item.appendChild(head);
            if (res.url) { item.appendChild(el('div', 'src-result-url muted small', res.url)); }
            if (res.details && res.details.length) {
              var ul = el('ul', 'src-result-details small');
              res.details.forEach(function (t) { ul.appendChild(el('li', t.indexOf('Attention') === 0 ? 'ko' : 'muted', t)); });
              item.appendChild(ul);
            }
            out.appendChild(item);
          });
          if (!(d.results || []).length) { out.appendChild(el('div', 'src-result is-ko', 'Saisissez l\'adresse du flux.')); }
        })
        .catch(function (e) {
          out.appendChild(el('div', 'src-result is-ko', 'Test impossible (' + e.message + ') : rechargez la page puis réessayez.'));
        })
        .then(function () {
          btn.disabled = false;
          btn.textContent = 'Tester la connexion';
        });
    });
  });
})();
