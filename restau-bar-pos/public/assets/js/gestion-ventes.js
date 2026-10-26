// Fiche d'une vente : « Imprimer le reçu » (la feuille d'impression ne garde que le reçu, en 80 mm).
(function () {
  'use strict';
  document.querySelectorAll('[data-imprimer]').forEach(function (bouton) {
    bouton.hidden = false;
    bouton.addEventListener('click', function () { window.print(); });
  });
})();
