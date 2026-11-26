/* Notifications : réglages (champs selon la forme, copie du sw.js, aperçu ordinateur / mobile) et rédaction d'un message
 * (aperçu de la notification, nombre d'abonnés visés, confirmation d'envoi). textContent uniquement. */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    // ---------------------------------------------------------------- « Importer une image » (message, invitation, icône)
    document.querySelectorAll('form[data-push-upload] [data-pu]').forEach(function (row) {
      var form = row.closest('form');
      var input = form.querySelector('[name="' + row.getAttribute('data-pu') + '"]');
      var file = row.querySelector('[data-pu-file]');
      var thumb = row.querySelector('[data-pu-thumb]');
      var msg = row.querySelector('[data-pu-msg]');
      var hint = msg.textContent;
      var TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
      var showThumb = function () {
        var ok = /^https?:\/\//i.test(input.value.trim());
        thumb.hidden = !ok;
        if (ok) { thumb.src = input.value.trim(); }
      };
      var say = function (text, bad) { msg.textContent = text; msg.classList.toggle('ko', !!bad); };
      row.querySelector('[data-pu-pick]').addEventListener('click', function () { file.click(); });
      thumb.addEventListener('error', function () { thumb.hidden = true; });
      input.addEventListener('input', function () { say(hint); showThumb(); });
      file.addEventListener('change', function () {
        var f0 = file.files[0];
        file.value = '';
        if (!f0) { return; }
        if (TYPES.indexOf(f0.type) === -1) { say('Format non accepté (' + f0.name + ') : JPG, PNG, GIF ou WebP.', true); return; }
        say('Téléversement…');
        var data = new FormData();
        data.append('_csrf', form.getAttribute('data-csrf'));
        data.append('image', f0);
        fetch(form.getAttribute('data-push-upload'), { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (r) { return r.json().catch(function () { return { error: 'réponse illisible du serveur' }; }); })
          .then(function (d) {
            if (!d.url) { say('Image non importée : ' + (d.error || 'erreur inconnue'), true); return; }
            input.value = d.url;
            input.dispatchEvent(new Event('input', { bubbles: true })); // aperçu mis à jour
            say('Image importée (' + Math.ceil(f0.size / 1024) + ' Ko). Pensez à enregistrer.');
          })
          .catch(function () { say('Téléversement impossible : vérifiez la connexion.', true); });
      });
      showThumb();
    });

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
      if (ev.submitter && ev.submitter.hasAttribute('data-pe-test')) { return; } // envoi au groupe de test : ni date ni confirmation
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
