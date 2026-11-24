/* Aperçu direct, côté cadre : reçoit de la fiche (même origine uniquement) la popin ou le carrousel à dessiner
 * et le confie au vrai widget (DeepFind.render). Signale à la fiche qu'il est prêt. */
(function () {
  'use strict';
  var config = {};
  try { config = JSON.parse(document.body.getAttribute('data-config') || '{}'); } catch (e) { config = {}; }
  window.addEventListener('message', function (ev) {
    if (ev.origin !== window.location.origin || !ev.data || ev.data.df !== 'render') { return; }
    if (window.DeepFind && window.DeepFind.render) {
      window.DeepFind.render({ config: config, type: ev.data.type, item: ev.data.item, slot: document.getElementById('df-preview-slot') });
    }
  });
  window.parent.postMessage({ df: 'ready' }, window.location.origin);
})();
