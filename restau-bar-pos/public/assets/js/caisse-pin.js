// Caisse partagée verrouillée (façon Windows) : heure qui avance, choix de la personne,
// puis pavé du PIN au centre (envoi automatique au 6e chiffre).
// Sans JavaScript, chaque nom mène à /caisse?personne=<id> et le PIN se tape dans le champ.
(function () {
  'use strict';
  var racine = document.getElementById('qui');
  var pave = document.getElementById('pave');
  var form = document.getElementById('pave-form');
  if (!racine || !pave || !form) { return; }
  var langue = racine.dataset.langue === 'en' ? 'en-GB' : 'fr-FR';
  var champ = document.getElementById('pave-pin');
  var points = document.querySelectorAll('#pave-points span');
  var touches = document.getElementById('pave-touches');
  var actions = document.getElementById('pave-actions');
  var modeles = document.getElementById('modeles-avatars');
  var longueur = parseInt(champ.getAttribute('maxlength'), 10) || 6;
  var envoi = false;

  // Heure et date de l'écran de verrouillage
  var heure = racine.querySelector('[data-heure]');
  var date = racine.querySelector('[data-date]');
  function horloge() {
    var maintenant = new Date();
    heure.textContent = maintenant.toLocaleTimeString(langue, { hour: '2-digit', minute: '2-digit' });
    var texte = maintenant.toLocaleDateString(langue, { weekday: 'long', day: 'numeric', month: 'long' });
    date.textContent = texte.charAt(0).toUpperCase() + texte.slice(1);
  }
  horloge();
  setInterval(horloge, 10000);

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
  // Sans réseau (caisse-hors-ligne.js) : seules les personnes déjà entrées ici avec le réseau ont un PIN vérifiable
  var donneesHl = document.getElementById('donnees-hors-ligne');
  var TH = donneesHl ? (JSON.parse(donneesHl.textContent).textes || {}) : {};
  var messageHl = document.getElementById('hl-pin-message');
  function dire(texte) {
    if (!messageHl) { return; }
    messageHl.textContent = texte || '';
    messageHl.hidden = !texte;
  }
  function ouvrir(lien) {
    if (lien.dataset.bloque) { return; }
    if (lien.dataset.hlIndisponible) { dire(TH.pin_indisponible); return; }
    dire('');
    document.querySelectorAll('[data-personne][aria-current]').forEach(function (a) { a.removeAttribute('aria-current'); });
    lien.setAttribute('aria-current', 'true');
    document.getElementById('pave-utilisateur').value = lien.dataset.personne;
    document.getElementById('pave-nom').textContent = lien.dataset.nom;
    var modele = modeles && modeles.content.querySelector('[data-avatar-de="' + lien.dataset.personne + '"]');
    var cadre = document.getElementById('pave-avatar');
    cadre.textContent = '';
    if (modele) { cadre.appendChild(modele.firstElementChild.cloneNode(true)); }
    var erreur = document.getElementById('pave-erreur');
    if (erreur) { erreur.remove(); }
    champ.value = '';
    envoi = false;
    dessiner();
    pave.hidden = false;
    racine.classList.add('verrou--pin');
    var premiere = touches.querySelector('button');
    if (premiere) { premiere.focus({ preventScroll: true }); }
  }
  function fermer() {
    pave.hidden = true;
    racine.classList.remove('verrou--pin');
    document.querySelectorAll('[data-personne][aria-current]').forEach(function (a) { a.removeAttribute('aria-current'); });
    champ.value = '';
    dessiner();
  }

  document.querySelectorAll('[data-personne]').forEach(function (lien) {
    lien.addEventListener('click', function (ev) { ev.preventDefault(); ouvrir(lien); });
  });
  // Retour du service worker après un PIN vérifié sans réseau : ?personne=<id>&hl=<raison>&reste=<n>
  var parametres = new URLSearchParams(location.search);
  var raison = parametres.get('hl');
  if (raison) {
    var lienHl = document.querySelector('[data-personne="' + (parseInt(parametres.get('personne'), 10) || 0) + '"]');
    var nom = lienHl ? lienHl.dataset.nom : '';
    var message = {
      incorrect: (TH.pin_incorrect || '').replace('{reste}', parametres.get('reste') || ''),
      bloque: (TH.pin_bloque || '').replace('{nom}', nom),
      appareil: TH.pin_appareil,
      indisponible: TH.pin_indisponible
    }[raison] || '';
    if (lienHl && raison === 'incorrect') {
      ouvrir(lienHl);
      var aide = document.getElementById('pave-aide');
      if (aide) { aide.textContent = message; aide.classList.add('pave__aide--erreur'); }
    } else {
      dire(message);
    }
    // L'adresse redevient /caisse : recharger ne rejoue pas le message
    if (history.replaceState) { history.replaceState(null, '', location.pathname + (parametres.get('zone') ? '?zone=' + encodeURIComponent(parametres.get('zone')) : '')); }
  }
  touches.addEventListener('click', function (ev) {
    var b = ev.target.closest('button');
    if (!b) { return; }
    if (b.dataset.chiffre !== undefined) { taper(b.dataset.chiffre); }
    else if (b.hasAttribute('data-effacer')) { champ.value = champ.value.slice(0, -1); dessiner(); }
    else if (b.hasAttribute('data-fermer')) { fermer(); }
  });
  // Clavier physique (caisse avec clavier)
  document.addEventListener('keydown', function (ev) {
    if (pave.hidden) { return; }
    if (/^\d$/.test(ev.key)) { ev.preventDefault(); taper(ev.key); }
    else if (ev.key === 'Backspace') { ev.preventDefault(); champ.value = champ.value.slice(0, -1); dessiner(); }
    else if (ev.key === 'Escape') { fermer(); }
  });
})();
