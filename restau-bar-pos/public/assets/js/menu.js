// Menu public : recherche instantanée et catégorie active dans la barre de navigation.
(function () {
  'use strict';

  var champ = document.getElementById('recherche');
  var aucun = document.getElementById('recherche-aucun');
  var sections = Array.prototype.slice.call(document.querySelectorAll('[data-categorie]'));

  function normaliser(texte) {
    return texte.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  }

  if (champ) {
    sections.forEach(function (section) {
      section.querySelectorAll('[data-produit]').forEach(function (produit) {
        produit.dataset.rechercheNormalisee = normaliser(produit.dataset.recherche || '');
      });
    });

    champ.addEventListener('input', function () {
      var termes = normaliser(champ.value.trim()).split(/\s+/).filter(Boolean);
      var total = 0;
      sections.forEach(function (section) {
        var visibles = 0;
        section.querySelectorAll('[data-produit]').forEach(function (produit) {
          var texte = produit.dataset.rechercheNormalisee;
          var correspond = termes.every(function (terme) { return texte.indexOf(terme) !== -1; });
          produit.hidden = !correspond;
          if (correspond) { visibles++; }
        });
        section.hidden = visibles === 0;
        total += visibles;
      });
      aucun.hidden = total !== 0;
    });
  }

  // Met en évidence la catégorie visible à l'écran
  var liens = {};
  document.querySelectorAll('.categories__lien').forEach(function (lien) {
    liens[lien.getAttribute('href').slice(1)] = lien;
  });
  if ('IntersectionObserver' in window && sections.length) {
    var observateur = new IntersectionObserver(function (entrees) {
      entrees.forEach(function (entree) {
        if (!entree.isIntersecting) { return; }
        Object.keys(liens).forEach(function (id) { liens[id].classList.remove('categories__lien--actif'); });
        var lien = liens[entree.target.id];
        if (lien) {
          lien.classList.add('categories__lien--actif');
          // Fait défiler la barre horizontalement sans toucher au défilement de la page
          var barre = lien.parentElement;
          barre.scrollTo({ left: lien.offsetLeft - (barre.clientWidth - lien.offsetWidth) / 2, behavior: 'smooth' });
        }
      });
    }, { rootMargin: '-40% 0px -55% 0px' });
    sections.forEach(function (section) { observateur.observe(section); });
  }
})();
