// Caisse : pointage de secours (PIN de l'employé validé par le PIN d'un gérant), envoyé sans quitter la caisse.
(function () {
  'use strict';

  var form = document.getElementById('form-pointage-secours');
  if (!form) { return; }
  var message = document.getElementById('pointage-message');
  var employe = document.getElementById('pointage-employe');
  var pinEmploye = document.getElementById('pointage-pin-employe');

  // Un employé sans PIN (pas de compte ni de PIN de pointage) : seul le PIN du gérant est demandé
  function majPin() {
    var option = employe.options[employe.selectedIndex];
    var sansPin = option && option.getAttribute('data-pin') === '0';
    pinEmploye.disabled = sansPin;
    if (sansPin) { pinEmploye.value = ''; }
  }
  employe.addEventListener('change', majPin);
  majPin();

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var bouton = form.querySelector('button[type=submit]');
    bouton.disabled = true;
    message.hidden = true;
    fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        bouton.disabled = false;
        message.hidden = false;
        message.textContent = res.ok ? res.j.message : (res.j.erreur || '');
        message.classList.toggle('pointage-secours__message--ok', res.ok);
        form.querySelectorAll('input[type=password]').forEach(function (champ) { champ.value = ''; });
        if (res.ok) { setTimeout(function () { window.location.reload(); }, 1500); }
      })
      .catch(function () { bouton.disabled = false; message.hidden = false; message.textContent = '…'; });
  });
})();
