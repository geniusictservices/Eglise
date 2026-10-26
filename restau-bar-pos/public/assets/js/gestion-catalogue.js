/* Gestion du catalogue : disponibilité en un toucher, prix calculés pendant la saisie,
   rappel des modifications non enregistrées. Tout fonctionne aussi sans JavaScript. */
(function () {
  'use strict';

  // Filtres de la liste : un choix dans une liste déroulante relance la recherche
  document.querySelectorAll('select[data-auto]').forEach(function (liste) {
    liste.addEventListener('change', function () { liste.form.submit(); });
  });

  // Disponible / épuisé sans recharger la page
  var annonce = document.querySelector('[data-annonce]');
  document.querySelectorAll('form[data-bascule]').forEach(function (form) {
    form.addEventListener('submit', function (evt) {
      if (!window.fetch || !window.FormData) { return; }
      evt.preventDefault();
      var bouton = form.querySelector('button');
      if (bouton.getAttribute('aria-busy') === 'true') { return; }
      bouton.setAttribute('aria-busy', 'true');
      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json' },
        credentials: 'same-origin'
      }).then(function (reponse) {
        if (!reponse.ok) { throw new Error(String(reponse.status)); }
        return reponse.json();
      }).then(function (donnees) {
        var epuise = !donnees.disponible;
        bouton.setAttribute('aria-pressed', epuise ? 'true' : 'false');
        bouton.querySelector('.cat-bascule__texte').textContent = epuise ? bouton.dataset.texteEpuise : bouton.dataset.texteDisponible;
        bouton.title = epuise ? bouton.dataset.aideEpuise : bouton.dataset.aideDisponible;
        var ligne = form.closest('.cat-produit');
        if (ligne) { ligne.classList.toggle('cat-produit--epuise', epuise); }
        if (annonce) { annonce.textContent = donnees.message; annonce.hidden = false; }
      }).catch(function () {
        form.submit(); // repli : envoi classique du formulaire
      }).then(function () {
        bouton.removeAttribute('aria-busy');
      });
    });
  });

  // Modifications non enregistrées
  var envoi = false;
  var suivis = document.querySelectorAll('form[data-suivi]');
  suivis.forEach(function (form) {
    var marquer = function () {
      form.dataset.modifie = '1';
      var note = form.querySelector('.cat-modifie');
      if (note) { note.hidden = false; }
    };
    form.addEventListener('input', marquer);
    form.addEventListener('change', marquer);
    form.addEventListener('submit', function () { envoi = true; });
  });
  window.addEventListener('beforeunload', function (evt) {
    if (envoi) { return; }
    for (var i = 0; i < suivis.length; i++) {
      if (suivis[i].dataset.modifie) { evt.preventDefault(); evt.returnValue = ''; return; }
    }
  });

  // Photo : voir champ-image.js (glisser-déposer, médiathèque, aperçu, taille vérifiée avant l'envoi)

  // Prix calculés : une case vide affiche le prix que le menu calculera
  document.querySelectorAll('[data-calcul]').forEach(function (zone) {
    var calcul = JSON.parse(zone.dataset.calcul);
    var devises = {};
    calcul.devises.forEach(function (d) { devises[d.id] = d; });
    var cases = zone.querySelectorAll('input[data-devise]');

    function lire(texte) {
      var propre = String(texte).replace(/[\s  ']+/g, '');
      if (propre.indexOf(',') !== -1 && propre.indexOf('.') !== -1) {
        propre = propre.lastIndexOf(',') > propre.lastIndexOf('.') ? propre.replace(/\./g, '') : propre.replace(/,/g, '');
      }
      propre = propre.replace(',', '.');
      return /^\d+(\.\d+)?$/.test(propre) && Number(propre) > 0 ? Number(propre) : null;
    }

    function arrondir(montant, devise) {
      if (devise.arrondi > 0) { montant = Math.round(montant / devise.arrondi) * devise.arrondi; }
      var facteur = Math.pow(10, devise.decimales);
      return Math.round(montant * facteur) / facteur;
    }

    function formater(montant, devise) {
      var parties = montant.toFixed(devise.decimales).split('.');
      var milliers = calcul.langue === 'fr' ? ' ' : ',';
      var nombre = parties[0].replace(/\B(?=(\d{3})+(?!\d))/g, milliers);
      if (parties[1]) { nombre += (calcul.langue === 'fr' ? ',' : '.') + parties[1]; }
      return devise.symboleAvant ? devise.symbole + nombre : nombre + ' ' + devise.symbole;
    }

    function convertir(montant, devise) {
      if (devise.taux === null || devise.taux <= 0) { return null; }
      return arrondir(montant * devise.taux, devise);
    }

    // Même ordre de recherche que le menu : grille, puis grille par défaut
    function automatique(valeurs, format, grille, deviseId) {
      var parGrille = valeurs[format] || {};
      var ref = calcul.reference;
      var devise = devises[deviseId];
      var chaine = [grille];
      if (calcul.grilleDefaut !== grille) { chaine.push(calcul.grilleDefaut); }
      for (var i = 0; i < chaine.length; i++) {
        var prix = parGrille[chaine[i]] || {};
        if (i > 0 && prix[deviseId] != null) { return prix[deviseId]; }
        if (ref !== null && deviseId !== ref && prix[ref] != null) { return convertir(prix[ref], devise); }
      }
      return null;
    }

    function mettreAJour() {
      var valeurs = {};
      cases.forEach(function (c) {
        var f = c.dataset.format, g = Number(c.dataset.grille), d = Number(c.dataset.devise);
        valeurs[f] = valeurs[f] || {};
        valeurs[f][g] = valeurs[f][g] || {};
        valeurs[f][g][d] = c.value.trim() === '' ? null : lire(c.value);
      });
      cases.forEach(function (c) {
        if (c.value.trim() !== '') { c.placeholder = ''; return; }
        var d = Number(c.dataset.devise);
        var montant = automatique(valeurs, c.dataset.format, Number(c.dataset.grille), d);
        c.placeholder = montant === null ? '' : calcul.auto.replace('{montant}', formater(montant, devises[d]));
      });
    }

    zone.addEventListener('input', function (evt) {
      if (evt.target.matches('input[data-devise]')) { mettreAJour(); }
    });
    mettreAJour();
  });

  // Création : l'unité de base met à jour le libellé de la quantité et le nom du premier format
  var nouveau = document.querySelector('form[data-nouveau]');
  if (nouveau) {
    var unites = JSON.parse(nouveau.dataset.unites);
    var defauts = JSON.parse(nouveau.dataset.formatsDefaut);
    var choix = nouveau.querySelector('select[data-unite]');
    var noms = { fr: nouveau.querySelector('[data-format-nom="fr"]'), en: nouveau.querySelector('[data-format-nom="en"]') };
    var precedent = choix ? choix.value : '';
    var nomParDefaut = function (unite, langue) { return defauts[unite] ? defauts[unite][langue] : ''; };
    if (choix) {
      choix.addEventListener('change', function () {
        nouveau.querySelectorAll('[data-unite-libelle]').forEach(function (s) { s.textContent = unites[choix.value] || ''; });
        ['fr', 'en'].forEach(function (langue) {
          var champ = noms[langue];
          // On ne remplace que le nom proposé automatiquement, jamais un nom saisi
          if (champ && (champ.value === '' || champ.value === nomParDefaut(precedent, langue))) {
            champ.value = nomParDefaut(choix.value, langue);
          }
        });
        precedent = choix.value;
      });
    }
  }
})();
