// Caisse : lounge et nightclub (avec caisse.js, qui expose window.Caisse).
// - Formules : une bouteille avec ses softs se compose dans une fenêtre (le nombre exact de chaque groupe).
// - Fenêtre de paiement : remise ou offert (ligne ou ticket, %, montant ou offert, motif ; au-delà de la limite de la
//   personne, un supérieur tape son PIN), minimum de consommation de la table (ajouté ou levé), client fidèle
//   (points, récompense), paiement avec une carte cashless (sa part, dans sa devise).
// - Cashless : lire une carte (caméra ou code), la recharger (même fenêtre de paiement : l'argent entre dans la
//   trésorerie), rembourser son solde, la bloquer, en créer une.
// - Entrée : contrôle des billets (caméra ou saisie : déjà utilisé, inconnu, autre soirée refusés), liste d'invités
//   (recherche, pointage), comptes de la nuit. Vestiaire : dépôt (décrit), retour par code ou numéro, objets restants.
// Le serveur refait tous les calculs et refuse ce qui ne colle pas ; ici, on calcule pour l'affichage.
(function () {
  'use strict';

  var C = window.Caisse;
  if (!C || !C.D.nuit || !C.D.nuit.pret) { return; }
  var D = C.D, N = D.nuit, TN = N.textes, el = C.el, bouton = C.bouton, formater = C.formater;

  function texte(modele, valeurs) {
    return String(modele).replace(/\{(\w+)\}/g, function (m, cle) { return valeurs[cle] !== undefined ? valeurs[cle] : m; });
  }
  function envoyer(url, champs) {
    var corps = new FormData();
    corps.append('csrf', D.csrf);
    corps.append('etab', D.etab);
    Object.keys(champs || {}).forEach(function (k) { if (champs[k] !== undefined && champs[k] !== null) { corps.append(k, champs[k]); } });
    return fetch(url, { method: 'POST', body: corps, credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(lireReponse);
  }
  function lire(url) {
    return fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'etab=' + encodeURIComponent(D.etab), { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(lireReponse);
  }
  function lireReponse(r) {
    return r.json().catch(function () { return {}; }).then(function (j) {
      if (j && j.csrf) { D.csrf = j.csrf; }
      return { ok: r.ok, statut: r.status, j: j || {} };
    });
  }
  function pourcent(part) { return (Math.round(part * 1000) / 10).toString().replace('.', D.langue === 'fr' ? ',' : '.') + ' %'; }
  function montantLisible(texteSaisi) {
    var n = parseFloat(String(texteSaisi).replace(/[\s  ]/g, '').replace(',', '.'));
    return isFinite(n) && n > 0 ? n : 0;
  }
  function pas(code) { var d = C.devises[code]; return d.arrondi > 0 ? d.arrondi : Math.pow(10, -d.decimales); }
  function versLeHaut(m, code) { var p = pas(code); return +(Math.ceil(+(m / p).toFixed(6)) * p).toFixed(C.devises[code].decimales); }

  // ---------- Fenêtre commune, messages ----------

  var fenetre = document.getElementById('nuit-fenetre');
  var corpsFenetre = document.getElementById('nuit-corps');
  var titreFenetre = document.getElementById('nuit-titre');
  var aLaFermeture = null;
  function ouvrir(titre, remplir, fermeture) {
    if (window.ScannerQr) { window.ScannerQr.toutArreter(); }
    titreFenetre.textContent = titre;
    corpsFenetre.textContent = '';
    aLaFermeture = fermeture || null;
    remplir(corpsFenetre);
    if (!fenetre.open) { fenetre.showModal(); }
  }
  function fermer() { if (fenetre.open) { fenetre.close(); } }
  fenetre.addEventListener('close', function () {
    if (window.ScannerQr) { window.ScannerQr.toutArreter(); }
    var f = aLaFermeture;
    aLaFermeture = null;
    if (f) { f(); }
  });
  fenetre.querySelector('[data-fermer-nuit]').addEventListener('click', fermer);

  var elToast = document.getElementById('nuit-toast');
  var minuterieToast = null;
  function toast(message) {
    elToast.textContent = message;
    elToast.hidden = false;
    clearTimeout(minuterieToast);
    minuterieToast = setTimeout(function () { elToast.hidden = true; }, 4000);
  }
  function erreurDans(zone, message) {
    var p = zone.querySelector('.nuit-erreur') || zone.appendChild(el('p', 'paiement-fenetre__erreur nuit-erreur'));
    p.setAttribute('role', 'alert');
    p.textContent = message;
    p.hidden = !message;
  }
  function puces(options, choisi, quand) {
    var groupe = el('div', 'nuit-puces');
    options.forEach(function (o) {
      var b = bouton('puce', o.texte);
      b.dataset.valeur = o.valeur;
      b.setAttribute('aria-pressed', String(o.valeur === choisi));
      if (o.desactive) { b.disabled = true; }
      b.addEventListener('click', function () {
        groupe.querySelectorAll('.puce').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
        quand(o.valeur);
      });
      groupe.appendChild(b);
    });
    return groupe;
  }
  function champ(libelle, attributs) {
    var bloc = el('label', 'ardoise-champ');
    bloc.appendChild(el('span', 'paiement-fenetre__etiquette', libelle));
    var input = el('input', 'saisie');
    Object.keys(attributs || {}).forEach(function (k) { input.setAttribute(k, attributs[k]); });
    bloc.appendChild(input);
    return { bloc: bloc, input: input };
  }
  // Supérieur + PIN : puces des personnes, champ du PIN
  function validation(valideurs, etat) {
    var bloc = el('div', 'nuit-validation');
    bloc.appendChild(el('p', 'paiement-fenetre__etiquette', TN.valideur));
    bloc.appendChild(puces(valideurs.map(function (v) { return { valeur: v.id, texte: v.nom, desactive: v.bloque }; }), etat.validateur, function (id) { etat.validateur = id; pin.input.focus(); }));
    var pin = champ(TN.pin, { inputmode: 'numeric', autocomplete: 'off', maxlength: '6', type: 'password', id: 'nuit-pin' });
    pin.input.value = etat.pin || '';
    pin.input.addEventListener('input', function () { pin.input.value = pin.input.value.replace(/\D/g, '').slice(0, 6); etat.pin = pin.input.value; });
    bloc.appendChild(pin.bloc);
    bloc.appendChild(el('p', 'aide', TN.pin_aide));
    return bloc;
  }

  // ---------- Codes QR (reçus, billets, cartes) ----------

  function dessinerQr(racine) {
    if (typeof window.qrcode !== 'function') { return; }
    (racine || document).querySelectorAll('[data-qr]').forEach(function (z) {
      if (z.querySelector('svg')) { return; }
      var qr = window.qrcode(0, 'M');
      qr.addData(z.getAttribute('data-qr'));
      qr.make();
      z.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 8, scalable: true });
    });
  }
  C.apresRecu = dessinerQr;
  function zoneImpression(html, titre) {
    ouvrir(titre || TN.billets, function (z) {
      var recu = el('div', 'nuit-impression');
      recu.innerHTML = html;
      z.appendChild(recu);
      dessinerQr(recu);
      var actions = el('div', 'paiement-fenetre__actions');
      var imprimer = bouton('bouton bouton--secondaire', TN.imprimer);
      imprimer.addEventListener('click', function () { C.imprimer(recu.innerHTML); });
      var ok = bouton('bouton', TN.fermer);
      ok.addEventListener('click', fermer);
      actions.append(imprimer, ok);
      z.appendChild(actions);
    });
  }

  // ---------- Formules et dépôts au vestiaire (toucher une tuile) ----------

  C.avantChoix = function (p) {
    if (p.formule && p.formule.length) { choisirFormule(p); return true; }
    if (D.poste === 'vestiaire') { decrireDepot(p); return true; }
    return false;
  };

  function choisirFormule(p) {
    var format = p.formats.filter(function (f) { return f.disponible && typeof f.prix[D.principale] === 'number'; })[0];
    if (!format) { return; }
    var choix = p.formule.map(function (g) {
      var q = {};
      if (g.options.length === 1) { q[g.options[0].id] = g.choix; }
      return q;
    });
    ouvrir(p.nom, function (z) {
      z.appendChild(el('p', 'paiement-fenetre__etiquette', TN.formule_titre + ' · ' + formater(format.prix[D.principale], D.principale)));
      var groupes = el('div', 'nuit-formule');
      var valider = bouton('bouton bouton--large', TN.formule_valider);
      valider.id = 'formule-valider';
      function total(i) { return Object.keys(choix[i]).reduce(function (s, k) { return s + choix[i][k]; }, 0); }
      function dessiner() {
        groupes.textContent = '';
        var complet = true;
        p.formule.forEach(function (g, i) {
          var bloc = el('fieldset', 'nuit-formule__groupe');
          var reste = g.choix - total(i);
          if (reste !== 0) { complet = false; }
          bloc.appendChild(el('legend', '', g.nom + ' · ' + (g.options.length === 1 ? TN.inclus : (reste > 0 ? texte(TN.formule_reste, { n: reste }) : texte(TN.formule_choisir, { n: g.choix })))));
          g.options.forEach(function (o) {
            var ligne = el('div', 'nuit-option' + (o.epuise ? ' nuit-option--epuise' : ''));
            ligne.appendChild(el('span', 'nuit-option__nom', o.nom + (o.format ? ' · ' + o.format : '')));
            if (g.options.length === 1) {
              ligne.appendChild(el('span', 'badge badge--succes', g.choix + ' × ' + TN.inclus));
            } else {
              var q = choix[i][o.id] || 0;
              var pasB = el('div', 'pas');
              var moins = bouton('pas__bouton', '−', TN.retirer + ' : ' + o.nom);
              moins.disabled = q <= 0;
              moins.addEventListener('click', function () { choix[i][o.id] = q - 1; if (!choix[i][o.id]) { delete choix[i][o.id]; } dessiner(); });
              var plus = bouton('pas__bouton', '+', o.nom);
              plus.dataset.option = o.id;
              plus.disabled = o.epuise || reste <= 0;
              plus.addEventListener('click', function () { choix[i][o.id] = q + 1; dessiner(); });
              pasB.append(moins, el('span', 'pas__qte', String(q)), plus);
              ligne.appendChild(pasB);
            }
            bloc.appendChild(ligne);
          });
          groupes.appendChild(bloc);
        });
        valider.disabled = !complet;
      }
      dessiner();
      valider.addEventListener('click', function () {
        var comp = [];
        p.formule.forEach(function (g, i) {
          Object.keys(choix[i]).forEach(function (id) {
            var o = g.options.filter(function (x) { return x.id === +id; })[0];
            if (choix[i][id] > 0 && o) { comp.push({ id: +id, qte: choix[i][id], nom: o.nom }); }
          });
        });
        C.ajouterLigne({ id: format.id, qte: 1, comp: comp });
        fermer();
      });
      z.append(groupes, valider);
    });
  }

  function decrireDepot(p) {
    var format = p.formats[0];
    if (!format) { return; }
    ouvrir(p.nom, function (z) {
      var f = el('form', 'nuit-form');
      var d = champ(TN.vestiaire_description, { maxlength: '160', autocomplete: 'off', id: 'depot-description' });
      var ok = bouton('bouton bouton--large', TN.valider);
      ok.type = 'submit';
      f.append(d.bloc, ok);
      f.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var ligne = { id: format.id, qte: 1 };
        if (d.input.value.trim()) { ligne.rem = d.input.value.trim().slice(0, 160); }
        C.ajouterLigne(ligne);
        fermer();
      });
      z.appendChild(f);
      setTimeout(function () { d.input.focus(); }, 30);
    });
  }

  // ---------- Fenêtre de paiement : remises, minimum, fidélité, cartes ----------

  // Lignes que paie cette fenêtre (montants bruts par devise) : le ticket, ou la part de la note
  C.nuit = {};
  C.nuit.preparer = function (paiement, source) {
    if (source && source.url) { return null; }   // recharge : pas de remise ni de carte
    var lignes;
    if (source) {
      lignes = (source.lignes || []).map(function (l) { return { cle: l.cle, nom: l.nom, brut: l.brut, remisable: true, format: null }; });
    } else {
      lignes = C.lignes().map(function (l, i) {
        var f = C.formatDe(l.id);
        var brut = {};
        D.devises.forEach(function (d) { var p = f.format.prix[d.code]; brut[d.code] = typeof p === 'number' ? p * l.qte : null; });
        return { cle: 'i' + i, nom: C.nomLigne(l.id), brut: brut, remisable: true, format: l.id, qte: l.qte };
      });
    }
    var base = {};
    Object.keys(paiement.totaux).forEach(function (c) { base[c] = paiement.totaux[c]; });
    var minimum = source && source.minimum && !source.minimum.atteint && !source.minimum.leve ? source.minimum : null;
    return {
      base: base, lignes: lignes, remises: { lignes: {}, ticket: null }, validation: { validateur: null, pin: '' }, besoinValidation: false,
      minimum: minimum, minimumLever: false, minimumValidation: { validateur: null, pin: '' },
      cartes: [], fidelite: null, source: source
    };
  };

  function partDe(r, base) {
    if (r.type === 'offert') { return 1; }
    if (r.type === 'montant') {
      var b = base[r.devise];
      if (!(b > 0) || r.valeur > b + 0.0005) { return null; }
      return Math.min(1, r.valeur / b);
    }
    return Math.min(1, r.valeur / 100);
  }
  // Remises en vigueur, récompense de fidélité comprise
  function remisesEffectives(n) {
    var lignes = {};
    Object.keys(n.remises.lignes).forEach(function (k) { lignes[k] = n.remises.lignes[k]; });
    var ticket = n.remises.ticket;
    var r = n.fidelite && n.fidelite.recompense;
    if (r && r.type === 'remise') { ticket = { type: 'pourcent', valeur: r.pourcent, fidelite: true }; }
    if (r && r.type === 'offert') {
      var cible = n.lignes.filter(function (l) { return l.format === r.format && !lignes[l.cle]; })[0];
      if (cible) { lignes[cible.cle] = { type: 'pourcent', valeur: Math.round(100 / cible.qte * 1000) / 1000, fidelite: true }; }
    }
    return { lignes: lignes, ticket: ticket };
  }
  // Même calcul que le serveur (app/Core/Remises.php)
  function calculer(paiement) {
    var n = paiement.nuit;
    var codes = Object.keys(n.base);
    var eff = remisesEffectives(n);
    var resultat = { parts: {}, partTicket: 0, totaux: {}, partMax: 0, valide: true };
    var apres = {};
    codes.forEach(function (c) { apres[c] = 0; });
    n.lignes.forEach(function (l) {
      var r = eff.lignes[l.cle];
      var part = r ? partDe(r, l.brut) : 0;
      if (part === null) { resultat.valide = false; part = 0; }
      resultat.parts[l.cle] = part;
      if (r && !r.fidelite) { resultat.partMax = Math.max(resultat.partMax, part); }
      codes.forEach(function (c) { apres[c] += (l.brut[c] || 0) * (1 - part); });
    });
    if (eff.ticket) {
      var pt = partDe(eff.ticket, apres);
      if (pt === null) { resultat.valide = false; pt = 0; }
      resultat.partTicket = pt;
      if (!eff.ticket.fidelite) { resultat.partMax = Math.max(resultat.partMax, pt); }
    }
    codes.forEach(function (c) {
      var t = apres[c] * (1 - resultat.partTicket);
      if (n.minimum && n.minimum.action === 'ajouter' && !n.minimumLever && typeof n.minimum.manque[c] === 'number') { t += n.minimum.manque[c]; }
      resultat.totaux[c] = Math.round(t * 1000) / 1000;
    });
    if (!n.lignes.length) { resultat.totaux = n.base; }
    return resultat;
  }
  C.nuit.totaux = function (paiement) { return calculer(paiement).totaux; };
  function recalculer(paiement) {
    paiement.totaux = C.nuit.totaux(paiement);
    paiement.lignes.forEach(function (l) { l.saisi = false; });
    // Les cartes ne paient jamais plus que le nouveau total
    paiement.nuit.cartes = paiement.nuit.cartes.filter(function (c) { return typeof paiement.totaux[c.devise] === 'number'; });
    C.redessinerPaiement();
  }

  C.nuit.dessinerPaiement = function (paiement, zone) {
    var n = paiement.nuit;
    var calc = calculer(paiement);
    var principale = D.principale in n.base ? D.principale : Object.keys(n.base)[0];
    // Remises en cours
    var resume = el('div', 'nuit-resume');
    var remise = (n.base[principale] || 0) + (n.minimum && n.minimum.action === 'ajouter' && !n.minimumLever ? (n.minimum.manque[principale] || 0) : 0) - (calc.totaux[principale] || 0);
    if (remise > 0.0005) {
      var ligneR = el('p', 'nuit-resume__ligne');
      ligneR.appendChild(el('span', '', TN.sous_total + ' ' + formater(n.base[principale], principale)));
      ligneR.appendChild(el('strong', 'nuit-resume__remise', TN.remises + ' −' + formater(remise, principale)));
      resume.appendChild(ligneR);
    }
    var eff = remisesEffectives(n);
    Object.keys(eff.lignes).concat(eff.ticket ? ['ticket'] : []).forEach(function (cle) {
      var r = cle === 'ticket' ? eff.ticket : eff.lignes[cle];
      var nom = cle === 'ticket' ? TN.tout_ticket : (n.lignes.filter(function (l) { return l.cle === cle; })[0] || {}).nom;
      var part = cle === 'ticket' ? calc.partTicket : calc.parts[cle];
      var p = el('p', 'nuit-resume__detail');
      p.appendChild(el('span', '', (nom || '') + ' · ' + (part >= 1 - 1e-9 ? TN.offert : pourcent(part)) + (r.fidelite ? ' · ' + TN.fidelite : '')));
      if (!r.fidelite) {
        var retirer = bouton('lien', TN.retirer, TN.retirer + ' : ' + (nom || ''));
        retirer.addEventListener('click', function () {
          if (cle === 'ticket') { n.remises.ticket = null; } else { delete n.remises.lignes[cle]; }
          recalculer(paiement);
        });
        p.appendChild(retirer);
      }
      resume.appendChild(p);
    });
    // Validation par un supérieur : au-delà de la limite de la personne
    var limite = N.remise ? N.remise.limite : 0;
    n.besoinValidation = calc.partMax > 0 && limite !== null && calc.partMax > limite + 1e-6;
    if (n.besoinValidation) {
      resume.appendChild(el('p', 'nuit-alerte', texte(TN.remise_au_dela_court, { limite: pourcent(limite || 0) })));
      resume.appendChild(validation(N.remise.valideurs, n.validation));
    }
    // Minimum de consommation de la table
    if (n.minimum) {
      var m = el('div', 'nuit-minimum' + (n.minimumLever ? ' nuit-minimum--leve' : ''));
      var manque = formater(n.minimum.manque[principale] || 0, principale);
      m.appendChild(el('p', '', n.minimumLever ? TN.minimum_leve : texte(n.minimum.action === 'ajouter' ? TN.minimum_ajoute : TN.minimum_avertir, { manque: manque })));
      var lever = bouton('bouton bouton--petit bouton--secondaire', n.minimumLever ? TN.minimum_garder : TN.minimum_lever);
      lever.id = 'minimum-lever';
      lever.addEventListener('click', function () { n.minimumLever = !n.minimumLever; recalculer(paiement); });
      m.appendChild(lever);
      if (n.minimumLever && N.minimum && !N.minimum.lever) { m.appendChild(validation(N.minimum.valideurs, n.minimumValidation)); }
      resume.appendChild(m);
    }
    // Cartes cashless utilisées
    n.cartes.forEach(function (c, i) {
      var p = el('p', 'nuit-resume__detail');
      p.appendChild(el('span', '', texte(TN.carte_ajoutee, { code: c.numero, montant: formater(c.montant, c.devise) })));
      var retirer = bouton('lien', TN.retirer);
      retirer.addEventListener('click', function () { n.cartes.splice(i, 1); recalculer(paiement); });
      p.appendChild(retirer);
      resume.appendChild(p);
    });
    if (n.fidelite) {
      var pf = el('p', 'nuit-resume__detail');
      pf.appendChild(el('span', '', TN.fidelite + ' : ' + n.fidelite.client.nom + ' · ' + texte(TN.fidelite_points, { n: n.fidelite.client.points })
        + (n.fidelite.recompense ? ' · ' + texte(TN.remise_fidelite, { nom: n.fidelite.recompense.nom }) : '')));
      var rf = bouton('lien', TN.retirer);
      rf.addEventListener('click', function () { n.fidelite = null; recalculer(paiement); });
      pf.appendChild(rf);
      resume.appendChild(pf);
    }
    if (!calc.valide) { resume.appendChild(el('p', 'paiement-fenetre__erreur', TN.erreur)); }
    // Boutons
    var actions = el('div', 'nuit-actions');
    if (n.lignes.length && N.remise) {
      var bR = bouton('puce', TN.remise);
      bR.id = 'paiement-remise';
      bR.addEventListener('click', function () { ouvrirRemise(paiement); });
      actions.appendChild(bR);
    }
    if (N.cashless && N.cashless.payer) {
      var bC = bouton('puce', TN.carte_payer);
      bC.id = 'paiement-carte';
      bC.addEventListener('click', function () { payerAvecCarte(paiement); });
      actions.appendChild(bC);
    }
    if (N.fidelite && !n.fidelite && D.poste === 'caisse') {
      var bF = bouton('puce', TN.fidelite);
      bF.id = 'paiement-fidelite';
      bF.addEventListener('click', function () { choisirClient(paiement); });
      actions.appendChild(bF);
    }
    zone.append(actions, resume);
  };

  // Ce qui part avec l'encaissement
  C.nuit.envoi = function (paiement) {
    var n = paiement.nuit;
    var envoi = { cartes: n.cartes.map(function (c) { return { code: c.code, devise: c.devise, montant: c.montant }; }) };
    var lignes = {};
    Object.keys(n.remises.lignes).forEach(function (k) { lignes[k] = n.remises.lignes[k]; });
    if (Object.keys(lignes).length || n.remises.ticket) {
      envoi.remises = { lignes: lignes, ticket: n.remises.ticket };
      if (n.besoinValidation) { envoi.remises.validateur = n.validation.validateur; envoi.remises.pin = n.validation.pin; }
    }
    if (n.fidelite) { envoi.fidelite = { client: n.fidelite.client.id, recompense: n.fidelite.recompense ? n.fidelite.recompense.id : null }; }
    if (n.minimum && n.minimumLever) {
      envoi.minimum = { lever: true, validateur: n.minimumValidation.validateur, pin: n.minimumValidation.pin };
    }
    return envoi;
  };
  C.nuit.erreurServeur = function (paiement, j) {
    var code = j.code || '';
    if (code.indexOf('remise_') === 0) { paiement.nuit.validation.pin = ''; return true; }
    if (code.indexOf('minimum_') === 0) { paiement.nuit.minimumValidation.pin = ''; return true; }
    if (code.indexOf('carte') === 0 || code === 'points' || code === 'fidelite' || code === 'remise') { return true; }
    return false;
  };

  // Remise ou offert : sur tout le ticket ou une ligne ; %, montant (dans une devise du ticket) ou offert ; motif
  function ouvrirRemise(paiement) {
    var n = paiement.nuit;
    var etat = { cible: 'ticket', type: 'pourcent', valeur: '', devise: D.principale in n.base ? D.principale : Object.keys(n.base)[0], motif: N.remise.motifs[0].code, precision: '' };
    ouvrir(TN.remise_titre, function (z) {
      var f = el('form', 'nuit-form');
      f.appendChild(el('p', 'paiement-fenetre__etiquette', TN.cible));
      f.appendChild(puces([{ valeur: 'ticket', texte: TN.tout_ticket }].concat(n.lignes.map(function (l) { return { valeur: l.cle, texte: l.nom }; })), etat.cible, function (v) { etat.cible = v; }));
      var types = puces([{ valeur: 'pourcent', texte: TN.pourcent }, { valeur: 'montant', texte: TN.montant }, { valeur: 'offert', texte: TN.offert }], etat.type, function (v) { etat.type = v; maj(); });
      types.id = 'remise-types';
      f.appendChild(types);
      var valeur = champ(TN.pourcent, { inputmode: 'decimal', autocomplete: 'off', id: 'remise-valeur' });
      f.appendChild(valeur.bloc);
      var devises = puces(Object.keys(n.base).map(function (c) { return { valeur: c, texte: C.devises[c].symbole }; }), etat.devise, function (v) { etat.devise = v; });
      f.appendChild(devises);
      f.appendChild(el('p', 'paiement-fenetre__etiquette', TN.motif));
      var motifs = puces(N.remise.motifs.map(function (m) { return { valeur: m.code, texte: m.texte }; }), etat.motif, function (v) { etat.motif = v; });
      motifs.id = 'remise-motifs';
      f.appendChild(motifs);
      var precision = champ(TN.precision, { maxlength: '160', autocomplete: 'off', id: 'remise-precision' });
      f.appendChild(precision.bloc);
      var ok = bouton('bouton bouton--large', TN.appliquer);
      ok.type = 'submit';
      ok.id = 'remise-appliquer';
      f.appendChild(ok);
      function maj() {
        valeur.bloc.hidden = etat.type === 'offert';
        valeur.bloc.querySelector('span').textContent = etat.type === 'montant' ? TN.montant : TN.pourcent;
        devises.hidden = etat.type !== 'montant';
      }
      maj();
      f.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var v = etat.type === 'offert' ? 100 : montantLisible(valeur.input.value);
        var r = { type: etat.type, valeur: v, devise: etat.type === 'montant' ? etat.devise : null, motif: etat.motif, precision: precision.input.value.trim() || null };
        var base = etat.cible === 'ticket' ? null : (n.lignes.filter(function (l) { return l.cle === etat.cible; })[0] || {}).brut;
        if (!(v > 0) || (etat.type === 'pourcent' && v > 100) || (base && partDe(r, base) === null)) {
          erreurDans(f, TN.erreur);
          valeur.input.focus();
          return;
        }
        if (etat.cible === 'ticket') { n.remises.ticket = r; } else { n.remises.lignes[etat.cible] = r; }
        var calc = calculer(paiement);
        if (!calc.valide) {
          if (etat.cible === 'ticket') { n.remises.ticket = null; } else { delete n.remises.lignes[etat.cible]; }
          erreurDans(f, TN.erreur);
          return;
        }
        fermer();
        recalculer(paiement);
      });
      z.appendChild(f);
      setTimeout(function () { valeur.input.focus(); }, 30);
    });
  }

  // Payer avec une carte cashless : lire la carte, prendre sa part dans une devise où elle a un solde
  function payerAvecCarte(paiement) {
    ouvrir(TN.carte_payer_titre, function (z) {
      var resultat = el('div', 'nuit-carte');
      var scanner = window.ScannerQr.monter(z, { textes: TN, placeholder: TN.carte_code, lu: function (code) {
        lire('/caisse/nuit/carte?code=' + encodeURIComponent(code)).then(function (r) {
          resultat.textContent = '';
          if (!r.ok) { erreurDans(resultat, r.j.erreur || TN.erreur); scanner.reprendre(); return; }
          var carte = r.j.carte;
          resultat.appendChild(resumeCarte(carte));
          if (carte.statut !== 'active') { erreurDans(resultat, TN.carte_bloquee); scanner.reprendre(); return; }
          var reste = resteSansAuto(paiement);
          var codes = Object.keys(paiement.totaux).filter(function (c) {
            var dejaPris = paiement.nuit.cartes.filter(function (x) { return x.code === carte.code && x.devise === c; }).reduce(function (s, x) { return s + x.montant; }, 0);
            return (carte.soldes[c] || 0) - dejaPris > 0.0005 && reste[c] > 0;
          });
          if (!codes.length) { erreurDans(resultat, TN.carte_trop); scanner.reprendre(); return; }
          codes.forEach(function (c) {
            var dispo = carte.soldes[c] - paiement.nuit.cartes.filter(function (x) { return x.code === carte.code && x.devise === c; }).reduce(function (s, x) { return s + x.montant; }, 0);
            var montant = Math.min(dispo, reste[c]);
            var b = bouton('bouton bouton--large', TN.carte_payer + ' · ' + formater(montant, c));
            b.dataset.devise = c;
            b.addEventListener('click', function () {
              paiement.nuit.cartes.push({ code: carte.code, numero: 'C' + String(carte.numero).padStart(4, '0'), devise: c, montant: +montant.toFixed(C.devises[c].decimales) });
              fermer();
              recalculer(paiement);
            });
            resultat.appendChild(b);
          });
        });
      } });
      z.appendChild(resultat);
    });
  }
  // Ce qui reste à payer sans compter le montant que la fenêtre a rempli d'elle-même (le compte prend ensuite le reste)
  function resteSansAuto(paiement) {
    var gardes = paiement.lignes.map(function (l) { return l.recu; });
    paiement.lignes.forEach(function (l) { if (!l.saisi) { l.recu = 0; } });
    var reste = C.reglement().reste;
    paiement.lignes.forEach(function (l, i) { l.recu = gardes[i]; });
    return reste;
  }
  function resumeCarte(carte) {
    var bloc = el('div', 'nuit-carte__resume' + (carte.statut !== 'active' ? ' nuit-carte__resume--bloquee' : ''));
    bloc.appendChild(el('strong', 'nuit-carte__code', carte.code + (carte.porteur ? ' · ' + carte.porteur : '')));
    var soldes = Object.keys(carte.soldes).map(function (c) { return formater(carte.soldes[c], c); }).join('  ·  ');
    bloc.appendChild(el('span', 'nuit-carte__soldes', TN.carte_solde + ' : ' + soldes));
    if (carte.statut !== 'active') { bloc.appendChild(el('span', 'badge badge--erreur', TN.carte_bloquee + (carte.motif ? ' — ' + carte.motif : ''))); }
    return bloc;
  }

  // Client fidèle : recherche (nom, téléphone) ou création, points, récompense
  function choisirClient(paiement) {
    ouvrir(TN.fidelite_titre, function (z) {
      var recherche = champ(TN.fidelite_client, { type: 'search', autocomplete: 'off', id: 'fidelite-recherche', placeholder: TN.fidelite_nom + ' / ' + TN.fidelite_telephone });
      var liste = el('div', 'nuit-liste');
      var minuterie = null;
      recherche.input.addEventListener('input', function () {
        clearTimeout(minuterie);
        var q = recherche.input.value.trim();
        if (q.length < 2) { liste.textContent = ''; return; }
        minuterie = setTimeout(function () {
          lire('/caisse/nuit/clients?q=' + encodeURIComponent(q)).then(function (r) {
            liste.textContent = '';
            (r.ok ? r.j.clients : []).forEach(function (c) { liste.appendChild(boutonClient(c)); });
            if (r.ok && !r.j.clients.length) { liste.appendChild(el('p', 'aide', TN.invite_aucun)); }
          });
        }, 220);
      });
      function boutonClient(c) {
        var b = bouton('ardoise-client');
        b.append(el('span', 'ardoise-client__nom', c.nom), el('span', 'ardoise-client__detail', [c.telephone, texte(TN.fidelite_points, { n: c.points })].filter(Boolean).join(' · ')));
        b.addEventListener('click', function () { choisirRecompense(paiement, c); });
        return b;
      }
      var nouveau = el('details', 'ardoise-nouveau');
      nouveau.appendChild(el('summary', 'lien', TN.fidelite_nouveau));
      var nom = champ(TN.fidelite_nom, { autocomplete: 'off', maxlength: '128', id: 'fidelite-nom' });
      var tel = champ(TN.fidelite_telephone, { type: 'tel', autocomplete: 'off', id: 'fidelite-telephone' });
      var creer = bouton('bouton bouton--secondaire', TN.fidelite_creer);
      creer.addEventListener('click', function () {
        envoyer('/caisse/nuit/clients', { nom: nom.input.value, telephone: tel.input.value }).then(function (r) {
          if (!r.ok) { erreurDans(nouveau, r.j.erreur || TN.erreur); return; }
          choisirRecompense(paiement, r.j.client);
        });
      });
      nouveau.append(nom.bloc, tel.bloc, creer);
      z.append(recherche.bloc, liste, nouveau);
      setTimeout(function () { recherche.input.focus(); }, 30);
    });
  }
  function choisirRecompense(paiement, client) {
    var possibles = N.fidelite.recompenses.filter(function (r) {
      return r.points <= client.points && (r.type === 'remise' || paiement.nuit.lignes.some(function (l) { return l.format === r.format; }));
    });
    var choisir = function (r) {
      paiement.nuit.fidelite = { client: client, recompense: r };
      if (r && r.type === 'remise') { paiement.nuit.remises.ticket = null; }
      fermer();
      recalculer(paiement);
    };
    if (!possibles.length) { choisir(null); return; }
    ouvrir(client.nom + ' · ' + texte(TN.fidelite_points, { n: client.points }), function (z) {
      z.appendChild(el('p', 'paiement-fenetre__etiquette', TN.fidelite_recompense));
      possibles.forEach(function (r) {
        var b = bouton('bouton bouton--secondaire bouton--large', r.nom + ' · ' + r.points + ' pts');
        b.dataset.recompense = r.id;
        b.addEventListener('click', function () { choisir(r); });
        z.appendChild(b);
      });
      var sans = bouton('bouton bouton--large', TN.fidelite_aucune);
      sans.id = 'fidelite-sans';
      sans.addEventListener('click', function () { choisir(null); });
      z.appendChild(sans);
    });
  }

  // ---------- Cashless : lire, recharger, rembourser, bloquer, créer ----------

  var boutonCashless = document.getElementById('nuit-cashless');
  if (boutonCashless) { boutonCashless.addEventListener('click', function () { ouvrirCashless(); }); }

  function ouvrirCashless(carteInitiale) {
    ouvrir(TN.carte_titre, function (z) {
      var resultat = el('div', 'nuit-carte');
      function afficher(carte) {
        resultat.textContent = '';
        resultat.appendChild(resumeCarte(carte));
        var actions = el('div', 'nuit-actions nuit-actions--grandes');
        if (N.cashless.recharger && carte.statut === 'active') {
          var r = bouton('bouton', TN.carte_recharger);
          r.id = 'carte-recharger';
          r.addEventListener('click', function () { formulaireRecharge(carte, resultat); });
          actions.appendChild(r);
        }
        if (N.cashless.gerer) {
          var rb = bouton('bouton bouton--secondaire', TN.carte_rembourser);
          rb.id = 'carte-rembourser';
          rb.disabled = !Object.keys(carte.soldes).some(function (c) { return carte.soldes[c] > 0; });
          rb.addEventListener('click', function () { formulaireRemboursement(carte, resultat, afficher); });
          var bl = bouton('bouton bouton--secondaire', carte.statut === 'active' ? TN.carte_bloquer : TN.carte_debloquer);
          bl.id = 'carte-bloquer';
          bl.addEventListener('click', function () {
            var motif = carte.statut === 'active' ? window.prompt(TN.motif_blocage, '') : '';
            if (motif === null) { return; }
            envoyer('/caisse/nuit/carte/bloquer', { carte: carte.id, bloquer: carte.statut === 'active' ? '1' : '0', motif: motif }).then(function (rep) {
              if (!rep.ok) { erreurDans(resultat, rep.j.erreur || TN.erreur); return; }
              afficher(rep.j.carte);
            });
          });
          actions.append(rb, bl);
        }
        resultat.appendChild(actions);
        if (carte.historique && carte.historique.length) {
          resultat.appendChild(el('p', 'paiement-fenetre__etiquette', TN.carte_historique));
          var ul = el('ul', 'nuit-historique');
          carte.historique.forEach(function (m) {
            var signe = m.montant < 0 ? '−' : '+';
            ul.appendChild(el('li', '', m.le + ' · ' + (m.libelle || m.type) + ' · ' + signe + formater(Math.abs(m.montant), m.devise) + (m.vente ? ' · ' + m.vente : '') + ' → ' + formater(m.solde, m.devise)));
          });
          resultat.appendChild(ul);
        }
      }
      var scanner = window.ScannerQr.monter(z, { textes: TN, placeholder: TN.carte_code, lu: function (code) {
        var numero = /^\d{1,7}$/.test(code.trim()) && N.cashless.gerer;
        lire('/caisse/nuit/carte?' + (numero ? 'numero=' + encodeURIComponent(code.trim()) : 'code=' + encodeURIComponent(code))).then(function (r) {
          scanner.reprendre();
          if (!r.ok) { resultat.textContent = ''; erreurDans(resultat, r.j.erreur || TN.erreur); return; }
          afficher(r.j.carte);
        });
      } });
      z.appendChild(resultat);
      if (N.cashless.recharger) {
        var nouvelle = el('details', 'ardoise-nouveau');
        nouvelle.appendChild(el('summary', 'lien', TN.carte_nouvelle));
        var porteur = champ(TN.carte_porteur, { autocomplete: 'off', maxlength: '128', id: 'carte-porteur' });
        var support = 'carte';
        var choixSupport = puces([{ valeur: 'carte', texte: TN.carte_titre }, { valeur: 'bracelet', texte: TN.carte_bracelet }], support, function (v) { support = v; });
        var creer = bouton('bouton bouton--secondaire', TN.carte_nouvelle);
        creer.id = 'carte-creer';
        var cleCreation = C.cle();
        creer.addEventListener('click', function () {
          creer.disabled = true;
          envoyer('/caisse/nuit/carte/nouvelle', { porteur: porteur.input.value, support: support, cle: cleCreation }).then(function (r) {
            creer.disabled = false;
            if (!r.ok) { erreurDans(nouvelle, r.j.erreur || TN.erreur); return; }
            cleCreation = C.cle();
            toast(texte(TN.carte_creee, { code: r.j.carte.code }));
            afficher(r.j.carte);
            var impr = el('div', 'nuit-impression');
            impr.innerHTML = r.j.impression;
            dessinerQr(impr);
            var b = bouton('bouton bouton--secondaire', TN.carte_imprimer);
            b.addEventListener('click', function () { C.imprimer(impr.innerHTML); });
            resultat.append(impr, b);
          });
        });
        nouvelle.append(choixSupport, porteur.bloc, creer);
        z.appendChild(nouvelle);
      }
      if (carteInitiale) { afficher(carteInitiale); }
    });
  }

  function formulaireRecharge(carte, zone) {
    var bloc = el('form', 'nuit-form');
    var devise = D.principale;
    bloc.appendChild(el('p', 'paiement-fenetre__etiquette', TN.devise));
    bloc.appendChild(puces(D.devises.map(function (d) { return { valeur: d.code, texte: d.symbole }; }), devise, function (v) { devise = v; }));
    var montant = champ(TN.montant_recharge, { inputmode: 'decimal', autocomplete: 'off', id: 'recharge-montant' });
    bloc.appendChild(montant.bloc);
    var ok = bouton('bouton bouton--large', TN.carte_recharger);
    ok.type = 'submit';
    ok.id = 'recharge-valider';
    bloc.appendChild(ok);
    bloc.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var m = montantLisible(montant.input.value);
      if (!(m > 0)) { erreurDans(bloc, TN.erreur); montant.input.focus(); return; }
      m = +m.toFixed(C.devises[devise].decimales);
      // Même calcul que le serveur : l'équivalent dans chaque autre devise, arrondi vers le haut
      var totaux = {};
      totaux[devise] = m;
      var taux = N.taux[devise];
      D.devises.forEach(function (d) {
        if (d.code !== devise && taux > 0 && N.taux[d.code] > 0) { totaux[d.code] = versLeHaut(m / taux * N.taux[d.code], d.code); }
      });
      fermer();
      C.ouvrirPaiement(null, false, {
        url: '/caisse/nuit/carte/recharger', totaux: totaux, sansArdoise: true, titre: carte.code, version: C.cle(),
        note: undefined, extra: { carte: carte.code, devise: devise, montant: m },
        apres: function () { toast(TN.recharge_faite); }
      });
    });
    zone.appendChild(bloc);
    montant.input.focus();
  }

  function formulaireRemboursement(carte, zone, afficher) {
    var bloc = el('form', 'nuit-form');
    bloc.appendChild(el('p', 'paiement-fenetre__etiquette', TN.carte_rembourser_titre));
    var codes = Object.keys(carte.soldes).filter(function (c) { return carte.soldes[c] > 0; });
    var etat = { devise: codes[0], compte: null };
    var comptes = el('div');
    function majComptes() {
      comptes.textContent = '';
      var liste = C.comptesPour(etat.devise, 'caisse');
      etat.compte = liste[0] ? liste[0].id : null;
      comptes.appendChild(puces(liste.map(function (c) { return { valeur: c.id, texte: c.nom }; }), etat.compte, function (v) { etat.compte = v; }));
      montant.input.value = String(carte.soldes[etat.devise]);
    }
    var montant = champ(TN.montant, { inputmode: 'decimal', autocomplete: 'off', id: 'remboursement-montant' });
    bloc.appendChild(puces(codes.map(function (c) { return { valeur: c, texte: formater(carte.soldes[c], c) }; }), etat.devise, function (v) { etat.devise = v; majComptes(); }));
    bloc.append(montant.bloc, comptes);
    majComptes();
    var ok = bouton('bouton bouton--large', TN.carte_rembourser);
    ok.type = 'submit';
    ok.id = 'remboursement-valider';
    bloc.appendChild(ok);
    var cle = C.cle();
    bloc.addEventListener('submit', function (ev) {
      ev.preventDefault();
      ok.disabled = true;
      envoyer('/caisse/nuit/carte/rembourser', { carte: carte.id, devise: etat.devise, montant: montantLisible(montant.input.value), compte: etat.compte, cle: cle }).then(function (r) {
        ok.disabled = false;
        if (!r.ok) { erreurDans(bloc, r.j.erreur || TN.erreur); return; }
        toast(TN.remboursement_fait);
        afficher(r.j.carte);
      });
    });
    zone.appendChild(bloc);
  }

  // ---------- Entrée : contrôle des billets, invités, comptes de la nuit ----------

  function afficherCompteurs(c) {
    var z = document.getElementById('nuit-compteurs');
    if (!z || !c) { return; }
    z.textContent = [texte(TN.compteur_vendus, { n: c.vendus }), texte(TN.compteur_entres, { n: c.entres }), texte(TN.compteur_refus, { n: c.refus })].join(' · ');
  }
  if (N.entree) {
    afficherCompteurs(N.entree.compteurs);
    setInterval(function () {
      if (document.hidden || fenetre.open) { return; }
      lire('/caisse/entree/compteurs').then(function (r) { if (r.ok) { afficherCompteurs(r.j.compteurs); } });
    }, 20000);
  }
  var boutonControle = document.getElementById('nuit-controle');
  if (boutonControle) {
    boutonControle.addEventListener('click', function () {
      ouvrir(TN.controle_titre, function (z) {
        var resultat = el('div', 'nuit-controle');
        resultat.setAttribute('aria-live', 'assertive');
        var scanner = window.ScannerQr.monter(z, { textes: TN, auto: true, placeholder: TN.code_manuel, lu: function (code) {
          envoyer('/caisse/entree/controle', { code: code }).then(function (r) {
            resultat.textContent = '';
            if (!r.ok) { erreurDans(resultat, r.j.erreur || TN.erreur); scanner.reprendre(); return; }
            var ok = r.j.resultat === 'ok';
            resultat.className = 'nuit-controle nuit-controle--' + (ok ? 'ok' : 'refus');
            resultat.dataset.resultat = r.j.resultat;
            resultat.appendChild(el('strong', 'nuit-controle__titre', TN['controle_' + r.j.resultat] || r.j.resultat));
            var b = r.j.billet;
            if (b) {
              resultat.appendChild(el('span', '', [b.categorie, b.tarif, b.journee].filter(Boolean).join(' · ')));
              if (r.j.resultat === 'deja' && b.utilise_le) { resultat.appendChild(el('span', '', b.utilise_le + (b.utilise_par ? ' · ' + b.utilise_par : ''))); }
            }
            afficherCompteurs(r.j.compteurs);
            var suivant = bouton('bouton bouton--large', TN.controle_suivant);
            suivant.id = 'controle-suivant';
            suivant.addEventListener('click', function () { resultat.textContent = ''; resultat.className = 'nuit-controle'; scanner.reprendre(); });
            resultat.appendChild(suivant);
            suivant.focus();
          });
        } });
        z.appendChild(resultat);
      });
    });
  }
  var boutonInvites = document.getElementById('nuit-invites');
  if (boutonInvites) {
    boutonInvites.addEventListener('click', function () {
      ouvrir(TN.invites_titre, function (z) {
        var recherche = champ(TN.invite_chercher, { type: 'search', autocomplete: 'off', id: 'invite-recherche' });
        var liste = el('div', 'nuit-liste');
        liste.id = 'invites-liste';
        var minuterie = null;
        function charger() {
          lire('/caisse/entree/invites?q=' + encodeURIComponent(recherche.input.value.trim())).then(function (r) {
            liste.textContent = '';
            var invites = r.ok ? r.j.invites : [];
            if (!invites.length) { liste.appendChild(el('p', 'aide', TN.invite_aucun)); }
            invites.forEach(function (i) { liste.appendChild(ligneInvite(i)); });
          });
        }
        function ligneInvite(i) {
          var ligne = el('div', 'nuit-invite' + (i.arrives >= i.places ? ' nuit-invite--entre' : ''));
          ligne.dataset.invite = i.id;
          var t = el('div', 'nuit-invite__texte');
          t.appendChild(el('strong', '', i.nom + (i.places > 1 ? ' +' + (i.places - 1) : '')));
          t.appendChild(el('span', '', [i.promoteur, i.entree === 'gratuite' ? TN.invite_gratuite : TN.invite_reduite + (i.tarif ? ' · ' + i.tarif.nom + ' ' + formater(i.tarif.prix[D.principale] || 0, D.principale) : ''),
            texte(TN.invite_arrives, { n: i.arrives, places: i.places })].filter(Boolean).join(' · ')));
          ligne.appendChild(t);
          var reste = i.places - i.arrives;
          if (reste > 0) {
            var nombre = 1;
            var choix = puces(Array.from({ length: reste }, function (x, k) { return { valeur: k + 1, texte: String(k + 1) }; }), 1, function (v) { nombre = v; });
            if (reste > 1) { ligne.appendChild(choix); }
            var entrer = bouton('bouton bouton--petit', TN.invite_pointer);
            entrer.addEventListener('click', function () {
              entrer.disabled = true;
              envoyer('/caisse/entree/invites/' + i.id + '/pointer', { nombre: nombre }).then(function (r) {
                entrer.disabled = false;
                if (!r.ok) { erreurDans(ligne, r.j.erreur || TN.erreur); return; }
                afficherCompteurs(r.j.compteurs);
                if (i.entree === 'reduite' && i.tarif) {
                  // Entrée réduite : le tarif de l'invité part au ticket, à encaisser
                  C.ajouterLigne({ id: i.tarif.id, qte: nombre, inv: i.id, invNom: i.nom });
                  toast(TN.invite_ajoute_ticket);
                  fermer();
                  return;
                }
                ligne.replaceWith(ligneInvite(r.j.invite));
                if (r.j.billets) { zoneImpression(r.j.billets, i.nom); }
              });
            });
            ligne.appendChild(entrer);
          }
          return ligne;
        }
        recherche.input.addEventListener('input', function () { clearTimeout(minuterie); minuterie = setTimeout(charger, 220); });
        z.append(recherche.bloc, liste);
        charger();
        setTimeout(function () { recherche.input.focus(); }, 30);
      });
    });
  }

  // ---------- Vestiaire : dépôt sans frais, retour, objets restants ----------

  function majRestants(n) { var b = document.getElementById('nuit-restants-n'); if (b && typeof n === 'number') { b.textContent = String(n); } }
  var boutonDeposer = document.getElementById('nuit-deposer');
  if (boutonDeposer) {
    boutonDeposer.addEventListener('click', function () {
      ouvrir(TN.vestiaire_gratuit, function (z) {
        var f = el('form', 'nuit-form');
        var d = champ(TN.vestiaire_description, { maxlength: '160', autocomplete: 'off', id: 'depot-description' });
        var ok = bouton('bouton bouton--large', TN.vestiaire_deposer);
        ok.type = 'submit';
        f.append(d.bloc, ok);
        var cle = C.cle();
        f.addEventListener('submit', function (ev) {
          ev.preventDefault();
          ok.disabled = true;
          envoyer('/caisse/vestiaire/deposer', { description: d.input.value, cle: cle }).then(function (r) {
            ok.disabled = false;
            if (!r.ok) { erreurDans(f, r.j.erreur || TN.erreur); return; }
            majRestants(r.j.restants);
            zoneImpression(r.j.recu, TN.vestiaire_gratuit);
          });
        });
        z.appendChild(f);
        setTimeout(function () { d.input.focus(); }, 30);
      });
    });
  }
  var boutonRendre = document.getElementById('nuit-rendre');
  if (boutonRendre) {
    boutonRendre.addEventListener('click', function () {
      ouvrir(TN.vestiaire_rendre, function (z) {
        var resultat = el('div', 'nuit-controle');
        resultat.setAttribute('aria-live', 'assertive');
        var scanner = window.ScannerQr.monter(z, { textes: TN, placeholder: TN.vestiaire_numero, lu: function (code) {
          envoyer('/caisse/vestiaire/rendre', { code: code }).then(function (r) {
            resultat.textContent = '';
            resultat.className = 'nuit-controle nuit-controle--' + (r.ok ? 'ok' : 'refus');
            if (!r.ok) {
              resultat.appendChild(el('strong', 'nuit-controle__titre', r.j.code === 'deja' ? TN.vestiaire_deja : TN.controle_invalide));
              resultat.appendChild(el('span', '', r.j.erreur || TN.erreur));
            } else {
              resultat.appendChild(el('strong', 'nuit-controle__titre', texte(TN.vestiaire_rendu, { numero: r.j.ticket.numero })));
              if (r.j.ticket.description) { resultat.appendChild(el('span', '', r.j.ticket.description)); }
              majRestants(r.j.restants);
            }
            scanner.reprendre();
          });
        } });
        z.appendChild(resultat);
      });
    });
  }
  var boutonRestants = document.getElementById('nuit-restants');
  if (boutonRestants) {
    boutonRestants.addEventListener('click', function () {
      ouvrir(TN.vestiaire_restants, function (z) {
        var liste = el('ul', 'nuit-historique nuit-restants');
        z.appendChild(liste);
        lire('/caisse/vestiaire/liste').then(function (r) {
          var tickets = r.ok ? r.j.tickets : [];
          majRestants(tickets.length);
          if (!tickets.length) { liste.appendChild(el('li', '', TN.vestiaire_aucun)); }
          tickets.forEach(function (t) { liste.appendChild(el('li', '', 'N° ' + t.numero + ' · ' + (t.description || '—') + ' · ' + t.depose + (t.par ? ' · ' + t.par : ''))); });
        });
      });
    });
  }

  // ---------- Note : minimum de la table, invité (promoteur) ----------

  C.nuit.destination = function (zone, note, maj) {
    var m = note.minimum;
    if (m && !m.leve) {
      var code = D.principale;
      var p = el('div', 'nuit-progres' + (m.atteint ? ' nuit-progres--atteint' : ''));
      var minimum = m.minimum[code] || 0;
      var part = minimum > 0 ? Math.min(1, (m.consomme[code] || 0) / minimum) : 1;
      p.appendChild(el('span', 'nuit-progres__texte', m.atteint ? texte(TN.minimum_atteint, { minimum: formater(minimum, code) })
        : texte(TN.minimum_progres, { minimum: formater(minimum, code), reste: formater(m.manque[code] || 0, code) })));
      var barre = el('span', 'nuit-progres__barre');
      barre.setAttribute('role', 'progressbar');
      barre.setAttribute('aria-valuemin', '0');
      barre.setAttribute('aria-valuemax', '100');
      barre.setAttribute('aria-valuenow', String(Math.round(part * 100)));
      var plein = el('span', 'nuit-progres__plein');
      plein.style.width = (part * 100) + '%';
      barre.appendChild(plein);
      p.appendChild(barre);
      zone.appendChild(p);
    }
    if (N.invites) {
      var b = bouton('lien nuit-invite-note', note.invite ? texte(TN.invite_note_lie, { nom: note.invite.nom + (note.invite.promoteur ? ' (' + note.invite.promoteur + ')' : '') }) : TN.invite_note);
      b.id = 'note-invite';
      b.addEventListener('click', function () { lierInvite(note, maj); });
      zone.appendChild(b);
    }
  };
  function lierInvite(note, maj) {
    ouvrir(TN.invite_note_titre, function (z) {
      var liste = el('div', 'nuit-liste');
      z.appendChild(liste);
      function lier(id) {
        envoyer('/caisse/notes/' + note.id + '/invite', { invite: id }).then(function (r) {
          if (!r.ok) { erreurDans(liste, r.j.erreur || TN.erreur); return; }
          fermer();
          maj(r.j.note);
        });
      }
      lire('/caisse/entree/invites?arrives=1').then(function (r) {
        var invites = r.ok ? r.j.invites : [];
        if (!invites.length) { liste.appendChild(el('p', 'aide', TN.invite_note_aucun)); }
        invites.forEach(function (i) {
          var b = bouton('ardoise-client');
          b.dataset.invite = i.id;
          b.append(el('span', 'ardoise-client__nom', i.nom), el('span', 'ardoise-client__detail', i.promoteur || ''));
          b.addEventListener('click', function () { lier(i.id); });
          liste.appendChild(b);
        });
        if (note.invite) {
          var retirer = bouton('lien', TN.retirer);
          retirer.addEventListener('click', function () { lier(0); });
          liste.appendChild(retirer);
        }
      });
    });
  }
  // La note est déjà dessinée (caisse-salle.js s'est chargé avant) : on la redessine avec le minimum
  C.redessiner();
})();
