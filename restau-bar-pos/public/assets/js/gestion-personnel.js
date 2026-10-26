// Personnel et paie : petites aides des formulaires (fiche employé, réglages du pointage, bulletin, avances).
(function () {
  'use strict';

  // Fiche employé : un compte lié donne son nom ; le téléphone est alors celui du compte
  var compte = document.querySelector('[data-compte-employe]');
  if (compte) {
    var telephone = document.querySelector('[data-telephone-employe]');
    var nom = document.getElementById('nom');
    var majCompte = function () {
      var option = compte.options[compte.selectedIndex];
      var lie = compte.value !== '';
      if (telephone) { telephone.hidden = lie; }
      if (lie && nom && nom.value.trim() === '') { nom.value = option.getAttribute('data-nom') || ''; }
    };
    compte.addEventListener('change', majCompte);
    majCompte();
  }

  // Réglages : « Utiliser la position de cet appareil » (à faire depuis l'établissement)
  var reglages = document.getElementById('reglages-pointage');
  var bouton = reglages ? reglages.querySelector('[data-ma-position]') : null;
  if (bouton) {
    var etat = reglages.querySelector('[data-position-etat]');
    bouton.addEventListener('click', function () {
      if (!navigator.geolocation) { etat.textContent = reglages.getAttribute('data-texte-position-erreur'); return; }
      etat.textContent = reglages.getAttribute('data-texte-position');
      bouton.disabled = true;
      navigator.geolocation.getCurrentPosition(function (p) {
        bouton.disabled = false;
        document.getElementById('latitude').value = p.coords.latitude.toFixed(6);
        document.getElementById('longitude').value = p.coords.longitude.toFixed(6);
        etat.textContent = reglages.getAttribute('data-texte-position-ok').replace('{precision}', String(Math.round(p.coords.accuracy || 0)));
      }, function () {
        bouton.disabled = false;
        etat.textContent = reglages.getAttribute('data-texte-position-erreur');
      }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
    });
  }

  // Bulletin : « étaler sur N paies » propose le montant de cette paie ; confirmation avant de valider
  document.querySelectorAll('[data-etaler]').forEach(function (choix) {
    choix.addEventListener('change', function () {
      var champ = document.getElementById('montant-' + choix.getAttribute('data-etaler'));
      var max = parseFloat(champ.getAttribute('data-max'));
      var n = parseInt(choix.value, 10) || 1;
      if (!isFinite(max)) { return; }
      var f = Math.pow(10, parseInt(champ.getAttribute('data-decimales'), 10) || 0);
      var valeur = Math.ceil(max / n * f) / f;
      champ.value = String(valeur).replace('.', document.documentElement.lang === 'fr' ? ',' : '.');
    });
  });
  var valider = document.querySelector('[data-valider]');
  if (valider) {
    valider.addEventListener('click', function (ev) {
      if (!window.confirm(valider.getAttribute('data-confirmer'))) { ev.preventDefault(); }
    });
  }

  // Avance : les comptes proposés sont ceux de l'établissement de l'employé
  var employe = document.querySelector('[data-recharger-comptes]');
  if (employe) {
    employe.addEventListener('change', function () {
      var montants = document.querySelectorAll('[data-montant-paiement]');
      var vide = Array.prototype.every.call(montants, function (m) { return m.value.trim() === ''; });
      if (vide && employe.value) { window.location.href = '/gestion/personnel/avances?employe=' + encodeURIComponent(employe.value) + '#nouvelle'; }
    });
  }
})();
