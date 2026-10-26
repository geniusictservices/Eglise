// Rapports : bouton « Imprimer / PDF » (fenêtre d'impression du navigateur ; sans JavaScript, le lien ouvre
// la présentation d'impression), et bulle d'information des graphiques (survol, toucher ou clavier sur
// un élément [data-info] ; le titre SVG reste la bulle native et le tableau des données reste lisible).
(function () {
  'use strict';

  document.addEventListener('click', function (evenement) {
    var lien = evenement.target.closest && evenement.target.closest('[data-imprimer]');
    if (lien && typeof window.print === 'function') {
      evenement.preventDefault();
      window.print();
    }
  });

  var bulle = null;
  function montrer(cible) {
    var texte = cible.getAttribute('data-info');
    if (!texte) { return; }
    if (!bulle) {
      bulle = document.createElement('div');
      bulle.className = 'rap-bulle';
      bulle.setAttribute('role', 'status');
      document.body.appendChild(bulle);
    }
    bulle.textContent = texte;
    bulle.hidden = false;
    var r = cible.getBoundingClientRect();
    var largeur = bulle.offsetWidth;
    var gauche = Math.min(Math.max(8, r.left + r.width / 2 - largeur / 2), document.documentElement.clientWidth - largeur - 8);
    var haut = r.top + window.scrollY - bulle.offsetHeight - 8;
    if (haut < window.scrollY + 4) { haut = r.bottom + window.scrollY + 8; }
    bulle.style.left = gauche + 'px';
    bulle.style.top = haut + 'px';
  }
  function cacher() { if (bulle) { bulle.hidden = true; } }

  ['mouseover', 'focusin'].forEach(function (type) {
    document.addEventListener(type, function (evenement) {
      var cible = evenement.target.closest && evenement.target.closest('[data-info]');
      if (cible) { montrer(cible); }
    });
  });
  ['mouseout', 'focusout'].forEach(function (type) {
    document.addEventListener(type, function (evenement) {
      if (evenement.target.closest && evenement.target.closest('[data-info]')) { cacher(); }
    });
  });
  document.addEventListener('keydown', function (evenement) { if (evenement.key === 'Escape') { cacher(); } });
  window.addEventListener('scroll', cacher, { passive: true });
})();
