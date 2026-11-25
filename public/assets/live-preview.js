/* Aperçu direct d'une popin ou d'un carrousel dans l'extranet : fenêtre avec une page fictive (cadre /admin/live-preview)
 * où le vrai widget dessine le contenu de la fiche, en format ordinateur (1280 × 800) ou mobile (390 × 844), réduit pour tenir à l'écran.
 * DFLivePreview({ button, frameUrl, type: 'popin' | 'carousel', load: () => Promise<{ item } | { error }> })
 * Réglages en cours, même non enregistrés ; rien n'est compté ni mémorisé. Données affichées avec textContent uniquement. */
(function () {
  'use strict';
  var SIZES = { desktop: [1280, 800], mobile: [390, 844] };
  window.DFLivePreview = function (opts) {
    if (!opts.button) { return; }
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
    var device = 'desktop';
    try { device = window.localStorage.getItem('df-lp-device') === 'mobile' ? 'mobile' : 'desktop'; } catch (e) { /* ignoré */ }

    var dialog = el('dialog', 'lv-dialog');
    dialog.setAttribute('aria-label', 'Aperçu');
    var bar = el('div', 'lv-bar');
    bar.appendChild(el('strong', 'lv-title', opts.type === 'carousel' ? 'Aperçu du carrousel' : (opts.type === 'push' ? 'Aperçu de l\'invitation aux notifications' : 'Aperçu de la popin')));
    var sw = el('div', 'lv-switch');
    sw.setAttribute('role', 'group');
    sw.setAttribute('aria-label', 'Format d\'écran');
    var buttons = {};
    [['desktop', 'Ordinateur'], ['mobile', 'Mobile']].forEach(function (d) {
      var b = el('button', 'lv-sw', d[1]);
      b.type = 'button';
      b.setAttribute('data-device', d[0]);
      b.addEventListener('click', function () { setDevice(d[0]); });
      buttons[d[0]] = b;
      sw.appendChild(b);
    });
    bar.appendChild(sw);
    var replay = el('button', 'btn btn-sm btn-light', 'Rejouer');
    replay.type = 'button';
    replay.title = 'Relire les réglages de la fiche et redessiner';
    var close = el('button', 'btn btn-sm', 'Fermer');
    close.type = 'button';
    var actions = el('div', 'lv-actions');
    actions.appendChild(replay);
    actions.appendChild(close);
    bar.appendChild(actions);
    var msg = el('p', 'lv-msg');
    msg.setAttribute('role', 'status');
    var stage = el('div', 'lv-stage');
    var screen = el('div', 'lv-screen');
    var frame = el('iframe', 'lv-frame');
    frame.title = 'Aperçu';
    screen.appendChild(frame);
    stage.appendChild(screen);
    dialog.appendChild(bar);
    dialog.appendChild(msg);
    dialog.appendChild(stage);
    document.body.appendChild(dialog);

    var ready = false;
    var item = null;
    function send() {
      if (!ready || !item) { return; }
      frame.contentWindow.postMessage({ df: 'render', type: opts.type, item: item }, window.location.origin);
    }
    window.addEventListener('message', function (ev) {
      if (ev.origin !== window.location.origin || ev.source !== frame.contentWindow || !ev.data || ev.data.df !== 'ready') { return; }
      ready = true;
      send();
    });
    // taille réelle de l'écran simulé, réduite à l'affichage pour tenir dans la fenêtre
    function fit() {
      var size = SIZES[device];
      var r = stage.getBoundingClientRect();
      var scale = Math.min(1, (r.width - 32) / size[0], (r.height - 32) / size[1]);
      scale = Math.max(0.2, scale);
      frame.style.width = size[0] + 'px';
      frame.style.height = size[1] + 'px';
      frame.style.transform = 'scale(' + scale + ')';
      screen.style.width = Math.round(size[0] * scale) + 'px';
      screen.style.height = Math.round(size[1] * scale) + 'px';
      screen.setAttribute('data-scale', String(Math.round(scale * 100)));
    }
    function setDevice(d) {
      device = d;
      try { window.localStorage.setItem('df-lp-device', d); } catch (e) { /* ignoré */ }
      Object.keys(buttons).forEach(function (k) { buttons[k].setAttribute('aria-pressed', k === d ? 'true' : 'false'); });
      screen.className = 'lv-screen lv-' + d;
      fit();
      // le widget choisit sa mise en page (mobile ≤ 900 px) au moment de dessiner : redessin après le changement de taille
      requestAnimationFrame(function () { requestAnimationFrame(send); });
    }
    function load() {
      msg.textContent = 'Chargement…';
      msg.className = 'lv-msg';
      opts.load().then(function (d) {
        if (!d || d.error || !d.item) {
          item = null;
          msg.textContent = (d && d.error) || 'Aperçu impossible.';
          msg.className = 'lv-msg lv-error';
          return;
        }
        item = d.item;
        msg.textContent = (d.note ? d.note + ' ' : '') + 'Réglages en cours, même non enregistrés. Page fictive : seul le contenu DeepFind est réel. Rien n\'est compté ni mémorisé.';
        if (!frame.getAttribute('src')) { frame.src = opts.frameUrl; } else { send(); }
      }).catch(function () {
        msg.textContent = 'Aperçu impossible : vérifiez la connexion.';
        msg.className = 'lv-msg lv-error';
      });
    }
    opts.button.addEventListener('click', function () {
      dialog.showModal();
      setDevice(device);
      load();
    });
    replay.addEventListener('click', load);
    close.addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (ev) { if (ev.target === dialog) { dialog.close(); } });
    window.addEventListener('resize', function () { if (dialog.open) { fit(); } });
  };
})();
