// Téléphone de l'employé : « Pointer mon arrivée / mon départ » demande la position GPS, l'envoie avec le code QR
// scanné, puis affiche le résultat (heure, distance) ou l'erreur à corriger (code expiré, trop loin…).
(function () {
  'use strict';

  var action = document.getElementById('action');
  var bouton = document.getElementById('pointer');
  if (!action || !bouton) { return; }
  var resultat = document.getElementById('resultat');
  var libelle = bouton.textContent;
  var texte = function (cle) { return action.getAttribute('data-texte-' + cle) || ''; };

  function afficher(ok, message, detail) {
    resultat.hidden = false;
    resultat.className = 'pointage__resultat ' + (ok ? 'pointage__resultat--ok' : 'pointage__resultat--erreur');
    resultat.innerHTML = '';
    var titre = document.createElement('p');
    titre.className = 'pointage__resultat-titre';
    titre.textContent = message;
    resultat.appendChild(titre);
    if (detail) {
      var p = document.createElement('p');
      p.className = 'pointage__resultat-detail';
      p.textContent = detail;
      resultat.appendChild(p);
    }
    resultat.setAttribute('role', ok ? 'status' : 'alert');
    resultat.focus();
  }

  function envoyer(position) {
    bouton.textContent = texte('envoi');
    var corps = new FormData();
    corps.append('csrf', action.getAttribute('data-csrf'));
    corps.append('t', action.getAttribute('data-t'));
    corps.append('lat', String(position.coords.latitude));
    corps.append('lng', String(position.coords.longitude));
    corps.append('precision', String(Math.round(position.coords.accuracy || 0)));
    fetch('/pointer', { method: 'POST', body: corps, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.ok) {
          bouton.hidden = true;
          afficher(true, res.j.message, res.j.detail);
          var statut = document.getElementById('statut');
          if (statut) { statut.hidden = true; }
          return;
        }
        afficher(false, res.j.erreur || texte('reseau'), null);
        // Code expiré ou déjà utilisé : il faut scanner à nouveau ; sinon on peut réessayer
        if (res.j.code === 'qr_expire' || res.j.code === 'qr_invalide' || res.j.code === 'deja') {
          bouton.hidden = true;
        } else {
          bouton.disabled = false;
          bouton.textContent = libelle;
        }
      })
      .catch(function () {
        afficher(false, texte('reseau'), null);
        bouton.disabled = false;
        bouton.textContent = libelle;
      });
  }

  bouton.addEventListener('click', function () {
    resultat.hidden = true;
    if (!navigator.geolocation) {
      afficher(false, texte('indisponible'), null);
      return;
    }
    bouton.disabled = true;
    bouton.textContent = texte('position');
    navigator.geolocation.getCurrentPosition(envoyer, function (erreur) {
      bouton.disabled = false;
      bouton.textContent = libelle;
      afficher(false, erreur.code === 1 ? texte('refus') : (erreur.code === 3 ? texte('delai') : texte('indisponible')), null);
    }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
  });
})();
