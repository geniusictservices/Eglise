// Déconnexion de la gestion sur un appareil personnel (pas une caisse partagée) : la caisse hors ligne de ce
// navigateur est oubliée — pages de la caisse gardées par le service worker (elles portent le nom et le jeton de la
// personne), clés de signature et vérificateurs de PIN, petits états. Seuls le compteur et la marque des reçus
// provisoires restent (un numéro HL-… ne sert jamais deux fois). S'il reste des ventes faites sans réseau à envoyer,
// rien n'est effacé : un avertissement le dit (elles partiront à la prochaine ouverture de la caisse avec le réseau).
/* global RbpHorsLigne */
(function () {
  'use strict';
  var avis = document.getElementById('caisse-oubli');
  var B = window.RbpHorsLigne;
  if (!avis || !B || !B.disponible()) { return; }
  var GARDER = { sequence: true, marque: true };

  // Sans base « rbp-caisse » sur ce navigateur (la caisse n'y a jamais servi) : ne pas la créer
  var existe = indexedDB.databases
    ? indexedDB.databases().then(function (l) { return l.some(function (d) { return d.name === 'rbp-caisse'; }); }, function () { return true; })
    : Promise.resolve(true);

  function oublierPages() {
    if (!('caches' in window)) { return Promise.resolve(); }
    return caches.keys().then(function (noms) {
      return Promise.all(noms.filter(function (n) { return n.indexOf('rbp-caisse-pages-') === 0; }).map(function (n) { return caches.delete(n); }));
    });
  }

  existe.then(function (oui) {
    if (!oui) { return oublierPages(); }
    return B.tous('file').then(function (file) {
      var attente = file.filter(function (i) { return i.statut !== 'envoyee'; }).length;
      if (attente > 0) {
        avis.textContent = attente === 1 ? avis.dataset.une : avis.dataset.plusieurs.replace('{n}', String(attente));
        avis.hidden = false;
        return null;
      }
      return Promise.all([
        B.vider('cles'), B.vider('verificateurs'), B.vider('attente'), B.vider('file'),
        B.tous('etat').then(function (etats) {
          return Promise.all(etats.filter(function (e) { return !GARDER[e.k]; }).map(function (e) { return B.effacer('etat', e.k); }));
        }),
        oublierPages()
      ]);
    });
  }).catch(function () { /* navigateur sans IndexedDB utilisable (navigation privée…) : rien à oublier */ });
})();
