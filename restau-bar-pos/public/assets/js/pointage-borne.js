// Borne de pointage : dessine le code QR, le renouvelle à la fin de chaque fenêtre (/pointage/borne/jeton),
// affiche l'heure et le temps restant. Pensé pour une tablette bon marché : JavaScript simple, sans dépendance
// autre que le dessin du code QR (assets/vendor/qrcode.js).
(function () {
  'use strict';

  var borne = document.getElementById('borne');
  if (!borne || typeof qrcode !== 'function') { return; }
  var zoneQr = document.getElementById('borne-qr');
  var barre = document.getElementById('borne-barre');
  var etat = document.getElementById('borne-etat');
  var heure = document.getElementById('borne-heure');
  var duree = parseInt(borne.getAttribute('data-duree'), 10) || 30;
  var url = borne.getAttribute('data-url');
  var finFenetre = Date.now() + (parseInt(borne.getAttribute('data-expire'), 10) || duree) * 1000;
  var horsLigne = false;
  var enCours = false;

  function dessiner() {
    var qr = qrcode(0, 'M');
    qr.addData(url);
    qr.make();
    zoneQr.innerHTML = qr.createSvgTag({ cellSize: 8, margin: 2, scalable: true });
    zoneQr.setAttribute('data-url', url);
  }

  function texte(cle, n) {
    return (borne.getAttribute('data-texte-' + cle) || '').replace('{n}', String(n));
  }

  function renouveler() {
    if (enCours) { return; }
    enCours = true;
    var requete = new XMLHttpRequest();
    requete.open('GET', '/pointage/borne/jeton?_=' + Date.now(), true);
    requete.setRequestHeader('Accept', 'application/json');
    requete.timeout = 10000;
    requete.onload = function () {
      enCours = false;
      if (requete.status === 403) {
        etat.textContent = texte('retire', 0);
        zoneQr.classList.add('borne__qr--expire');
        setTimeout(function () { window.location.reload(); }, 30000);
        return;
      }
      try {
        var reponse = JSON.parse(requete.responseText);
        url = reponse.url;
        finFenetre = Date.now() + Math.max(1, reponse.expire_dans) * 1000;
        horsLigne = false;
        zoneQr.classList.remove('borne__qr--expire');
        dessiner();
      } catch (e) { echec(); }
    };
    requete.onerror = requete.ontimeout = function () { enCours = false; echec(); };
    requete.send();
  }

  function echec() {
    horsLigne = true;
    // Le code affiché a expiré : on le grise pour qu'on ne le scanne pas pour rien
    zoneQr.classList.add('borne__qr--expire');
    etat.textContent = texte('hors-ligne', 0);
    finFenetre = Date.now() + 5000;
  }

  function battement() {
    var maintenant = new Date();
    heure.textContent = ('0' + maintenant.getHours()).slice(-2) + ':' + ('0' + maintenant.getMinutes()).slice(-2);
    var reste = Math.ceil((finFenetre - Date.now()) / 1000);
    if (reste <= 0) {
      renouveler();
      return;
    }
    if (!horsLigne) {
      etat.textContent = texte('renouvele', reste);
      barre.style.width = Math.max(0, Math.min(100, reste / duree * 100)) + '%';
    }
  }

  dessiner();
  battement();
  setInterval(battement, 1000);

  // Garder l'écran allumé quand le navigateur le permet
  function garderAllume() {
    if (navigator.wakeLock && navigator.wakeLock.request) {
      navigator.wakeLock.request('screen').catch(function () { /* refusé : tant pis */ });
    }
  }
  garderAllume();
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') { garderAllume(); renouveler(); }
  });
})();
