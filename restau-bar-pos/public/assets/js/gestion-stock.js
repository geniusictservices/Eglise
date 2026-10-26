/* Stock et achats : lignes « article · format, quantité (prix) » ajoutées et retirées sans recharger,
   contenu en unités de base et stock de la source en direct, total d'une réception ou d'une commande,
   lignes de paiement (compte puis devise du compte, solde et découvert), feuille d'inventaire (total compté),
   stock actuel dans le formulaire d'ajustement, échange d'emballages (fournisseur ou client).
   Sans JavaScript, tout fonctionne : le serveur ajoute les lignes et refait les calculs. */
(function () {
  'use strict';

  var langue = document.documentElement.lang === 'en' ? 'en' : 'fr';

  /** « 12 », « 0,5 », « 2.25 », « 1 200 » → nombre, ou null. */
  function lireNombre(texte) {
    texte = String(texte || '').replace(/[\s  ']+/g, '');
    if (texte === '') return null;
    var virgule = texte.lastIndexOf(','), point = texte.lastIndexOf('.');
    if (virgule !== -1 && point !== -1) {
      var decimal = virgule > point ? ',' : '.';
      texte = texte.split(decimal === ',' ? '.' : ',').join('').replace(',', '.');
    } else if (virgule !== -1) {
      texte = langue === 'en' && /^\d{1,3}(,\d{3})+$/.test(texte) ? texte.split(',').join('') : texte.replace(',', '.');
    }
    if (!/^-?\d+(\.\d+)?$/.test(texte)) return null;
    return parseFloat(texte);
  }

  function nombre(valeur, decimales) {
    if (decimales === undefined) decimales = Math.abs(valeur - Math.round(valeur)) < 0.0005 ? 0 : (Math.abs(valeur * 10 - Math.round(valeur * 10)) < 0.005 ? 1 : 2);
    var parties = Math.abs(valeur).toFixed(decimales).split('.');
    var entier = parties[0].replace(/\B(?=(\d{3})+(?!\d))/g, langue === 'fr' ? ' ' : ',');
    return (valeur < -0.0005 ? '−' : '') + entier + (parties[1] ? (langue === 'fr' ? ',' : '.') + parties[1] : '');
  }

  function remplir(modele, valeurs) {
    return String(modele || '').replace(/\{(\w+)\}/g, function (tout, cle) {
      return Object.prototype.hasOwnProperty.call(valeurs, cle) ? valeurs[cle] : tout;
    });
  }

  function json(texte, defaut) {
    try { return JSON.parse(texte || ''); } catch (e) { return defaut; }
  }

  function optionChoisie(select) {
    return select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
  }

  // ---------- Lignes ajoutées et retirées ----------

  /**
   * Conteneur [data-lignes] de lignes [data-ligne], modèle <template data-..>, bouton d'ajout ; numérote les
   * légendes, montre « Retirer » s'il reste plus d'une ligne, appelle apres() à chaque changement.
   */
  function brancherLignes(formulaire, conteneur, modele, bouton, selecteur, max, apres) {
    if (!conteneur) return;
    var compteur = conteneur.querySelectorAll(selecteur).length + 100;
    var lignes = function () { return Array.prototype.slice.call(conteneur.querySelectorAll(selecteur)); };
    var majBoutons = function () {
      var liste = lignes();
      liste.forEach(function (ligne, i) {
        var retirer = ligne.querySelector('[data-retirer-ligne], [data-retirer]');
        if (retirer) retirer.hidden = liste.length < 2;
        var titre = ligne.querySelector('legend');
        if (titre) titre.firstChild.textContent = titre.firstChild.textContent.replace(/\d+|__n__/, String(i + 1));
      });
      if (bouton) bouton.hidden = liste.length >= max;
      if (apres) apres();
    };
    var brancher = function (ligne) {
      var retirer = ligne.querySelector('[data-retirer-ligne], [data-retirer]');
      if (retirer) {
        retirer.addEventListener('click', function () {
          var voisine = ligne.nextElementSibling || ligne.previousElementSibling;
          ligne.remove();
          majBoutons();
          var champ = voisine && voisine.querySelector('select, input');
          if (champ) champ.focus();
        });
      }
      ligne.addEventListener('input', function () { if (apres) apres(); });
      ligne.addEventListener('change', function () { if (apres) apres(ligne); });
    };
    lignes().forEach(brancher);
    if (bouton && modele) {
      bouton.addEventListener('click', function (evenement) {
        evenement.preventDefault();
        if (lignes().length >= max) return;
        var html = modele.innerHTML.replace(/__i__/g, String(compteur++)).replace(/__n__/g, String(lignes().length + 1));
        var tampon = document.createElement('div');
        tampon.innerHTML = html.trim();
        var ligne = tampon.firstElementChild;
        conteneur.appendChild(ligne);
        brancher(ligne);
        majBoutons();
        var champ = ligne.querySelector('select, input');
        if (champ) champ.focus();
        if (apres) apres(ligne, true);
      });
    }
    majBoutons();
    return { lignes: lignes };
  }

  // ---------- Formulaires à lignes d'articles (transferts, commandes, réceptions, fiches techniques) ----------

  document.querySelectorAll('[data-formulaire-lignes]').forEach(function (formulaire) {
    var conteneur = formulaire.querySelector('[data-lignes]');
    var modele = formulaire.querySelector('template[data-modele-ligne]');
    var bouton = formulaire.querySelector('[data-ajouter-ligne]');
    var stocks = json(formulaire.dataset.stocks, {});
    var source = formulaire.querySelector('[data-source]');
    var deviseFacture = formulaire.querySelector('[data-devise-facture]');
    var total = formulaire.querySelector('[data-total-lignes]');

    var symbole = function () {
      var option = optionChoisie(deviseFacture);
      return option ? option.dataset.symbole || '' : '';
    };

    var maj = function () {
      var somme = 0, avecPrix = false;
      (conteneur ? conteneur.querySelectorAll('[data-ligne]') : []).forEach(function (ligne) {
        var format = optionChoisie(ligne.querySelector('[data-ligne-format]'));
        var quantite = lireNombre((ligne.querySelector('[data-ligne-quantite]') || {}).value);
        var zone = ligne.querySelector('[data-ligne-base]');
        var prixChamp = ligne.querySelector('[data-ligne-prix]');
        if (zone) {
          var textes = [];
          if (format && format.value && quantite !== null && quantite > 0) {
            var base = parseFloat(format.dataset.base) || 1;
            if (Math.abs(base - 1) > 0.0005) textes.push(remplir(formulaire.dataset.texteBase, { quantite: nombre(quantite * base) + ' ' + (format.dataset.unite || '') }));
          }
          if (source && format && format.value && source.value && stocks[source.value]) {
            var stock = stocks[source.value][format.dataset.produit];
            if (stock !== undefined) textes.push(remplir(formulaire.dataset.texteStock, { stock: stock }));
          }
          zone.textContent = textes.join(' · ');
        }
        if (prixChamp) {
          var prix = lireNombre(prixChamp.value);
          if (prix !== null && quantite !== null) { somme += prix * quantite; avecPrix = true; }
        }
      });
      formulaire.querySelectorAll('[data-symbole]').forEach(function (s) { s.textContent = symbole(); });
      if (total) {
        total.hidden = !avecPrix;
        total.textContent = remplir(formulaire.dataset.texteTotal, { total: nombre(somme) + ' ' + symbole() });
      }
    };
    brancherLignes(formulaire, conteneur, modele, bouton, '[data-ligne]', parseInt(formulaire.dataset.max, 10) || 40, maj);
    if (source) source.addEventListener('change', maj);
    if (deviseFacture) deviseFacture.addEventListener('change', maj);
    maj();
  });

  // ---------- Lignes de paiement (compte, devise du compte, montant) ----------

  function brancherPaiements(formulaire) {
    var conteneur = formulaire.querySelector('[data-paiements]');
    if (!conteneur) return;
    var devises = json(formulaire.dataset.devises, {});
    var modele = formulaire.querySelector('template[data-modele-paiement]');
    var bouton = formulaire.querySelector('[data-ajouter-paiement]');

    var tenues = function (option) { return option && option.value ? json(option.dataset.devises, []) : null; };
    var remplirDevises = function (ligne) {
      var compte = optionChoisie(ligne.querySelector('[data-compte-paiement]'));
      var select = ligne.querySelector('[data-devise-paiement]');
      var liste = tenues(compte);
      if (!liste || !select) return;
      var choisie = select.value;
      var ids = liste.map(function (d) { return String(d[0]); });
      if (ids.indexOf(choisie) === -1) choisie = ids[0] || '';
      select.innerHTML = '';
      liste.forEach(function (d) {
        if (!devises[d[0]]) return;
        var option = document.createElement('option');
        option.value = String(d[0]);
        option.textContent = devises[d[0]].code;
        option.selected = option.value === choisie;
        select.appendChild(option);
      });
    };
    var majLigne = function (ligne) {
      var compte = optionChoisie(ligne.querySelector('[data-compte-paiement]'));
      var deviseId = (ligne.querySelector('[data-devise-paiement]') || {}).value;
      var devise = devises[deviseId];
      var symbole = ligne.querySelector('[data-symbole-paiement]');
      if (symbole) symbole.textContent = devise ? devise.symbole : '';
      var zone = ligne.querySelector('[data-solde-paiement]');
      if (!zone) return;
      zone.classList.remove('tre-alerte-texte');
      var liste = tenues(compte) || [];
      var solde = null;
      liste.forEach(function (d) { if (String(d[0]) === String(deviseId)) solde = parseFloat(d[1]) || 0; });
      if (solde === null || !devise) { zone.textContent = ''; return; }
      var montant = lireNombre((ligne.querySelector('[data-montant-paiement]') || {}).value) || 0;
      var fmt = function (v) { return (devise.avant ? devise.symbole : '') + nombre(v, Math.abs(v - Math.round(v)) < 0.005 ? 0 : devise.decimales) + (devise.avant ? '' : ' ' + devise.symbole); };
      if (montant && solde - montant < -0.0005) {
        zone.textContent = remplir(formulaire.dataset.texteDecouvert, { solde: fmt(solde), apres: fmt(solde - montant) });
        zone.classList.add('tre-alerte-texte');
      } else {
        zone.textContent = remplir(formulaire.dataset.texteSolde, { solde: fmt(solde) });
      }
    };
    var toutes = function () { conteneur.querySelectorAll('[data-paiement]').forEach(majLigne); };
    conteneur.querySelectorAll('[data-paiement]').forEach(function (ligne) { remplirDevises(ligne); });
    brancherLignes(formulaire, conteneur, modele, bouton, '[data-paiement]', parseInt(formulaire.dataset.maxPaiements, 10) || 6, function (ligne, nouvelle) {
      if (ligne && ligne.matches && ligne.matches('[data-paiement]')) remplirDevises(ligne);
      toutes();
    });
    conteneur.addEventListener('change', function (e) {
      var ligne = e.target.closest('[data-paiement]');
      if (ligne && e.target.matches('[data-compte-paiement]')) { remplirDevises(ligne); majLigne(ligne); }
    });
    toutes();

    var payer = formulaire.querySelector('[data-payer]');
    var bloc = formulaire.querySelector('[data-bloc-paiements]');
    if (payer && bloc) {
      var suivre = function () { bloc.hidden = !payer.checked; };
      payer.addEventListener('change', suivre);
      suivre();
    }
  }
  document.querySelectorAll('[data-formulaire-paiements], [data-formulaire-lignes]').forEach(brancherPaiements);

  // ---------- Ajustement : stock actuel ----------

  var ajuster = document.getElementById('form-ajuster');
  if (ajuster) {
    var stocksA = json(ajuster.dataset.stocks, {});
    var lieu = ajuster.querySelector('[data-lieu]');
    var formatA = ajuster.querySelector('[data-format]');
    var zoneA = ajuster.querySelector('[data-stock-actuel]');
    var majA = function () {
      var option = optionChoisie(formatA);
      if (!option || !option.value || !lieu.value) { zoneA.textContent = ''; return; }
      var stock = (stocksA[lieu.value] || {})[option.dataset.produit];
      zoneA.textContent = remplir(zoneA.dataset.texte, { stock: stock !== undefined ? stock : '0' });
    };
    lieu.addEventListener('change', majA);
    formatA.addEventListener('change', majA);
    majA();
  }

  // ---------- Inventaire : total compté par article ----------

  document.querySelectorAll('[data-comptage] .stk-compte').forEach(function (article) {
    var zone = article.querySelector('[data-total-compte]');
    var maj = function () {
      var total = 0, vide = true;
      article.querySelectorAll('input[data-taille]').forEach(function (champ) {
        var q = lireNombre(champ.value);
        if (q !== null) { total += q * (parseFloat(champ.dataset.taille) || 1); vide = false; }
      });
      zone.textContent = vide ? '' : '= ' + nombre(total) + ' ' + (zone.dataset.unite || '');
    };
    article.addEventListener('input', maj);
    maj();
  });

  // ---------- Emballages : fournisseur ou client ----------

  var echange = document.querySelector('[data-echange]');
  if (echange) {
    var majTiers = function () {
      var choisi = (echange.querySelector('input[name="tiers"]:checked') || {}).value || 'fournisseur';
      echange.querySelectorAll('[data-tiers]').forEach(function (bloc) { bloc.hidden = bloc.dataset.tiers !== choisi; });
    };
    echange.querySelectorAll('input[name="tiers"]').forEach(function (r) { r.addEventListener('change', majTiers); });
    majTiers();
  }
})();
