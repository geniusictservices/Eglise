/* Trésorerie et dépenses : symbole de la devise du compte, montant reçu proposé au taux du jour,
   lignes de paiement d'une dépense (moyen puis compte, plusieurs devises), totaux en direct.
   Mêmes règles que DeviseRepository::arrondir() et ::formater() côté serveur.
   Sans JavaScript, les formulaires fonctionnent : le serveur propose le montant reçu et ajoute les lignes. */
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
    var entier = parties[0].replace(/\B(?=(\d{3})+(?!\d))/g, langue === 'fr' ? ' ' : ',');
    return entier + (parties[1] ? (langue === 'fr' ? ',' : '.') + parties[1] : '');
  }

  /** « 990 $ », « −150 $ », « 3 605 000 FC » (comme TresorerieAffichage::montant). */
  function formater(montant, devise) {
    var decimales = devise.decimales;
    if (Math.abs(montant - Math.round(montant)) < 0.005) decimales = 0;
    var nombre = formaterNombre(montant, decimales);
    var texte = devise.avant ? devise.symbole + nombre : nombre + ' ' + devise.symbole;
    return (montant < -0.0005 ? '−' : '') + texte;
  }

  /** Valeur d'un champ de saisie : « 142500 », « 12,5 ». */
  function saisie(montant, devise) {
    var texte = String(arrondirA(montant, devise.decimales));
    return langue === 'fr' ? texte.replace('.', ',') : texte;
  }

  /** « 1 USD = 2 850 CDF » dans le sens où le taux dépasse 1. */
  function texteTaux(de, vers, taux) {
    if (!(taux > 0)) return '';
    if (taux < 1) { var c = de; de = vers; vers = c; taux = 1 / taux; }
    var decimales = taux >= 100 ? 2 : 4;
    var nombre = formaterNombre(arrondirA(taux, decimales), decimales).replace(/[,.]?0+$/, '');
    return '1 ' + de + ' = ' + nombre + ' ' + vers;
  }

  function remplir(modele, valeurs) {
    return String(modele || '').replace(/\{(\w+)\}/g, function (tout, cle) {
      return Object.prototype.hasOwnProperty.call(valeurs, cle) ? valeurs[cle] : tout;
    });
  }

  function lireDevises(formulaire) {
    try { return JSON.parse(formulaire.dataset.devises || '{}'); } catch (e) { return {}; }
  }

  function optionChoisie(select) {
    return select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
  }

  // ---------- Fiche d'un compte : symbole de la devise ----------

  var choixDevise = document.querySelector('[data-devise-compte]');
  var symboleCompte = document.querySelector('[data-symbole-compte]');
  if (choixDevise && symboleCompte) {
    choixDevise.addEventListener('change', function () {
      var option = optionChoisie(choixDevise);
      symboleCompte.textContent = option ? option.dataset.symbole || '' : '';
    });
  }

  // ---------- Transfert ----------

  var transfert = document.getElementById('formulaire-transfert');
  if (transfert) {
    var devisesT = lireDevises(transfert);
    var source = transfert.querySelector('[data-compte="source"]');
    var destination = transfert.querySelector('[data-compte="destination"]');
    var montantSource = document.getElementById('montant_source');
    var montantDestination = document.getElementById('montant_destination');
    var blocDestination = transfert.querySelector('[data-bloc-destination]');
    var proposition = transfert.querySelector('[data-proposition]');
    var tauxApplique = transfert.querySelector('[data-taux-applique]');
    var reprendre = transfert.querySelector('[data-reprendre]');
    // Montant reçu tapé à la main : on ne le remplace plus (sauf « Reprendre le montant proposé »)
    var modifieALaMain = montantDestination.value.trim() !== '';
    var dernierPropose = null;

    var compteDe = function (select) {
      var option = optionChoisie(select);
      if (!option || !option.value) return null;
      return { id: option.value, devise: devisesT[option.dataset.devise], solde: parseFloat(option.dataset.solde) || 0 };
    };

    var afficherSolde = function (cote, compte, sortie) {
      var zone = transfert.querySelector('[data-solde="' + cote + '"]');
      var symbole = transfert.querySelector('[data-symbole="' + cote + '"]');
      if (symbole) symbole.textContent = compte && compte.devise ? compte.devise.symbole : '';
      if (!zone) return;
      zone.classList.remove('tre-alerte-texte');
      if (!compte || !compte.devise) { zone.textContent = ''; return; }
      var apres = compte.solde - (sortie || 0);
      if (sortie && apres < -0.0005) {
        zone.textContent = remplir(transfert.dataset.texteDecouvert, { solde: formater(compte.solde, compte.devise), apres: formater(apres, compte.devise) });
        zone.classList.add('tre-alerte-texte');
      } else {
        zone.textContent = remplir(transfert.dataset.texteSolde, { solde: formater(compte.solde, compte.devise) });
      }
    };

    var mettreAJour = function () {
      var de = compteDe(source), vers = compteDe(destination);
      var montant = lireNombre(montantSource.value);
      afficherSolde('source', de, montant);
      afficherSolde('destination', vers, 0);

      var change = de && vers && de.devise && vers.devise && de.devise.code !== vers.devise.code;
      blocDestination.hidden = !!(de && vers && !change);
      tauxApplique.textContent = '';
      reprendre.hidden = true;
      if (!change) { dernierPropose = null; return; }

      var propose = null;
      if (montant !== null && montant > 0 && de.devise.taux > 0 && vers.devise.taux > 0) {
        propose = arrondir(montant / de.devise.taux * vers.devise.taux, vers.devise);
      }
      dernierPropose = propose;
      if (propose === null) {
        proposition.textContent = de.devise.taux > 0 && vers.devise.taux > 0 ? '' : remplir(transfert.dataset.texteSansTaux, {});
      } else {
        proposition.textContent = remplir(transfert.dataset.textePropose, {
          taux: texteTaux(de.devise.code, vers.devise.code, vers.devise.taux / de.devise.taux),
          montant: formater(propose, vers.devise)
        });
        if (!modifieALaMain) montantDestination.value = saisie(propose, vers.devise);
      }
      var recu = lireNombre(montantDestination.value);
      if (montant && recu) {
        tauxApplique.textContent = remplir(transfert.dataset.texteTaux, { taux: texteTaux(de.devise.code, vers.devise.code, recu / montant) });
      }
      reprendre.hidden = !(modifieALaMain && propose !== null && recu !== propose);
    };

    [source, destination].forEach(function (select) { select.addEventListener('change', mettreAJour); });
    montantSource.addEventListener('input', mettreAJour);
    montantDestination.addEventListener('input', function () {
      modifieALaMain = montantDestination.value.trim() !== '';
      mettreAJour();
    });
    reprendre.addEventListener('click', function () {
      modifieALaMain = false;
      mettreAJour();
      montantDestination.focus();
    });
    mettreAJour();
  }

  // ---------- Dépense : lignes de paiement ----------

  var depense = document.getElementById('formulaire-depense');
  if (depense) {
    var devisesD = lireDevises(depense);
    var ordre = (depense.dataset.ordre || '').split(',');
    var referenceId = depense.dataset.reference;
    var max = parseInt(depense.dataset.max, 10) || 6;
    var conteneur = depense.querySelector('[data-paiements]');
    var modele = document.getElementById('modele-paiement');
    var boutonAjouter = depense.querySelector('[data-ajouter-paiement]');
    var resume = depense.querySelector('[data-resume]');
    var compteur = conteneur.querySelectorAll('[data-paiement]').length;

    // Toutes les options de compte, pour ne montrer que celles du moyen choisi
    var toutesOptions = [];
    if (modele) {
      modele.content.querySelectorAll('[data-compte-paiement] option[value]:not([value=""])').forEach(function (option) {
        toutesOptions.push(option);
      });
    }

    var lignes = function () { return Array.prototype.slice.call(conteneur.querySelectorAll('[data-paiement]')); };

    var compteLigne = function (ligne) {
      var option = optionChoisie(ligne.querySelector('[data-compte-paiement]'));
      if (!option || !option.value) return null;
      return { id: option.value, type: option.dataset.type, devise: option.dataset.devise, solde: parseFloat(option.dataset.solde) || 0 };
    };

    var moyenLigne = function (ligne) {
      var coche = ligne.querySelector('[data-moyen]:checked');
      return coche ? coche.value : 'especes';
    };

    /** Remplit la liste des comptes avec ceux du moyen choisi (le compte choisi reste s'il en fait partie). */
    var filtrerComptes = function (ligne, preferer) {
      var select = ligne.querySelector('[data-compte-paiement]');
      var moyen = moyenLigne(ligne);
      var choisi = preferer || select.value;
      var premiere = select.options[0];
      select.innerHTML = '';
      select.appendChild(premiere);
      var trouve = false, nb = 0;
      toutesOptions.forEach(function (option) {
        if (option.dataset.type !== moyen) return;
        var copie = option.cloneNode(true);
        copie.selected = copie.value === choisi;
        if (copie.selected) trouve = true;
        select.appendChild(copie);
        nb++;
      });
      if (!trouve) {
        // Un seul compte pour ce moyen : il est choisi d'office
        select.selectedIndex = nb === 1 ? 1 : 0;
      }
      premiere.textContent = nb === 0 ? depense.dataset.texteAucunCompte : premiere.dataset.texte || premiere.textContent;
    };

    var devisesUtilisees = function (sauf) {
      var codes = {};
      lignes().forEach(function (ligne) {
        if (ligne === sauf) return;
        var compte = compteLigne(ligne);
        if (compte) codes[compte.devise] = true;
      });
      return codes;
    };

    var mettreAJourLigne = function (ligne) {
      var compte = compteLigne(ligne);
      var devise = compte ? devisesD[compte.devise] : null;
      ligne.querySelector('[data-symbole-paiement]').textContent = devise ? devise.symbole : '';
      var zone = ligne.querySelector('[data-solde-paiement]');
      zone.classList.remove('tre-alerte-texte');
      if (!compte || !devise) { zone.textContent = ''; return; }
      var montant = lireNombre(ligne.querySelector('[data-montant-paiement]').value) || 0;
      var apres = compte.solde - montant;
      if (montant && apres < -0.0005) {
        zone.textContent = remplir(depense.dataset.texteDecouvert, { solde: formater(compte.solde, devise), apres: formater(apres, devise) });
        zone.classList.add('tre-alerte-texte');
      } else {
        zone.textContent = remplir(depense.dataset.texteSolde, { solde: formater(compte.solde, devise) });
      }
    };

    var mettreAJourResume = function () {
      var totaux = {};
      var equivalent = 0, complet = true, nbDevises = 0;
      lignes().forEach(function (ligne) {
        mettreAJourLigne(ligne);
        var compte = compteLigne(ligne);
        var montant = lireNombre(ligne.querySelector('[data-montant-paiement]').value);
        if (!compte || montant === null || !(montant > 0)) return;
        if (!(compte.devise in totaux)) nbDevises++;
        totaux[compte.devise] = (totaux[compte.devise] || 0) + montant;
      });
      var textes = [];
      ordre.forEach(function (id) {
        if (!(id in totaux) || !devisesD[id]) return;
        textes.push(formater(totaux[id], devisesD[id]));
        if (devisesD[id].taux > 0) equivalent += totaux[id] / devisesD[id].taux; else complet = false;
      });
      resume.hidden = textes.length === 0;
      resume.querySelector('[data-resume-montants]').textContent = textes.join(' + ');
      var reference = devisesD[referenceId];
      var texteEquivalent = '';
      if (reference && complet && (nbDevises > 1 || !(referenceId in totaux))) {
        texteEquivalent = '≈ ' + formater(arrondirA(equivalent, reference.decimales), reference);
      }
      resume.querySelector('[data-resume-equivalent]').textContent = texteEquivalent;

      var nb = lignes().length;
      lignes().forEach(function (ligne) { ligne.querySelector('[data-retirer]').hidden = nb < 2; });
      if (boutonAjouter) boutonAjouter.hidden = nb >= max;
    };

    var brancher = function (ligne) {
      ligne.querySelector('[data-compte-paiement] option[value=""]').dataset.texte = ligne.querySelector('[data-compte-paiement] option[value=""]').textContent;
      ligne.querySelectorAll('[data-moyen]').forEach(function (radio) {
        radio.addEventListener('change', function () {
          filtrerComptes(ligne);
          mettreAJourResume();
        });
      });
      ligne.querySelector('[data-compte-paiement]').addEventListener('change', mettreAJourResume);
      ligne.querySelector('[data-montant-paiement]').addEventListener('input', mettreAJourResume);
      ligne.querySelector('[data-retirer]').addEventListener('click', function () {
        var suivante = ligne.nextElementSibling || ligne.previousElementSibling;
        ligne.remove();
        renumeroter();
        mettreAJourResume();
        if (suivante) suivante.querySelector('[data-montant-paiement]').focus();
      });
      // Le moyen coché suit le compte choisi (formulaire renvoyé par le serveur)
      var compte = compteLigne(ligne);
      if (compte) {
        var radio = ligne.querySelector('[data-moyen][value="' + compte.type + '"]');
        if (radio) radio.checked = true;
      }
      filtrerComptes(ligne);
    };

    var renumeroter = function () {
      lignes().forEach(function (ligne, i) {
        var titre = ligne.querySelector('.tre-paiement__titre');
        if (titre) titre.textContent = titre.textContent.replace(/\d+/, String(i + 1));
      });
    };

    lignes().forEach(brancher);

    if (boutonAjouter && modele) {
      boutonAjouter.addEventListener('click', function (evenement) {
        evenement.preventDefault();
        if (lignes().length >= max) return;
        var precedente = lignes()[lignes().length - 1];
        var html = modele.innerHTML.replace(/__i__/g, String(compteur)).replace(/__n__/g, String(lignes().length + 1));
        compteur++;
        var tampon = document.createElement('div');
        tampon.innerHTML = html.trim();
        var ligne = tampon.firstElementChild;
        conteneur.appendChild(ligne);

        // Même moyen que la ligne précédente, et un compte dans une autre devise
        var moyen = precedente ? moyenLigne(precedente) : 'especes';
        var radio = ligne.querySelector('[data-moyen][value="' + moyen + '"]');
        if (radio) radio.checked = true;
        var utilisees = devisesUtilisees(ligne);
        var prefere = null;
        toutesOptions.some(function (option) {
          if (option.dataset.type === moyen && !utilisees[option.dataset.devise]) { prefere = option.value; return true; }
          return false;
        });
        brancher(ligne);
        if (prefere) filtrerComptes(ligne, prefere);
        mettreAJourResume();
        ligne.querySelector('[data-montant-paiement]').focus();
      });
    }

    mettreAJourResume();
  }
})();
