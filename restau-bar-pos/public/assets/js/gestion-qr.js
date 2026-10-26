// Codes QR de l'espace de gestion : dessin, téléchargement en SVG, impression.
(function () {
  'use strict';

  function svgPour(texte) {
    var qr = qrcode(0, 'M');
    qr.addData(texte);
    qr.make();
    return qr.createSvgTag({ cellSize: 8, margin: 16, scalable: true });
  }

  document.querySelectorAll('[data-qr]').forEach(function (conteneur) {
    conteneur.innerHTML = svgPour(conteneur.getAttribute('data-qr'));
  });

  document.querySelectorAll('[data-telecharger]').forEach(function (bouton) {
    bouton.addEventListener('click', function () {
      var carte = bouton.closest('.carte-qr');
      var svg = carte.querySelector('[data-qr] svg');
      var fichier = new Blob(['<?xml version="1.0" encoding="UTF-8"?>\n' + svg.outerHTML], { type: 'image/svg+xml' });
      var lien = document.createElement('a');
      lien.href = URL.createObjectURL(fichier);
      lien.download = 'qr-' + bouton.getAttribute('data-telecharger') + '.svg';
      document.body.appendChild(lien);
      lien.click();
      setTimeout(function () { URL.revokeObjectURL(lien.href); lien.remove(); }, 0);
    });
  });

  document.querySelectorAll('[data-imprimer]').forEach(function (bouton) {
    bouton.addEventListener('click', function () { window.print(); });
  });

  // Formulaire : ne proposer que les zones de l'établissement choisi
  var etablissement = document.getElementById('etablissement_id');
  var zone = document.getElementById('zone_id');
  if (etablissement && zone) {
    var filtrer = function () {
      zone.querySelectorAll('optgroup').forEach(function (groupe) {
        var visible = groupe.getAttribute('data-etablissement') === etablissement.value;
        groupe.hidden = !visible;
        groupe.disabled = !visible;
      });
      var choisie = zone.selectedOptions[0];
      if (choisie && choisie.parentElement.disabled) {
        zone.value = '';
      }
    };
    etablissement.addEventListener('change', filtrer);
    filtrer();
  }
})();
