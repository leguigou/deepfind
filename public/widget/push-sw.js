/*
 * DeepFind — service worker des notifications (Web Push).
 * Chargé par le fichier sw.js du site, d'une seule ligne :
 *   importScripts('https://<adresse de DeepFind>/widget/push-sw.js');
 * Affiche la notification reçue (titre, texte, image, lien), ouvre le lien au clic, et signale à DeepFind l'affichage et le clic
 * (compteurs du message, sans donnée sur la personne). Ne met rien en cache et n'intercepte aucune requête du site.
 */
'use strict';

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });

function dfSafeUrl(u) {
  try {
    var url = new URL(u || '/', self.location.origin);
    return /^https?:$/.test(url.protocol) ? url.href : self.location.origin + '/';
  } catch (e) { return self.location.origin + '/'; }
}

function dfPing(d, e) {
  if (!d || !d.a || !d.c || !d.k) { return Promise.resolve(); }
  var body = new URLSearchParams({ key: String(d.k), lang: String(d.l || 'fr'), c: String(d.c), e: e });
  if (d.s && d.g) { body.set('s', String(d.s)); body.set('g', String(d.g)); } // compteurs de l'appareil (identifiant signé)
  return fetch(String(d.a) + '/push/event', { method: 'POST', body: body, credentials: 'omit' }).catch(function () { /* compteur facultatif */ });
}

self.addEventListener('push', function (event) {
  var d = {};
  try { d = event.data ? event.data.json() : {}; } catch (e) { d = { t: event.data ? event.data.text() : '' }; }
  var options = { body: String(d.b || ''), data: { u: dfSafeUrl(d.u), c: d.c, k: d.k, a: d.a, l: d.l, s: d.s, g: d.g }, tag: d.c ? 'deepfind-' + d.c : undefined };
  if (d.ic) { options.icon = String(d.ic); options.badge = String(d.ic); }
  if (d.i) { options.image = String(d.i); }
  event.waitUntil(Promise.all([self.registration.showNotification(String(d.t || ''), options), dfPing(d, 's')]));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var d = event.notification.data || {};
  var target = dfSafeUrl(d.u);
  event.waitUntil(Promise.all([
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
      for (var i = 0; i < list.length; i++) {
        if (list[i].url === target && 'focus' in list[i]) { return list[i].focus(); }
      }
      return self.clients.openWindow ? self.clients.openWindow(target) : null;
    }),
    dfPing(d, 'c')
  ]));
});
