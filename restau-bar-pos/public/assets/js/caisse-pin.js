// Caisse partagée : choix de la personne, puis pavé du PIN (envoi automatique au 6e chiffre).
// Sans JavaScript, chaque nom mène à /caisse?personne=<id> et le PIN se tape dans le champ.
(function () {
  'use strict';
  var pave = document.getElementById('pave');
  var form = document.getElementById('pave-form');
  if (!pave || !form) { return; }
  var champ = document.getElementById('pave-pin');
  var points = document.querySelectorAll('#pave-points span');
  var touches = document.getElementById('pave-touches');
  var actions = document.getElementById('pave-actions');
  var longueur = parseInt(champ.getAttribute('maxlength'), 10) || 6;
  var envoi = false;

  // Le pavé remplace le clavier du téléphone ; le champ reste pour les lecteurs d'écran et le clavier physique
  touches.hidden = false;
  actions.hidden = true;
  champ.classList.add('visuellement-cache');
  champ.setAttribute('readonly', 'readonly');

  function dessiner() {
    for (var i = 0; i < points.length; i++) { points[i].classList.toggle('plein', i < champ.value.length); }
    if (champ.value.length === longueur && !envoi) {
      envoi = true;
      form.classList.add('pave__form--envoi');
      form.submit();
    }
  }
  function taper(chiffre) {
    if (envoi || champ.value.length >= longueur) { return; }
    champ.value += chiffre;
    if (navigator.vibrate) { try { navigator.vibrate(8); } catch (e) { /* sans vibration */ } }
    dessiner();
  }
  function ouvrir(lien) {
    if (lien.dataset.bloque) { return; }
    document.getElementById('pave-utilisateur').value = lien.dataset.personne;
    document.getElementById('pave-nom').textContent = lien.dataset.nom;
    document.getElementById('pave-initiales').textContent = lien.dataset.initiales;
    var erreur = document.getElementById('pave-erreur');
    if (erreur) { erreur.remove(); }
    champ.value = '';
    envoi = false;
    dessiner();
    if (pave.open) { pave.close(); }
    pave.showModal();
  }

  document.querySelectorAll('[data-personne]').forEach(function (lien) {
    lien.addEventListener('click', function (ev) { ev.preventDefault(); ouvrir(lien); });
  });
  touches.addEventListener('click', function (ev) {
    var b = ev.target.closest('button');
    if (!b) { return; }
    if (b.dataset.chiffre !== undefined) { taper(b.dataset.chiffre); }
    else if (b.hasAttribute('data-effacer')) { champ.value = champ.value.slice(0, -1); dessiner(); }
    else if (b.hasAttribute('data-fermer')) { pave.close(); }
  });
  // Clavier physique (caisse avec clavier)
  pave.addEventListener('keydown', function (ev) {
    if (/^\d$/.test(ev.key)) { ev.preventDefault(); taper(ev.key); }
    else if (ev.key === 'Backspace') { ev.preventDefault(); champ.value = champ.value.slice(0, -1); dessiner(); }
  });
  pave.addEventListener('click', function (ev) { if (ev.target === pave) { pave.close(); } });

  // Après une erreur, le pavé de la même personne se rouvre en fenêtre
  if (pave.dataset.ouvrir) { pave.close(); pave.showModal(); }
})();
