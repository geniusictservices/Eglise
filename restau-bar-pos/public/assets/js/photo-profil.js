// Photo de profil : aperçu dès qu'une image est choisie (ou prise), avant l'envoi.
(function () {
  'use strict';
  document.querySelectorAll('[data-photo-profil]').forEach(function (form) {
    var champ = form.querySelector('input[type=file]');
    var envoyer = form.querySelector('[data-envoyer]');
    var apercu = form.querySelector('[data-apercu] .avatar');
    if (!champ || !envoyer || !apercu) { return; }
    envoyer.disabled = true;
    champ.addEventListener('change', function () {
      var fichier = champ.files && champ.files[0];
      envoyer.disabled = !fichier;
      if (!fichier || !window.URL) { return; }
      var img = document.createElement('img');
      img.alt = '';
      img.src = URL.createObjectURL(fichier);
      apercu.textContent = '';
      apercu.appendChild(img);
    });
  });
})();
