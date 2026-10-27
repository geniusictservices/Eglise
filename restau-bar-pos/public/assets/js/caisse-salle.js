// Caisse : salle et notes ouvertes (module Salle et cuisine).
// - Plan de salle (bouton « Salle ») : tables par zone avec leur statut, le montant et la durée de leur note ;
//   notes nommées (« Bar — Patrick ») ; toucher une table ouvre sa note (ou en prépare une nouvelle).
// - Note choisie : le panneau du ticket montre les lignes déjà envoyées (statut en cuisine, qui a commandé), puis les
//   nouvelles ; « Envoyer » les enregistre sans payer (plusieurs serveurs ajoutent à la même note), « Addition »
//   imprime la pré-note, « Partager » paie par articles ou en parts égales, « Encaisser » ouvre la fenêtre de paiement
//   de caisse.js avec la note pour source (devises, comptes, ardoise, monnaie : tout reste pareil).
// - Toutes les quelques secondes : état de la salle, ruptures (la tuile se grise), plats prêts pour moi, prix changés.
// La note choisie est gardée dans l'onglet (sessionStorage), par personne, comme le ticket.
(function () {
  'use strict';

  var C = window.Caisse;
  if (!C || !C.D.salle) { return; }
  var D = C.D;
  var S = D.salle;
  var T = S.textes;
  var racine = document.getElementById('caisse');
  var el = C.el;
  var bouton = C.bouton;
  var formater = C.formater;

  // ---------- État ----------

  var cle = 'caisse.note.' + D.cleTicket;
  var courante = null;   // { note: id|null, table: id|null, nom: texte|null, reservation: id|null }
  var note = null;       // note du serveur (JSON de CaisseNoteController::noteJson)
  var salle = null;      // dernier état de la salle (tables, notes, prêts)
  var mode = 'ouvrir';   // plan : ouvrir | envoyer | transfert | fusion
  var zoneActive = null;
  var envoiEnCours = false;
  var cleEnvoi = null;
  var partage = null;    // partage en cours : { mode, parts }
  var vus = {};          // plats prêts déjà signalés (bandeau fermé)

  function lireCourante() {
    try {
      var c = JSON.parse(sessionStorage.getItem(cle));
      if (c && (c.note || c.table || c.nom)) {
        return { note: +c.note || null, table: +c.table || null, nom: typeof c.nom === 'string' ? c.nom.slice(0, 80) : null, reservation: +c.reservation || null };
      }
    } catch (e) { /* stockage indisponible */ }
    return null;
  }
  function garder() {
    try {
      if (courante) { sessionStorage.setItem(cle, JSON.stringify(courante)); } else { sessionStorage.removeItem(cle); }
    } catch (e) { /* stockage indisponible */ }
  }
  var tablesParId = {};
  S.tables.forEach(function (t) { tablesParId[t.id] = t; });

  // ---------- Requêtes ----------

  function requete(methode, url, champs) {
    var options = { method: methode, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (methode === 'POST') {
      var corps = new FormData();
      corps.append('csrf', D.csrf);
      corps.append('etab', D.etab);
      Object.keys(champs || {}).forEach(function (k) { if (champs[k] !== undefined && champs[k] !== null) { corps.append(k, champs[k]); } });
      options.body = corps;
    } else {
      url += (url.indexOf('?') === -1 ? '?' : '&') + 'etab=' + encodeURIComponent(D.etab);
    }
    return fetch(url, options).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (j && j.csrf) { D.csrf = j.csrf; }
        if (!r.ok) { var e = new Error(j.erreur || T.erreur); e.statut = r.status; e.donnees = j; throw e; }
        return j;
      });
    });
  }

  // ---------- Montants ----------

  function totalNote(code) {
    if (!note) { return 0; }
    var t = note.totaux[code];
    return typeof t === 'number' ? t : null;
  }
  function articlesNote() {
    if (!note) { return 0; }
    return note.lignes.reduce(function (n, l) { return l.annulee ? n : n + Math.ceil(l.reste); }, 0);
  }
  function resteNote() { return note && note.lignes.some(function (l) { return !l.annulee && l.reste > 0.0005; }); }
  function duree(minutes) {
    if (minutes < 60) { return T.minutes.replace('{n}', String(minutes)); }
    return T.heures.replace('{h}', String(Math.floor(minutes / 60))).replace('{m}', String(minutes % 60).padStart(2, '0'));
  }
  function titreCourante() {
    if (note) { return note.table ? T.table.replace('{nom}', note.table.nom) : (note.nom || ''); }
    if (courante && courante.table && tablesParId[courante.table]) { return T.table.replace('{nom}', tablesParId[courante.table].nom); }
    return courante ? courante.nom || '' : '';
  }

  // ---------- Interface du panneau ----------

  var elDestination = document.getElementById('destination');
  var elNoteLignes = document.getElementById('note-lignes');
  var elNouveau = document.getElementById('nouveau-titre');
  var elActions = document.getElementById('actions-note');

  C.note = {
    actif: function () { return !!courante; },
    vide: function () { return !resteNote(); },
    totalEn: totalNote,
    articles: articlesNote,
    dessiner: dessinerPanneau,
    payer: payer,
    payee: notePayee,
    apresPaiement: apresPaiement
  };
  C.optionsLigne = ouvrirOptions;

  function dessinerPanneau() {
    var locales = C.lignes();
    // Destination : la note choisie, ou rien (vente au comptoir)
    elDestination.textContent = '';
    elDestination.hidden = !courante;
    if (courante) {
      var texte = el('div', 'destination__texte');
      texte.appendChild(el('strong', 'destination__nom', titreCourante() || T.nouvelle_note));
      var infos = [];
      if (note) {
        if (note.zoneNom) { infos.push(note.zoneNom); }
        if (note.serveur) { infos.push(note.serveur.nom); }
        infos.push(duree(note.minutes));
        if (note.addition) { infos.push(T.addition_demandee); }
      } else {
        infos.push(T.nouvelle_table_note);
      }
      texte.appendChild(el('span', 'destination__info', infos.join(' · ')));
      elDestination.appendChild(texte);
      var quitter = bouton('icone destination__quitter', '×', T.vente_comptoir);
      quitter.id = 'note-quitter';
      quitter.title = T.vente_comptoir;
      quitter.addEventListener('click', function () { choisir(null); });
      elDestination.appendChild(quitter);
      // Nuit (caisse-nuit.js) : minimum de consommation de la table, invité de la note
      if (note && C.nuit && C.nuit.destination) { C.nuit.destination(elDestination, note, function (n) { note = n; C.redessiner(); }); }
    }

    // Lignes déjà envoyées
    elNoteLignes.textContent = '';
    var lignes = note ? note.lignes : [];
    elNoteLignes.hidden = lignes.length === 0;
    lignes.forEach(function (l) { elNoteLignes.appendChild(ligneEnvoyee(l)); });
    elNouveau.hidden = !(courante && note && lignes.length && locales.length);

    // Boutons de la note
    elActions.textContent = '';
    var envoyables = locales.length > 0 && S.modules.tables;
    elActions.hidden = !(courante || envoyables);
    if (elActions.hidden) { return; }
    // Envoyer : seulement s'il y a de nouvelles lignes (vers la note choisie, ou choisir une table)
    if (locales.length) {
      var envoyer = bouton('bouton actions-note__envoyer actions-note__large', courante ? T.envoyer : T.envoyer_table);
      envoyer.id = 'envoyer';
      envoyer.disabled = envoiEnCours;
      envoyer.addEventListener('click', function () { if (courante) { envoyerLignes(); } else { ouvrirSalle('envoyer'); } });
      elActions.appendChild(envoyer);
    }
    if (note && resteNote()) {
      var addition = bouton('bouton bouton--secondaire', T.addition);
      addition.id = 'addition';
      addition.addEventListener('click', demanderAddition);
      elActions.appendChild(addition);
      if (D.encaisser && S.modules.partage) {
        var partager = bouton('bouton bouton--secondaire', T.partager_court);
        partager.id = 'partager';
        partager.addEventListener('click', function () { ouvrirPartage(); });
        elActions.appendChild(partager);
      }
    }
    if (note) {
      var transfert = bouton('bouton bouton--secondaire', T.transferer);
      transfert.id = 'note-transferer';
      transfert.addEventListener('click', function () { ouvrirSalle('transfert'); });
      var fusion = bouton('bouton bouton--secondaire', T.fusionner);
      fusion.id = 'note-fusionner';
      fusion.addEventListener('click', function () { ouvrirSalle('fusion'); });
      elActions.append(transfert, fusion);
    }
    var attente = note ? note.lignes.filter(function (l) { return !l.annulee && l.statut === 'attente'; }).length : 0;
    if (attente) {
      var lancer = bouton('bouton bouton--secondaire actions-note__lancer actions-note__large', T.lancer.replace('{n}', String(attente)));
      lancer.id = 'lancer';
      lancer.addEventListener('click', lancerSuite);
      elActions.appendChild(lancer);
    }
    if (note && !note.lignes.some(function (l) { return !l.annulee; }) && !locales.length) {
      var fermer = bouton('lien actions-note__large', T.fermer_vide);
      fermer.id = 'note-fermer-vide';
      fermer.addEventListener('click', fermerVide);
      elActions.appendChild(fermer);
    }
  }

  function ligneEnvoyee(l) {
    var li = el('li', 'note-ligne' + (l.annulee ? ' note-ligne--annulee' : '') + (l.reste <= 0.0005 && !l.annulee ? ' note-ligne--payee' : ''));
    li.dataset.ligne = l.id;
    var qte = el('span', 'note-ligne__qte', (l.annulee ? l.qte : quantite(l.reste > 0.0005 ? l.reste : l.qte)) + ' ×');
    var texte = el('div', 'note-ligne__texte');
    texte.appendChild(el('span', 'note-ligne__nom', l.libelle + (l.format ? ' · ' + l.format : '')));
    if (l.rem) { texte.appendChild(el('span', 'ligne__precision', l.rem)); }
    if (l.all) { texte.appendChild(el('span', 'ligne__allergie', T.allergie_badge + ' ' + l.all)); }
    var detail = [T.par.replace('{nom}', l.par || '—').replace('{heure}', l.le)];
    if (l.annulee) { detail.push(T.annulee + (l.motif ? ' : ' + l.motif : '') + (l.validePar ? ' (' + l.validePar + ')' : '')); }
    else if (l.payee > 0.0005 && l.reste > 0.0005) { detail.push(quantite(l.payee) + ' ✓'); }
    texte.appendChild(el('span', 'note-ligne__detail', detail.join(' · ')));
    li.append(qte, texte);
    var droite = el('div', 'note-ligne__droite');
    if (!l.annulee && l.statut !== 'sans') {
      var badge = el('span', 'badge note-statut note-statut--' + l.statut, T['statut_' + l.statut]);
      droite.appendChild(badge);
    }
    var pu = l.prix[D.principale];
    if (!l.annulee) { droite.appendChild(el('span', 'note-ligne__montant', typeof pu === 'number' ? formater(pu * (l.reste > 0.0005 ? l.reste : l.qte), D.principale) : '—')); }
    li.appendChild(droite);
    // Plat prêt : « Servi » à la place d'« annuler » (on peut annuler une fois servi)
    if (!l.annulee && l.statut === 'pret') {
      var servi = bouton('note-ligne__servi', T.servi, T.servi + ' : ' + l.libelle);
      servi.addEventListener('click', function () { marquerServi(note.id, [l.id]); });
      li.appendChild(servi);
    } else if (!l.annulee && l.payee <= 0.0005) {
      var annuler = bouton('note-ligne__annuler', '×', T.annuler_ligne + ' : ' + l.libelle);
      annuler.title = T.annuler_ligne;
      annuler.addEventListener('click', function () { ouvrirAnnulation(l); });
      li.appendChild(annuler);
    }
    return li;
  }
  function quantite(q) {
    if (Math.abs(q - Math.round(q)) < 0.0005) { return String(Math.round(q)); }
    for (var k = 2; k <= 20; k++) {
      var m = q * k;
      if (Math.abs(m - Math.round(m)) < 0.01 * k) { return Math.round(m) + '/' + k; }
    }
    return q.toFixed(2).replace('.', D.langue === 'fr' ? ',' : '.');
  }

  // ---------- Choisir une note ----------

  // destination : null (comptoir), { note }, { table, reservation? } ou { nom }
  function choisir(destination) {
    courante = destination ? { note: destination.note || null, table: destination.table || null, nom: destination.nom || null, reservation: destination.reservation || null } : null;
    note = null;
    partage = null;
    garder();
    // La grille de prix de la zone de la table (Carré VIP) : la caisse se recharge dans cette zone
    if (courante && changerDeZone()) { return false; }
    if (courante && courante.note) { chargerNote(courante.note); }
    C.redessiner();
    return true;
  }
  function zoneDeCourante() {
    if (note) { return note.zone || ''; }
    if (courante && courante.table && tablesParId[courante.table]) { return tablesParId[courante.table].zone || ''; }
    return null;
  }
  function changerDeZone() {
    var zone = zoneDeCourante();
    if (zone === null || zone === (D.zone || '')) { return false; }
    var grille = zone === '' ? S.grilleSansZone : (S.zones.filter(function (z) { return z.code === zone; })[0] || {}).grille;
    if (!grille || grille === D.grille) { return false; }
    var url = new URL(window.location.href);
    url.searchParams.set('zone', zone);
    if (!url.searchParams.get('e') && document.getElementById('e')) { url.searchParams.set('e', D.etab); }
    window.location.href = url.toString();
    return true;
  }
  function chargerNote(id) {
    return requete('GET', '/caisse/notes/' + id).then(function (j) {
      if (!courante || courante.note !== id) { return; }
      if (j.note.statut !== 'ouverte') { toast(T.note_fermee); choisir(null); return; }
      note = j.note;
      if (changerDeZone()) { return; }
      C.redessiner();
    }).catch(function (e) {
      if (e.statut === 404 || e.statut === 409) { choisir(null); }
      else { toast(e.message); }
    });
  }

  // ---------- Envoyer ----------

  function envoyerLignes() {
    var locales = C.lignes();
    if (!courante || !locales.length || envoiEnCours) { return Promise.resolve(false); }
    envoiEnCours = true;
    cleEnvoi = cleEnvoi || C.cle();
    dessinerPanneau();
    var envoi = {
      cle: cleEnvoi, note: courante.note, table: courante.note ? null : courante.table, nom: courante.note || courante.table ? null : courante.nom,
      reservation: courante.reservation, zone: D.zone,
      lignes: locales.map(function (l) {
        var ligne = { id: l.id, qte: l.qte, rem: l.rem || null, all: l.all || null, temps: l.temps === 2 ? 2 : 1 };
        if (l.comp) { ligne.comp = l.comp.map(function (c) { return { id: c.id, qte: c.qte }; }); }   // formule : ses composants
        return ligne;
      })
    };
    return requete('POST', '/caisse/notes/envoyer', { envoi: JSON.stringify(envoi) }).then(function (j) {
      envoiEnCours = false;
      cleEnvoi = null;
      note = j.note;
      courante = { note: j.note.id, table: null, nom: null, reservation: null };
      garder();
      C.viderLocal();
      var bons = j.bons || [];
      if (j.verrouille) {
        toast(T.envoye_verrou);
        setTimeout(function () { window.location.reload(); }, 1500);
        return false;
      }
      toast(T.envoye, bons.length ? { texte: T.imprimer_bons, action: function () { imprimerBons(bons); } } : null);
      actualiser();
      return true;
    }).catch(function (e) {
      envoiEnCours = false;
      dessinerPanneau();
      if (e.donnees && e.donnees.code === 'note_fermee') { choisir(null); }
      toast(e.message);
      return false;
    });
  }
  function imprimerBons(bons) {
    Promise.all(bons.map(function (id) { return requete('GET', '/caisse/bon/' + id).then(function (j) { return j.bon; }); }))
      .then(function (html) { C.imprimer(html.join('')); })
      .catch(function (e) { toast(e.message); });
  }

  // ---------- Addition, suite, servi ----------

  function demanderAddition() {
    if (!note) { return; }
    var suite = C.lignes().length ? envoyerLignes() : Promise.resolve(true);
    suite.then(function (ok) {
      if (ok === false && C.lignes().length) { return; }
      return requete('POST', '/caisse/notes/' + note.id + '/addition').then(function (j) {
        note = j.note;
        C.redessiner();
        C.imprimer(j.addition);
      });
    }).catch(function (e) { toast(e.message); });
  }
  function lancerSuite() {
    if (!note) { return; }
    requete('POST', '/caisse/notes/' + note.id + '/lancer').then(function (j) {
      note = j.note;
      C.redessiner();
      toast(T.envoye, j.bons && j.bons.length ? { texte: T.imprimer_bons, action: function () { imprimerBons(j.bons); } } : null);
    }).catch(function (e) { toast(e.message); });
  }
  function marquerServi(noteId, ids) {
    requete('POST', '/caisse/notes/' + noteId + '/servi', { lignes: JSON.stringify(ids) }).then(function () {
      ids.forEach(function (id) { vus[id] = true; });
      if (note && note.id === noteId) { chargerNote(noteId); }
      actualiser();
    }).catch(function (e) { toast(e.message); });
  }
  function fermerVide() {
    if (!note) { return; }
    requete('POST', '/caisse/notes/' + note.id + '/fermer').then(function () { choisir(null); actualiser(); }).catch(function (e) { toast(e.message); });
  }

  // ---------- Encaisser une note ----------

  // lignes : ce que paie cette part (remises de caisse-nuit.js) ; minimum : celui de la note, si ce paiement la ferme
  function source(demande, totaux, titre, lignes) {
    var parts = lignes || restantes().map(function (l) { return { l: l, q: l.reste }; });
    var restant = restantes().reduce(function (s, l) { return s + l.reste; }, 0) - parts.reduce(function (s, p) { return s + p.q; }, 0);
    return {
      note: demande, version: note.version, totaux: totaux, servi: note.serveur ? note.serveur.id : null, titre: titre || titreCourante(),
      lignes: parts.map(function (p) {
        var brut = {};
        D.devises.forEach(function (d) { var pu = p.l.prix[d.code]; brut[d.code] = typeof pu === 'number' ? pu * p.q : null; });
        return { cle: 'n' + p.l.id, nom: p.l.libelle, brut: brut, format: p.l.format };
      }),
      minimum: restant <= 0.0005 * Math.max(1, note.lignes.length) ? note.minimum : null
    };
  }
  function payer(compteId, ardoise) {
    if (!D.encaisser) { return; }
    var suite = C.lignes().length ? envoyerLignes() : Promise.resolve(true);
    suite.then(function (ok) {
      if (ok === false || !note || !resteNote()) { return; }
      partage = null;
      C.ouvrirPaiement(compteId, ardoise, source({ id: note.id, mode: 'tout' }, note.totaux));
    });
  }
  var rechargement = null;
  function notePayee(reponse) {
    rechargement = null;
    if (!courante || !note) { return; }
    if (reponse.note && reponse.note.statut !== 'ouverte') {
      // Tout est payé : la caisse revient au comptoir
      courante = null;
      note = null;
      partage = null;
      garder();
      C.redessiner();
    } else {
      rechargement = chargerNote(note.id);
    }
    actualiser();
  }
  function apresPaiement() {
    (rechargement || Promise.resolve()).then(function () {
      if (partage && note && resteNote()) { ouvrirPartage(partage.mode); }
    });
  }

  // ---------- Partage de l'addition ----------

  var fenetrePartage = document.getElementById('partage-fenetre');
  var choixLignes = {};   // ligne → quantité choisie
  var nombreParts = 2;
  function ouvrirPartage(modeInitial) {
    if (!note || !resteNote()) { return; }
    var envoi = C.lignes().length ? envoyerLignes() : Promise.resolve(true);
    envoi.then(function (ok) {
      if (ok === false || !note) { return; }
      choixLignes = {};
      if (note.parts) { nombreParts = note.parts; }
      partage = partage || { mode: modeInitial || (note.parts ? 'egal' : 'lignes') };
      if (modeInitial) { partage.mode = modeInitial; }
      dessinerPartage();
      if (!fenetrePartage.open) { fenetrePartage.showModal(); }
    });
  }
  function restantes() { return note.lignes.filter(function (l) { return !l.annulee && l.reste > 0.0005; }); }
  function dessinerPartage() {
    document.querySelectorAll('#partage-modes button').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.mode === partage.mode)); });
    var zoneLignes = document.getElementById('partage-lignes');
    var zoneEgal = document.getElementById('partage-egal');
    zoneLignes.hidden = partage.mode !== 'lignes';
    zoneEgal.hidden = partage.mode !== 'egal';
    zoneLignes.textContent = '';
    restantes().forEach(function (l) {
      var ligne = el('div', 'partage-ligne');
      var texte = el('div', 'partage-ligne__texte');
      texte.appendChild(el('span', 'partage-ligne__nom', quantite(l.reste) + ' × ' + l.libelle));
      var pu = l.prix[D.principale];
      texte.appendChild(el('span', 'partage-ligne__prix', typeof pu === 'number' ? formater(pu, D.principale) : '—'));
      ligne.appendChild(texte);
      var pas = el('div', 'pas');
      var max = l.reste;
      var entier = Math.abs(max - Math.round(max)) < 0.0005;
      var valeur = choixLignes[l.id] || 0;
      var moins = bouton('pas__bouton', '−', T.selection_vide + ' : ' + l.libelle);
      moins.disabled = valeur <= 0;
      moins.addEventListener('click', function () { choixLignes[l.id] = entier ? Math.max(0, valeur - 1) : 0; dessinerPartage(); });
      var plus = bouton('pas__bouton', '+', l.libelle);
      plus.disabled = valeur >= max - 0.0005;
      plus.dataset.ligne = l.id;
      plus.addEventListener('click', function () { choixLignes[l.id] = entier ? Math.min(max, valeur + 1) : max; dessinerPartage(); });
      pas.append(moins, el('span', 'pas__qte', quantite(valeur)), plus);
      ligne.appendChild(pas);
      zoneLignes.appendChild(ligne);
    });
    // Parts égales : 2 à 8, la part suivante du reste
    var nombres = document.getElementById('partage-nombres');
    nombres.textContent = '';
    [2, 3, 4, 5, 6, 8].forEach(function (n) {
      var b = bouton('', String(n));
      b.dataset.parts = n;
      b.setAttribute('aria-pressed', String(n === nombreParts));
      b.addEventListener('click', function () { nombreParts = n; dessinerPartage(); });
      nombres.appendChild(b);
    });
    var totaux = totauxPartage();
    var bilan = document.getElementById('partage-bilan');
    bilan.textContent = '';
    var codes = Object.keys(totaux).filter(function (c) { return typeof totaux[c] === 'number'; });
    var principale = totaux[D.principale];
    bilan.appendChild(el('span', '', partage.mode === 'egal' ? T.part_de.replace('{n}', String(numeroPart())).replace('{total}', String(nombreParts)) : T.payer_selection));
    bilan.appendChild(el('strong', '', codes.map(function (c) { return formater(totaux[c], c); }).join('  ·  ')));
    document.getElementById('partage-part').textContent = T.reste_note + ' : ' + formater(totalNote(D.principale) || 0, D.principale);
    var payer = document.getElementById('partage-payer');
    payer.textContent = partage.mode === 'egal' ? T.payer_part : T.payer_selection;
    payer.disabled = !(principale > 0);
  }
  function numeroPart() { return note.parts === nombreParts ? Math.min(nombreParts, note.partsPayees + 1) : 1; }
  // Même calcul que le serveur (NoteRepository::part) : quantités au millième, la dernière part prend le reste exact
  function partDemandee() {
    if (partage.mode === 'egal') {
      var k = note.parts === nombreParts ? Math.max(1, nombreParts - note.partsPayees) : nombreParts;
      return { demande: { id: note.id, mode: 'egal', parts: nombreParts }, lignes: restantes().map(function (l) { return { l: l, q: k === 1 ? l.reste : Math.round(l.reste / k * 1000) / 1000 }; }) };
    }
    var choisies = restantes().filter(function (l) { return choixLignes[l.id] > 0; }).map(function (l) { return { l: l, q: choixLignes[l.id] }; });
    return { demande: { id: note.id, mode: 'lignes', lignes: choisies.map(function (c) { return { id: c.l.id, qte: c.q }; }) }, lignes: choisies };
  }
  function totauxPartage() {
    var p = partDemandee();
    var totaux = {};
    D.devises.forEach(function (d) {
      var t = 0;
      for (var i = 0; i < p.lignes.length; i++) {
        var pu = p.lignes[i].l.prix[d.code];
        if (typeof pu !== 'number') { t = null; break; }
        t += pu * p.lignes[i].q;
      }
      totaux[d.code] = p.lignes.length ? t : 0;
    });
    return totaux;
  }
  document.querySelectorAll('#partage-modes button').forEach(function (b) {
    b.addEventListener('click', function () { partage.mode = b.dataset.mode; dessinerPartage(); });
  });
  document.querySelector('[data-fermer-partage]').addEventListener('click', function () { partage = null; fenetrePartage.close(); });
  fenetrePartage.addEventListener('cancel', function () { partage = null; });
  document.getElementById('partage-payer').addEventListener('click', function () {
    var p = partDemandee();
    if (!p.lignes.length) { return; }
    var titre = titreCourante() + (partage.mode === 'egal' ? ' · ' + T.part_de.replace('{n}', String(numeroPart())).replace('{total}', String(nombreParts)) : '');
    fenetrePartage.close();
    C.ouvrirPaiement(null, false, source(p.demande, totauxPartage(), titre, p.lignes));
  });

  // ---------- Précisions d'une ligne (avant l'envoi) ----------

  var fenetreLigne = document.getElementById('ligne-fenetre');
  var ligneOptions = null;
  var remarquesChoisies = [];
  function ouvrirOptions(index) {
    var l = C.lignes()[index];
    if (!l) { return; }
    ligneOptions = index;
    document.getElementById('ligne-titre').textContent = C.nomLigne(l.id);
    var connues = (l.rem || '').split(', ').filter(function (r) { return S.remarques.indexOf(r) !== -1; });
    remarquesChoisies = connues;
    document.getElementById('ligne-remarque').value = (l.rem || '').split(', ').filter(function (r) { return r && S.remarques.indexOf(r) === -1; }).join(', ');
    document.getElementById('ligne-allergie').value = l.all || '';
    var temps = document.getElementById('ligne-temps');
    if (temps) { temps.checked = l.temps === 2; }
    var zone = document.getElementById('ligne-remarques');
    zone.textContent = '';
    S.remarques.forEach(function (r) {
      var b = bouton('puce', r);
      b.setAttribute('aria-pressed', String(remarquesChoisies.indexOf(r) !== -1));
      b.addEventListener('click', function () {
        var i = remarquesChoisies.indexOf(r);
        if (i === -1) { remarquesChoisies.push(r); } else { remarquesChoisies.splice(i, 1); }
        b.setAttribute('aria-pressed', String(i === -1));
      });
      zone.appendChild(b);
    });
    fenetreLigne.showModal();
  }
  fenetreLigne.addEventListener('close', function () {
    if (fenetreLigne.returnValue !== 'valider' || ligneOptions === null) { ligneOptions = null; return; }
    var libre = document.getElementById('ligne-remarque').value.trim();
    var rem = remarquesChoisies.concat(libre ? [libre] : []).join(', ');
    var temps = document.getElementById('ligne-temps');
    C.changerLigne(ligneOptions, { rem: rem, all: document.getElementById('ligne-allergie').value.trim(), temps: temps && temps.checked ? 2 : 1 });
    ligneOptions = null;
  });

  // ---------- Annuler une ligne déjà envoyée ----------

  var fenetreAnnuler = document.getElementById('annuler-fenetre');
  var annulation = null;  // { ligne, quantite, motif, valideur }
  function ouvrirAnnulation(l) {
    annulation = { ligne: l, quantite: l.qte, motif: S.motifs[0].code, valideur: null };
    var validation = l.statut !== 'attente' && !S.annuler;
    document.getElementById('annuler-ligne').textContent = l.qte + ' × ' + l.libelle + (l.statut !== 'sans' ? ' · ' + T['statut_' + l.statut] : '');
    var blocQte = document.getElementById('annuler-quantite-bloc');
    blocQte.hidden = l.qte < 2;
    dessinerQuantiteAnnulation();
    var motifs = document.getElementById('annuler-motifs');
    motifs.textContent = '';
    S.motifs.forEach(function (m) {
      var b = bouton('puce', m.texte);
      b.dataset.motif = m.code;
      b.setAttribute('aria-pressed', String(m.code === annulation.motif));
      b.addEventListener('click', function () {
        annulation.motif = m.code;
        motifs.querySelectorAll('.puce').forEach(function (p) { p.setAttribute('aria-pressed', String(p.dataset.motif === m.code)); });
      });
      motifs.appendChild(b);
    });
    document.getElementById('annuler-validation').hidden = !validation;
    var valideurs = document.getElementById('annuler-valideurs');
    valideurs.textContent = '';
    S.valideurs.forEach(function (v) {
      var b = bouton('puce', v.nom);
      b.dataset.valideur = v.id;
      b.disabled = v.bloque;
      b.setAttribute('aria-pressed', 'false');
      b.addEventListener('click', function () {
        annulation.valideur = v.id;
        valideurs.querySelectorAll('.puce').forEach(function (p) { p.setAttribute('aria-pressed', String(+p.dataset.valideur === v.id)); });
        document.getElementById('annuler-pin').focus();
      });
      valideurs.appendChild(b);
    });
    document.getElementById('annuler-pin').value = '';
    document.getElementById('annuler-erreur').hidden = true;
    fenetreAnnuler.showModal();
  }
  function dessinerQuantiteAnnulation() {
    var zone = document.getElementById('annuler-quantite');
    zone.textContent = '';
    var moins = bouton('pas__bouton', '−', T.quantite_annuler);
    moins.disabled = annulation.quantite <= 1;
    moins.addEventListener('click', function () { annulation.quantite--; dessinerQuantiteAnnulation(); });
    var plus = bouton('pas__bouton', '+', T.quantite_annuler);
    plus.disabled = annulation.quantite >= annulation.ligne.qte;
    plus.addEventListener('click', function () { annulation.quantite++; dessinerQuantiteAnnulation(); });
    zone.append(moins, el('span', 'pas__qte', String(annulation.quantite)), plus);
  }
  document.querySelector('[data-fermer-annuler]').addEventListener('click', function () { fenetreAnnuler.close(); });
  document.getElementById('annuler-form').addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (!annulation) { return; }
    var erreur = document.getElementById('annuler-erreur');
    var validation = !document.getElementById('annuler-validation').hidden;
    var pin = document.getElementById('annuler-pin').value.replace(/\D/g, '');
    if (validation && (!annulation.valideur || pin.length !== 6)) {
      erreur.textContent = T.pin_aide;
      erreur.hidden = false;
      return;
    }
    var valider = document.getElementById('annuler-valider');
    valider.disabled = true;
    requete('POST', '/caisse/notes/lignes/' + annulation.ligne.id + '/annuler', {
      quantite: annulation.quantite, motif: annulation.motif, validateur: validation ? annulation.valideur : null, pin: validation ? pin : null
    }).then(function (j) {
      valider.disabled = false;
      note = j.note;
      fenetreAnnuler.close();
      C.redessiner();
      toast(T.annulee);
    }).catch(function (e) {
      valider.disabled = false;
      erreur.textContent = e.message;
      erreur.hidden = false;
      document.getElementById('annuler-pin').value = '';
    });
  });

  // ---------- Plan de salle ----------

  var fenetreSalle = document.getElementById('salle-fenetre');
  var elPlan = document.getElementById('plan');
  var zonesAvecTables = [];
  S.zones.forEach(function (z) { if (S.tables.some(function (t) { return t.zone === z.code; })) { zonesAvecTables.push({ code: z.code, nom: z.nom }); } });
  if (S.tables.some(function (t) { return !t.zone; })) { zonesAvecTables.push({ code: '', nom: zonesAvecTables.length ? T.sans_zone : T.salle }); }

  function ouvrirSalle(nouveauMode) {
    mode = nouveauMode || 'ouvrir';
    var aide = document.getElementById('salle-aide');
    var textesMode = { envoyer: T.choisir_table, transfert: T.choisir_transfert, fusion: T.choisir_fusion };
    aide.hidden = !textesMode[mode];
    aide.textContent = textesMode[mode] || '';
    document.getElementById('salle-comptoir').hidden = mode !== 'ouvrir';
    document.getElementById('salle-nouvelle-ok').textContent = mode === 'transfert' ? T.transferer : T.nouvelle_note;
    if (zoneActive === null) {
      var zoneCourante = zoneDeCourante();
      zoneActive = zonesAvecTables.some(function (z) { return z.code === (zoneCourante !== null ? zoneCourante : D.zone || ''); })
        ? (zoneCourante !== null ? zoneCourante : D.zone || '') : (zonesAvecTables[0] ? zonesAvecTables[0].code : '');
    }
    dessinerSalle();
    if (!fenetreSalle.open) { fenetreSalle.showModal(); }
    racine.classList.remove('ticket-ouvert');
    actualiser();
  }
  function dessinerSalle() {
    var zones = document.getElementById('salle-zones');
    zones.textContent = '';
    zones.hidden = zonesAvecTables.length < 2;
    zonesAvecTables.forEach(function (z) {
      var b = bouton('puce', z.nom);
      b.dataset.zone = z.code;
      b.setAttribute('aria-pressed', String(z.code === zoneActive));
      b.addEventListener('click', function () { zoneActive = z.code; dessinerSalle(); });
      zones.appendChild(b);
    });
    elPlan.textContent = '';
    elPlan.style.setProperty('--colonnes', S.colonnes);
    elPlan.style.setProperty('--rangees', S.rangees);
    var tables = S.tables.filter(function (t) { return (t.zone || '') === zoneActive; });
    document.getElementById('salle-vide').hidden = S.tables.length > 0;
    elPlan.hidden = tables.length === 0;
    tables.forEach(function (t) { elPlan.appendChild(boutonTable(t)); });
    // Notes sans table
    var liste = document.getElementById('salle-notes');
    liste.textContent = '';
    var notes = salle ? salle.notes : [];
    notes.forEach(function (n) {
      var b = bouton('salle-note' + (n.addition ? ' salle-note--addition' : ''));
      b.dataset.note = n.id;
      b.append(el('strong', 'salle-note__nom', n.nom || ('#' + n.id)), el('span', 'salle-note__info', formater(n.reste, D.principale) + ' · ' + duree(n.minutes) + (n.serveur ? ' · ' + n.serveur : '')));
      if (n.prets) { b.appendChild(el('span', 'badge note-statut--pret', T.pret)); }
      b.disabled = (mode === 'transfert') || (mode === 'fusion' && note && n.id === note.id);
      b.addEventListener('click', function () { toucherNote(n); });
      liste.appendChild(b);
    });
    if (!notes.length) { liste.appendChild(el('p', 'salle-notes__vide', T.aucune)); }
    document.getElementById('salle-nouvelle').hidden = mode === 'fusion';
  }
  function boutonTable(t) {
    var info = salle && salle.tables[t.id] ? salle.tables[t.id] : { statut: 'libre', note: null, reservation: null };
    var b = bouton('table-plan table-plan--' + t.forme + ' table-plan--' + info.statut);
    b.dataset.table = t.id;
    b.dataset.statut = info.statut;
    b.style.setProperty('--x', t.x);
    b.style.setProperty('--y', t.y);
    b.style.setProperty('--l', t.l);
    b.style.setProperty('--h', t.h);
    b.appendChild(el('span', 'table-plan__nom', t.nom));
    var statut = { libre: T.libre, occupee: T.occupee, addition: T.addition_demandee, reservee: T.reservee }[info.statut];
    b.appendChild(el('span', 'table-plan__statut', statut));
    if (info.note) {
      b.appendChild(el('span', 'table-plan__montant', formater(info.note.reste, D.principale)));
      b.appendChild(el('span', 'table-plan__duree', duree(info.note.minutes)));
      if (info.note.prets) { b.appendChild(el('span', 'table-plan__pret', T.pret)); }
    } else if (info.reservation) {
      b.appendChild(el('span', 'table-plan__duree', info.reservation.heure + ' · ' + info.reservation.nom));
    } else {
      b.appendChild(el('span', 'table-plan__duree', T.places.replace('{n}', String(t.places))));
    }
    var libelle = t.nom + ', ' + statut + (info.note ? ', ' + formater(info.note.reste, D.principale) : '') + (info.reservation ? ', ' + info.reservation.heure + ' ' + info.reservation.nom : '');
    b.setAttribute('aria-label', libelle);
    if (note && info.note && info.note.id === note.id) { b.setAttribute('aria-current', 'true'); }
    b.addEventListener('click', function () { toucherTable(t, info); });
    return b;
  }

  function toucherTable(t, info) {
    if (mode === 'transfert') {
      if (!note) { return; }
      if (info.note && info.note.id === note.id) { fenetreSalle.close(); return; }
      if (info.note) { fusionnerVers(info.note.id); return; }
      requete('POST', '/caisse/notes/' + note.id + '/transferer', { table: t.id }).then(function (j) {
        note = j.note;
        fenetreSalle.close();
        toast(T.transferer + ' · ' + t.nom);
        if (!changerDeZone()) { C.redessiner(); }
        actualiser();
      }).catch(function (e) {
        if (e.donnees && e.donnees.code === 'occupee' && e.donnees.cible) { fusionnerVers(e.donnees.cible); } else { toast(e.message); }
      });
      return;
    }
    if (mode === 'fusion') {
      if (!info.note || !note || info.note.id === note.id) { return; }
      fusionnerVers(info.note.id);
      return;
    }
    fenetreSalle.close();
    var pret = info.note ? choisir({ note: info.note.id }) : choisir({ table: t.id, reservation: info.reservation ? info.reservation.id : null });
    if (pret && mode === 'envoyer' && courante) { envoyerLignes(); }
  }
  function toucherNote(n) {
    if (mode === 'fusion') { fusionnerVers(n.id); return; }
    if (mode === 'transfert') { return; }
    fenetreSalle.close();
    if (choisir({ note: n.id }) && mode === 'envoyer') { envoyerLignes(); }
  }
  function fusionnerVers(cibleId) {
    if (!note) { return; }
    if (!window.confirm(T.occupee_fusionner)) { return; }
    requete('POST', '/caisse/notes/' + note.id + '/fusionner', { cible: cibleId }).then(function (j) {
      note = j.note;
      courante = { note: j.note.id, table: null, nom: null, reservation: null };
      garder();
      fenetreSalle.close();
      toast(T.fusionner);
      if (!changerDeZone()) { C.redessiner(); }
      actualiser();
    }).catch(function (e) { toast(e.message); });
  }
  document.getElementById('salle-nouvelle').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var nom = document.getElementById('salle-nom').value.trim();
    if (!nom) { document.getElementById('salle-nom').focus(); return; }
    document.getElementById('salle-nom').value = '';
    if (mode === 'transfert' && note) {
      requete('POST', '/caisse/notes/' + note.id + '/transferer', { nom: nom }).then(function (j) {
        note = j.note;
        fenetreSalle.close();
        C.redessiner();
        actualiser();
      }).catch(function (e) { toast(e.message); });
      return;
    }
    fenetreSalle.close();
    if (choisir({ nom: nom }) && mode === 'envoyer') { envoyerLignes(); }
  });
  document.getElementById('salle-comptoir').addEventListener('click', function () { fenetreSalle.close(); choisir(null); });
  document.querySelector('[data-fermer-salle]').addEventListener('click', function () { fenetreSalle.close(); });
  fenetreSalle.addEventListener('click', function (ev) { if (ev.target === fenetreSalle) { fenetreSalle.close(); } });
  var boutonSalle = document.getElementById('salle');
  if (boutonSalle) { boutonSalle.addEventListener('click', function () { ouvrirSalle('ouvrir'); }); }

  // ---------- Petits messages ----------

  var elToast = document.getElementById('salle-toast');
  var minuterieToast = null;
  function toast(texte, action) {
    elToast.textContent = '';
    elToast.appendChild(el('span', '', texte));
    if (action) {
      var b = bouton('lien salle-toast__action', action.texte);
      b.addEventListener('click', function () { action.action(); elToast.hidden = true; });
      elToast.appendChild(b);
    }
    elToast.hidden = false;
    clearTimeout(minuterieToast);
    minuterieToast = setTimeout(function () { elToast.hidden = true; }, action ? 8000 : 3500);
  }

  // ---------- Plats prêts ----------

  var elPrets = document.getElementById('prets-bandeau');
  function dessinerPrets() {
    var prets = (salle ? salle.prets : []).map(function (p) {
      return { note: p.note, titre: p.titre, lignes: p.lignes.filter(function (l) { return !vus[l.id]; }) };
    }).filter(function (p) { return p.lignes.length; });
    var badge = document.getElementById('salle-prets');
    var n = prets.reduce(function (s, p) { return s + p.lignes.length; }, 0);
    if (badge) { badge.hidden = n === 0; badge.textContent = String(n); badge.setAttribute('aria-label', T.plats_prets.replace('{n}', String(n))); }
    elPrets.textContent = '';
    elPrets.hidden = prets.length === 0;
    if (!prets.length) { return; }
    var p = prets[0];
    var texte = el('span', 'prets-bandeau__texte');
    texte.appendChild(el('strong', '', p.titre));
    texte.appendChild(el('span', '', ' · ' + T.prets.replace('{plats}', p.lignes.map(function (l) { return l.qte + ' × ' + l.libelle; }).join(', '))));
    elPrets.appendChild(texte);
    var servi = bouton('bouton bouton--petit', T.servi);
    servi.id = 'prets-servi';
    servi.addEventListener('click', function () { marquerServi(p.note, p.lignes.map(function (l) { return l.id; })); });
    var fermer = bouton('icone prets-bandeau__fermer', '×', T.fermer);
    fermer.addEventListener('click', function () { p.lignes.forEach(function (l) { vus[l.id] = true; }); dessinerPrets(); });
    elPrets.append(servi, fermer);
  }

  // ---------- Actualisation (toutes les quelques secondes) ----------

  var minuterie = null;
  var enCours = false;
  function actualiser() {
    if (enCours) { return; }
    enCours = true;
    requete('GET', '/caisse/salle' + (D.zone ? '?zone=' + encodeURIComponent(D.zone) : '')).then(function (j) {
      enCours = false;
      salle = j;
      C.disponibilites(j.indisponibles, j.epuises);
      if (fenetreSalle.open) { dessinerSalle(); }
      dessinerPrets();
      // Note modifiée par un collègue (lignes ajoutées, plat prêt) : on la recharge
      if (note) {
        var resume = null;
        Object.keys(j.tables).forEach(function (id) { if (j.tables[id].note && j.tables[id].note.id === note.id) { resume = j.tables[id].note; } });
        j.notes.forEach(function (n) { if (n.id === note.id) { resume = n; } });
        if (!resume) { chargerNote(note.id); } else if (resume.version !== note.version) { chargerNote(note.id); }
      }
      // Les prix ont changé (happy hour, service suivant) : on recharge dès que possible
      if (j.grille !== D.grille) { prixChanges(); }
    }).catch(function (e) {
      enCours = false;
      if (e.statut === 401) { window.location.reload(); }
    });
  }
  var prixAvertis = false;
  function prixChanges() {
    if (C.lignes().length === 0 && !C.fenetreOuverte()) { window.location.reload(); return; }
    if (prixAvertis) { return; }
    prixAvertis = true;
    toast(T.prix_changes, { texte: T.recharger, action: function () { window.location.reload(); } });
  }
  function demarrer() {
    clearInterval(minuterie);
    minuterie = setInterval(function () { if (!document.hidden) { actualiser(); } }, S.intervalle || 8000);
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { actualiser(); } });

  // ---------- Démarrage ----------

  courante = lireCourante();
  if (courante && courante.note) { chargerNote(courante.note); }
  C.redessiner();
  actualiser();
  demarrer();
})();
