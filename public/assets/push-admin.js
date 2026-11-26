/* Notifications : réglages (champs selon la forme, copie du sw.js, aperçu ordinateur / mobile) et rédaction d'un message
 * (aperçu de la notification, nombre d'abonnés visés, confirmation d'envoi). textContent uniquement. */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    // ---------------------------------------------------------------- Paramètres › Notifications
    var ps = document.querySelector('[data-push-settings]');
    if (ps) {
      var form = function () { var r = ps.querySelector('input[name=push_form]:checked'); return r ? r.value : 'banner_bottom'; };
      var sync = function () {
        ps.querySelectorAll('[data-ps-show]').forEach(function (n) { n.hidden = n.getAttribute('data-ps-show').split(' ').indexOf(form()) === -1; });
      };
      ps.querySelectorAll('input[name=push_form]').forEach(function (r) { r.addEventListener('change', sync); });
      sync();
      var copy = ps.querySelector('[data-copy]');
      if (copy) {
        copy.addEventListener('click', function () {
          var txt = ps.querySelector('[data-copy-src]').textContent;
          if (navigator.clipboard) { navigator.clipboard.writeText(txt); }
          copy.textContent = 'Copié';
          setTimeout(function () { copy.textContent = 'Copier'; }, 1500);
        });
      }
      var val = function (n) { var f = ps.querySelector('[name="' + n + '"]'); return f ? f.value : ''; };
      if (window.DFLivePreview) {
        window.DFLivePreview({
          button: ps.querySelector('[data-live-preview]'), frameUrl: (ps.getAttribute('action') || '').replace(/\/admin\/push\/settings\/save$/, '/admin/live-preview?type=push'), type: 'push',
          load: function () {
            return Promise.resolve({ item: {
              form: form(), position: val('push_position'), title: val('push_title'), text: val('push_text'), accept: val('push_accept'), later: val('push_later'),
              image: val('push_image'), css: val('push_css'), colors: { bg: val('push_color_bg'), text: val('push_color_text'), button: val('push_color_button') }
            } });
          }
        });
      }
    }

    // ---------------------------------------------------------------- Recommandations › Envoi de notifications › message
    var pe = document.querySelector('[data-push-edit]');
    if (!pe) { return; }
    var out = function (k) { return document.querySelector('[data-pe-out="' + k + '"]'); };
    pe.querySelectorAll('[data-pe]').forEach(function (f) {
      var k = f.getAttribute('data-pe');
      var upd = function () {
        var o = out(k);
        if (k === 'image') {
          var ok = /^https?:\/\//i.test(f.value.trim());
          o.hidden = !ok;
          if (ok) { o.src = f.value.trim(); }
        } else {
          o.textContent = f.value || (k === 'title' ? 'Titre du message' : '');
        }
      };
      f.addEventListener('input', upd);
    });
    var audience = JSON.parse(pe.getAttribute('data-audience') || '{}');
    var count = pe.querySelector('[data-pe-count]');
    var dev = pe.querySelector('[data-pe-devices]');
    var showCount = function () {
      var n = audience[dev.value] || 0;
      count.textContent = n + ' abonné' + (n > 1 ? 's' : '') + ' sur ces appareils (avant le filtre de provenance).';
    };
    dev.addEventListener('change', showCount);
    showCount();
    pe.addEventListener('submit', function (ev) {
      var when = pe.querySelector('input[name=when]:checked');
      if (when && when.value === 'at' && !pe.querySelector('input[name=scheduled_at]').value) {
        ev.preventDefault();
        window.DeepFindUI.alert('Choisissez la date et l\'heure d\'envoi.');
        return;
      }
      if (when && when.value === 'now' && !pe.__dfSendNow) {
        ev.preventDefault();
        window.DeepFindUI.confirm('Envoyer ce message maintenant à ' + (audience[dev.value] || 0) + ' abonné(s) au plus ? Il ne pourra plus être modifié.',
          { ok: 'Envoyer' }).then(function (yes) { if (yes) { pe.__dfSendNow = true; pe.requestSubmit ? pe.requestSubmit() : pe.submit(); } });
      }
    });
  });
})();
