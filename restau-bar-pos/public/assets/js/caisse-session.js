// Sessions de caisse (si l'établissement les demande) : « Ouvrir ma caisse » avec le fond compté,
// « Clôturer ma caisse » avec le comptage, puis le rapport de clôture à imprimer.
(function () {
  'use strict';

  var C = window.Caisse;
  if (!C || !C.D.sessions) { return; }
  var D = C.D;

  function lireMontant(texte) {
    var n = parseFloat(String(texte).replace(/[\s  ]/g, '').replace(',', '.'));
    return isFinite(n) && n >= 0 ? n : 0;
  }

  document.querySelectorAll('.session-fenetre').forEach(function (fenetre) {
    var form = fenetre.querySelector('form');
    var sens = form.dataset.session;
    var erreur = form.querySelector('.session-erreur');
    form.querySelector('[data-fermer]').addEventListener('click', function () { fenetre.close(); });
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var montants = {};
      form.querySelectorAll('input[data-compte]').forEach(function (champ) {
        montants[champ.dataset.compte] = montants[champ.dataset.compte] || {};
        montants[champ.dataset.compte][champ.dataset.devise] = lireMontant(champ.value);
      });
      var corps = new FormData();
      corps.append('csrf', D.csrf);
      corps.append('etab', D.etab);
      corps.append(sens === 'ouverture' ? 'fonds' : 'comptes', JSON.stringify(montants));
      if (form.querySelector('[name=note]')) { corps.append('note', form.querySelector('[name=note]').value); }
      var bouton = form.querySelector('button[type=submit]');
      bouton.disabled = true;
      fetch(sens === 'ouverture' ? '/caisse/session/ouvrir' : '/caisse/session/cloturer', { method: 'POST', body: corps, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          bouton.disabled = false;
          if (!res.ok) { erreur.textContent = res.j.erreur || C.T.erreurReseau; erreur.hidden = false; return; }
          if (sens === 'ouverture') { window.location.reload(); return; }
          // Clôture : le rapport, à imprimer, puis retour à la caisse (fermée)
          form.hidden = true;
          var rapport = fenetre.querySelector('.session-rapport');
          rapport.querySelector('.session-rapport__recu').innerHTML = res.j.rapport;
          rapport.hidden = false;
        })
        .catch(function () { bouton.disabled = false; erreur.textContent = C.T.erreurReseau; erreur.hidden = false; });
    });
    var rapport = fenetre.querySelector('.session-rapport');
    rapport.querySelector('[data-imprimer]').addEventListener('click', function () { C.imprimer(rapport.querySelector('.recu').outerHTML); });
    rapport.querySelector('[data-terminer]').addEventListener('click', function () { window.location.reload(); });
    fenetre.addEventListener('cancel', function (ev) { if (!rapport.hidden) { ev.preventDefault(); window.location.reload(); } });
  });

  var ouvrir = document.getElementById('ouvrir-session');
  if (ouvrir) {
    ouvrir.addEventListener('click', function () {
      var f = document.getElementById('fenetre-ouverture');
      f.showModal();
      var premier = f.querySelector('input');
      if (premier) { premier.focus(); }
    });
  }
  var cloturer = document.getElementById('cloturer-session');
  if (cloturer) {
    cloturer.addEventListener('click', function () {
      document.getElementById('fenetre-quitter').close();
      document.getElementById('fenetre-cloture').showModal();
    });
  }
})();
