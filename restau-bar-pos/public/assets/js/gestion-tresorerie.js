/* Trésorerie et dépenses : devises tenues par un compte (case et solde initial), devise de chaque côté
   d'un transfert parmi celles du compte, montant reçu proposé au taux du jour, lignes de paiement d'une
   dépense (compte puis devise du compte, plusieurs devises), soldes et totaux en direct.
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

  /** Devises tenues par le compte d'une option : [[id, solde], …] (ordre : locale, dollar, autres). */
  function devisesDuCompte(option) {
    if (!option || !option.value) return null;
    try { return JSON.parse(option.dataset.devises || '[]'); } catch (e) { return []; }
  }

  /**
   * Remplit la liste des devises avec celles du compte choisi ; garde la devise choisie si le compte la tient,
   * sinon la première qui n'est pas à éviter. Sans compte : la liste reste telle que le serveur l'a écrite.
   */
  function remplirDevises(selectDevise, optionCompte, devises, eviter) {
    var tenues = devisesDuCompte(optionCompte);
    if (tenues === null) return;
    var choisie = selectDevise.value;
    var ids = tenues.map(function (d) { return String(d[0]); });
    if (ids.indexOf(choisie) === -1 || (eviter && eviter.indexOf(choisie) !== -1 && ids.some(function (id) { return eviter.indexOf(id) === -1; }))) {
      choisie = ids.filter(function (id) { return !eviter || eviter.indexOf(id) === -1; })[0] || ids[0] || '';
    }
    selectDevise.innerHTML = '';
    tenues.forEach(function (d) {
      var devise = devises[d[0]];
      if (!devise) return;
      var option = document.createElement('option');
      option.value = String(d[0]);
      option.textContent = devise.code;
      option.selected = option.value === choisie;
      selectDevise.appendChild(option);
    });
  }

  /** Solde du compte choisi dans la devise choisie, ou null. */
  function soldeDans(optionCompte, deviseId) {
    var tenues = devisesDuCompte(optionCompte) || [];
    for (var i = 0; i < tenues.length; i++) {
      if (String(tenues[i][0]) === String(deviseId)) return parseFloat(tenues[i][1]) || 0;
    }
    return null;
  }

  // ---------- Formulaire d'un compte : devises tenues ----------

  document.querySelectorAll('[data-devise-ligne]').forEach(function (ligne) {
    var coche = ligne.querySelector('[data-devise-tenue]');
    var solde = ligne.querySelector('[data-devise-solde]');
    if (!coche || !solde) return;
    var suivre = function () {
      solde.hidden = !coche.checked;
      ligne.classList.toggle('tre-devise--tenue', coche.checked);
    };
    coche.addEventListener('change', function () {
      suivre();
      if (coche.checked) solde.querySelector('input').focus();
    });
    suivre();
  });

  // ---------- Transfert ----------

  var transfert = document.getElementById('formulaire-transfert');
  if (transfert) {
    var devisesT = lireDevises(transfert);
    var cotes = ['source', 'destination'].map(function (nom) {
      return {
        nom: nom,
        compte: transfert.querySelector('[data-compte="' + nom + '"]'),
        devise: transfert.querySelector('[data-devise="' + nom + '"]')
      };
    });
    var montantSource = document.getElementById('montant_source');
    var montantDestination = document.getElementById('montant_destination');
    var blocDestination = transfert.querySelector('[data-bloc-destination]');
    var blocProposition = transfert.querySelector('[data-bloc-proposition]');
    var proposition = transfert.querySelector('[data-proposition]');
    var tauxApplique = transfert.querySelector('[data-taux-applique]');
    var reprendre = transfert.querySelector('[data-reprendre]');
    // Montant reçu tapé à la main : on ne le remplace plus (sauf « Reprendre le montant proposé »)
    var modifieALaMain = montantDestination.value.trim() !== '';
    var dernierPropose = null;

    var lireCote = function (cote) {
      var option = optionChoisie(cote.compte);
      if (!option || !option.value || !cote.devise.value) return null;
      var solde = soldeDans(option, cote.devise.value);
      return { id: option.value, deviseId: cote.devise.value, devise: devisesT[cote.devise.value], solde: solde === null ? 0 : solde };
    };

    var afficherSolde = function (nom, compte, sortie) {
      var zone = transfert.querySelector('[data-solde="' + nom + '"]');
      var symbole = transfert.querySelector('[data-symbole="' + nom + '"]');
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
      var de = lireCote(cotes[0]), vers = lireCote(cotes[1]);
      var montant = lireNombre(montantSource.value);
      afficherSolde('source', de, montant);
      afficherSolde('destination', vers, 0);

      var change = de && vers && de.devise && vers.devise && de.deviseId !== vers.deviseId;
      var memeDevise = !!(de && vers && !change);
      blocDestination.hidden = memeDevise;
      blocProposition.hidden = memeDevise;
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

    /** Devises du côté changé ; même compte des deux côtés : la destination prend une autre devise (change). */
    var changerCompte = function (index) {
      var cote = cotes[index], autre = cotes[1 - index];
      var memeCompte = cote.compte.value && cote.compte.value === autre.compte.value;
      remplirDevises(cote.devise, optionChoisie(cote.compte), devisesT, memeCompte ? [autre.devise.value] : null);
      if (memeCompte && index === 0) remplirDevises(autre.devise, optionChoisie(autre.compte), devisesT, [cote.devise.value]);
      mettreAJour();
    };

    cotes.forEach(function (cote, index) {
      cote.compte.addEventListener('change', function () { changerCompte(index); });
      cote.devise.addEventListener('change', mettreAJour);
      // Liste des devises du compte déjà choisi (formulaire renvoyé par le serveur, ?source=)
      remplirDevises(cote.devise, optionChoisie(cote.compte), devisesT, null);
    });
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

    var lignes = function () { return Array.prototype.slice.call(conteneur.querySelectorAll('[data-paiement]')); };

    var lireLigne = function (ligne) {
      var option = optionChoisie(ligne.querySelector('[data-compte-paiement]'));
      var deviseId = ligne.querySelector('[data-devise-paiement]').value;
      if (!option || !option.value || !deviseId) return null;
      var solde = soldeDans(option, deviseId);
      return { id: option.value, type: option.dataset.type, deviseId: deviseId, devise: devisesD[deviseId], solde: solde === null ? 0 : solde, option: option };
    };

    /** Devises déjà payées depuis ce compte par les autres lignes. */
    var devisesPrises = function (compteId, sauf) {
      var prises = [];
      lignes().forEach(function (ligne) {
        if (ligne === sauf) return;
        var l = lireLigne(ligne);
        if (l && l.id === compteId) prises.push(l.deviseId);
      });
      return prises;
    };

    var mettreAJourLigne = function (ligne) {
      var l = lireLigne(ligne);
      ligne.querySelector('[data-symbole-paiement]').textContent = l && l.devise ? l.devise.symbole : '';
      var zone = ligne.querySelector('[data-solde-paiement]');
      zone.classList.remove('tre-alerte-texte');
      if (!l || !l.devise) { zone.textContent = ''; return; }
      var montant = lireNombre(ligne.querySelector('[data-montant-paiement]').value) || 0;
      var apres = l.solde - montant;
      if (montant && apres < -0.0005) {
        zone.textContent = remplir(depense.dataset.texteDecouvert, { solde: formater(l.solde, l.devise), apres: formater(apres, l.devise) });
        zone.classList.add('tre-alerte-texte');
      } else {
        zone.textContent = remplir(depense.dataset.texteSolde, { solde: formater(l.solde, l.devise) });
      }
    };

    var mettreAJourResume = function () {
      var totaux = {};
      var equivalent = 0, complet = true, nbDevises = 0;
      lignes().forEach(function (ligne) {
        mettreAJourLigne(ligne);
        var l = lireLigne(ligne);
        var montant = lireNombre(ligne.querySelector('[data-montant-paiement]').value);
        if (!l || montant === null || !(montant > 0)) return;
        if (!(l.deviseId in totaux)) nbDevises++;
        totaux[l.deviseId] = (totaux[l.deviseId] || 0) + montant;
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
      var selectCompte = ligne.querySelector('[data-compte-paiement]');
      var selectDevise = ligne.querySelector('[data-devise-paiement]');
      selectCompte.addEventListener('change', function () {
        remplirDevises(selectDevise, optionChoisie(selectCompte), devisesD, devisesPrises(selectCompte.value, ligne));
        mettreAJourResume();
      });
      selectDevise.addEventListener('change', mettreAJourResume);
      ligne.querySelector('[data-montant-paiement]').addEventListener('input', mettreAJourResume);
      ligne.querySelector('[data-retirer]').addEventListener('click', function () {
        var suivante = ligne.nextElementSibling || ligne.previousElementSibling;
        ligne.remove();
        renumeroter();
        mettreAJourResume();
        if (suivante) suivante.querySelector('[data-montant-paiement]').focus();
      });
      // Devises du compte déjà choisi (formulaire renvoyé par le serveur)
      remplirDevises(selectDevise, optionChoisie(selectCompte), devisesD, null);
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
        var avant = precedente ? lireLigne(precedente) : null;
        var html = modele.innerHTML.replace(/__i__/g, String(compteur)).replace(/__n__/g, String(lignes().length + 1));
        compteur++;
        var tampon = document.createElement('div');
        tampon.innerHTML = html.trim();
        var ligne = tampon.firstElementChild;
        conteneur.appendChild(ligne);

        // « Dans une autre devise » : le même compte s'il tient une devise pas encore payée, sinon un compte
        // du même type qui en tient une, sinon le premier compte qui en tient une
        var selectCompte = ligne.querySelector('[data-compte-paiement]');
        var payees = {};
        lignes().forEach(function (autre) { var l = lireLigne(autre); if (l) payees[l.deviseId] = true; });
        var libre = function (option) {
          return (devisesDuCompte(option) || []).some(function (d) { return !payees[String(d[0])]; });
        };
        var options = Array.prototype.slice.call(selectCompte.querySelectorAll('option[value]:not([value=""])'));
        var choix = null;
        if (avant) {
          choix = options.filter(function (o) { return o.value === avant.id && libre(o); })[0]
            || options.filter(function (o) { return o.dataset.type === avant.type && libre(o); })[0];
        }
        choix = choix || options.filter(libre)[0] || (avant ? options.filter(function (o) { return o.value === avant.id; })[0] : null);
        if (choix) selectCompte.value = choix.value;
        brancher(ligne);
        if (choix) remplirDevises(ligne.querySelector('[data-devise-paiement]'), choix, devisesD, Object.keys(payees));
        mettreAJourResume();
        ligne.querySelector('[data-montant-paiement]').focus();
      });
    }

    mettreAJourResume();
  }
})();
