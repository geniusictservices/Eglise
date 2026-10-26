/* Devises et taux : exemple de conversion en direct pour chaque taux, aperçu du format d'une devise.
   Mêmes règles que DeviseRepository::arrondir() et ::formater() côté serveur. */
(function () {
  'use strict';

  var langue = document.documentElement.lang === 'en' ? 'en' : 'fr';

  /** Lit un nombre saisi (« 2850 », « 2 850 », « 0,92 », « 2,850.50 »), ou null. */
  function lireNombre(texte) {
    texte = String(texte).replace(/[\s  ']+/g, '');
    if (texte === '') return null;
    var virgule = texte.lastIndexOf(','), point = texte.lastIndexOf('.');
    if (virgule !== -1 && point !== -1) {
      var decimal = virgule > point ? ',' : '.';
      texte = texte.split(decimal === ',' ? '.' : ',').join('').replace(',', '.');
    } else if (virgule !== -1) {
      texte = langue === 'en' && /^\d{1,3}(,\d{3})+$/.test(texte) ? texte.split(',').join('') : texte.replace(',', '.');
    } else if (point !== -1 && langue === 'fr' && /^\d{1,3}(\.\d{3}){2,}$/.test(texte)) {
      texte = texte.split('.').join('');
    }
    if (!/^\d+(\.\d+)?$/.test(texte)) return null;
    return parseFloat(texte);
  }

  function arrondirA(valeur, decimales) {
    var facteur = Math.pow(10, decimales);
    return Math.round((valeur + Number.EPSILON) * facteur) / facteur;
  }

  function arrondir(montant, devise) {
    if (devise.arrondi > 0) montant = Math.round(montant / devise.arrondi) * devise.arrondi;
    return arrondirA(montant, devise.decimales);
  }

  function formaterNombre(montant, decimales) {
    var parties = Math.abs(montant).toFixed(decimales).split('.');
    var milliers = langue === 'fr' ? ' ' : ',';
    var entier = parties[0].replace(/\B(?=(\d{3})+(?!\d))/g, milliers);
    var texte = entier + (parties[1] ? (langue === 'fr' ? ',' : '.') + parties[1] : '');
    return (montant < 0 ? '-' : '') + texte;
  }

  function formater(montant, devise, compact) {
    var decimales = devise.decimales;
    if (compact && Math.abs(montant - Math.round(montant)) < 0.005) decimales = 0;
    var nombre = formaterNombre(montant, decimales);
    return devise.avant ? devise.symbole + nombre : nombre + ' ' + devise.symbole;
  }

  // ---------- Taux du jour ----------

  var formulaireTaux = document.getElementById('formulaire-taux');
  if (formulaireTaux) {
    var exemple = parseFloat(formulaireTaux.dataset.exemple) || 10;
    var exempleTexte = formulaireTaux.dataset.exempleTexte || '';
    var texteInvalide = formulaireTaux.dataset.texteInvalide || '';

    formulaireTaux.querySelectorAll('[data-devise]').forEach(function (ligne) {
      var champ = ligne.querySelector('input');
      var sortie = ligne.querySelector('.dev-ligne-taux__exemple');
      var marque = ligne.querySelector('.dev-modifie');
      var devise = {
        decimales: parseInt(ligne.dataset.decimales, 10) || 0,
        arrondi: parseFloat(ligne.dataset.arrondi) || 0,
        symbole: ligne.dataset.symbole || '',
        avant: ligne.dataset.avant === '1'
      };
      var initial = lireNombre(ligne.dataset.initial || '');

      function actualiser() {
        var taux = lireNombre(champ.value);
        if (champ.value.trim() === '') {
          sortie.textContent = '';
        } else if (taux === null || taux <= 0) {
          sortie.textContent = texteInvalide;
          sortie.classList.add('dev-ligne-taux__exemple--invalide');
        } else {
          sortie.classList.remove('dev-ligne-taux__exemple--invalide');
          sortie.textContent = exempleTexte + ' → ' + formater(arrondir(exemple * taux, devise), devise, true);
        }
        var modifie = taux !== null && taux > 0 && (initial === null || Math.abs(taux - initial) >= 0.0000005);
        if (marque) marque.hidden = !modifie;
        ligne.classList.toggle('dev-ligne-taux--modifiee', modifie);
      }

      champ.addEventListener('input', actualiser);
      actualiser();
    });
  }

  // ---------- Fiche d'une devise : aperçu du format ----------

  var formulaireDevise = document.getElementById('formulaire-devise');
  if (formulaireDevise) {
    var apercu = document.getElementById('apercu-montant');
    var code = document.getElementById('code');
    var montant = parseFloat(apercu.dataset.montant) || 1234.5;

    function valeurCochee(nom) {
      var choix = formulaireDevise.querySelector('input[name="' + nom + '"]:checked');
      return choix ? choix.value : null;
    }

    function actualiserApercu() {
      var symbole = document.getElementById('symbole').value.trim() || code.value.trim().toUpperCase() || '?';
      var arrondi = lireNombre(document.getElementById('arrondi').value || '0');
      var devise = {
        decimales: parseInt(valeurCochee('decimales') || '0', 10),
        arrondi: arrondi === null ? 0 : arrondi,
        symbole: symbole,
        avant: valeurCochee('symbole_avant') === '1'
      };
      apercu.textContent = formater(arrondir(montant, devise), devise, false);
    }

    code.addEventListener('input', function () {
      var position = code.selectionStart;
      code.value = code.value.toUpperCase();
      if (position !== null) code.setSelectionRange(position, position);
      actualiserApercu();
    });
    formulaireDevise.querySelectorAll('[data-apercu]').forEach(function (champ) {
      champ.addEventListener('input', actualiserApercu);
      champ.addEventListener('change', actualiserApercu);
    });
    actualiserApercu();
  }
})();
