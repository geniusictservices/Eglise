// Lecture d'un code QR (billet, carte cashless, ticket de vestiaire) : caméra de la tablette avec l'API
// BarcodeDetector du navigateur quand elle existe, sinon (ou en plus) saisie du code à la main.
// ScannerQr.monter(conteneur, { textes, lu: function (code) {}, continu: false }) → { arreter(), reprendre(), champ }
(function () {
  'use strict';

  function el(balise, classe, texte) {
    var e = document.createElement(balise);
    if (classe) { e.className = classe; }
    if (texte !== undefined) { e.textContent = texte; }
    return e;
  }

  var actifs = [];

  function monter(conteneur, options) {
    var T = options.textes || {};
    var bloc = el('div', 'scanner');
    var video = el('video', 'scanner__video');
    video.setAttribute('playsinline', '');
    video.muted = true;
    video.hidden = true;
    var etat = el('p', 'scanner__etat');
    etat.setAttribute('aria-live', 'polite');
    var bouton = el('button', 'bouton bouton--secondaire scanner__camera', T.camera || 'Caméra');
    bouton.type = 'button';
    var form = el('form', 'scanner__form');
    var champ = el('input', 'saisie scanner__champ');
    champ.type = 'text';
    champ.autocomplete = 'off';
    champ.autocapitalize = 'characters';
    champ.spellcheck = false;
    champ.placeholder = options.placeholder || T.code_manuel || '';
    champ.setAttribute('aria-label', options.placeholder || T.code_manuel || '');
    if (options.numerique) { champ.inputMode = 'text'; }
    var valider = el('button', 'bouton scanner__valider', T.valider || 'OK');
    valider.type = 'submit';
    form.append(champ, valider);
    bloc.append(video, bouton, etat, form);
    conteneur.appendChild(bloc);

    var flux = null, detecteur = null, minuterie = null, enPause = false, dernier = '', dernierLe = 0;
    var supporte = 'BarcodeDetector' in window && navigator.mediaDevices && navigator.mediaDevices.getUserMedia;

    function lu(code) {
      code = String(code || '').trim();
      if (!code) { return; }
      var maintenant = Date.now();
      // Le même code vu deux fois de suite par la caméra : une seule lecture
      if (code === dernier && maintenant - dernierLe < 2500) { return; }
      dernier = code;
      dernierLe = maintenant;
      if (!options.continu) { enPause = true; }
      options.lu(code);
    }

    function arreter() {
      clearInterval(minuterie);
      minuterie = null;
      if (flux) { flux.getTracks().forEach(function (t) { t.stop(); }); flux = null; }
      video.hidden = true;
      bouton.textContent = T.camera || 'Caméra';
    }

    function demarrer() {
      if (!supporte) { etat.textContent = T.camera_absente || ''; champ.focus(); return; }
      navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (f) {
        flux = f;
        video.srcObject = f;
        video.hidden = false;
        bouton.textContent = T.camera_arreter || 'Stop';
        etat.textContent = '';
        return video.play();
      }).then(function () {
        try { detecteur = detecteur || new window.BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { detecteur = null; }
        if (!detecteur) { etat.textContent = T.camera_absente || ''; arreter(); return; }
        minuterie = setInterval(function () {
          if (enPause || !flux || video.readyState < 2) { return; }
          detecteur.detect(video).then(function (codes) {
            if (codes && codes.length) { lu(codes[0].rawValue); }
          }).catch(function () { /* image illisible : on réessaie */ });
        }, 250);
      }).catch(function () {
        etat.textContent = T.camera_absente || '';
        arreter();
        champ.focus();
      });
    }

    bouton.addEventListener('click', function () { if (flux) { arreter(); } else { demarrer(); } });
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var code = champ.value;
      champ.value = '';
      dernier = '';
      lu(code);
    });
    if (!supporte) { etat.textContent = T.camera_absente || ''; }
    var api = {
      champ: champ,
      arreter: arreter,
      reprendre: function () { enPause = false; dernier = ''; champ.focus(); },
      demarrer: demarrer
    };
    actifs.push(api);
    if (options.auto && supporte) { demarrer(); } else { setTimeout(function () { champ.focus(); }, 50); }
    return api;
  }

  window.ScannerQr = {
    monter: monter,
    // Fermer une fenêtre : toutes les caméras s'arrêtent
    toutArreter: function () { actifs.forEach(function (a) { a.arreter(); }); actifs = []; }
  };
})();
