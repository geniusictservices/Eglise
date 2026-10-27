// Écran de caisse : grille de produits, ticket en cours, totaux dans chaque devise.
// Le ticket en cours est gardé dans l'onglet du navigateur (par personne) jusqu'à l'encaissement,
// qui l'envoie au serveur (/caisse/encaisser) : le serveur recalcule tout et enregistre la vente.
// Avec le module Salle (caisse-salle.js, window.Caisse.note), le ticket peut aussi être envoyé à une table ou à une
// note nommée sans être payé : le panneau montre alors les lignes déjà envoyées, puis les nouvelles ; « Encaisser »
// paie la note (même fenêtre de paiement, source « note »). Chaque ligne peut porter une précision (cuisson),
// une allergie et un temps d'envoi (2 : « à lancer » par le serveur).
// Un produit à tailles (Primus Petite 33 cl, Grande 72 cl) est une seule tuile : ses formats sont ceux de
// toutes ses tailles, chacun avec le libellé de sa taille ; la ligne du ticket affiche « Primus Grande 72 cl ».
(function () {
  'use strict';

  var D = JSON.parse(document.getElementById('donnees-caisse').textContent);
  var T = D.textes;
  var racine = document.getElementById('caisse');
  // Couleurs des catégories : variables de volcan.css, lisibles en thème jour comme en thème nuit
  var PALETTE = [1, 2, 3, 4, 5, 6, 7, 8].map(function (n) { return 'var(--cat-' + n + ')'; });

  // ---------- Données ----------

  var formats = {};       // id du format → { format, produit }
  var couleurs = {};      // id de catégorie principale → couleur (reprise par ses sous-catégories)
  var categoriesParId = {};
  D.categories.forEach(function (c, i) { couleurs[c.id] = PALETTE[i % PALETTE.length]; categoriesParId[c.id] = c; });
  D.produits.forEach(function (p) {
    p.rechercheNormalisee = normaliser(p.recherche + ' ' + p.nom);
    p.formats.forEach(function (f) { formats[f.id] = { format: f, produit: p }; });
  });
  var devises = {};
  D.devises.forEach(function (d) { devises[d.code] = d; });

  // ---------- Préférences de l'appareil (taille des tuiles) ----------
  // Le thème jour / nuit est géré par theme.js (bouton #theme, mémorisé par établissement).

  function lire(cle, defaut) {
    try { var v = localStorage.getItem('caisse.' + cle); return v === null ? defaut : v; } catch (e) { return defaut; }
  }
  function ecrire(cle, valeur) {
    try { localStorage.setItem('caisse.' + cle, valeur); } catch (e) { /* stockage indisponible */ }
  }

  var taille = lire('taille.' + D.mode, D.mode === 'rapide' ? 'grande' : 'moyenne');

  function appliquerReglages() {
    racine.dataset.taille = taille;
    document.querySelectorAll('.segments [data-taille]').forEach(function (b) {
      b.setAttribute('aria-pressed', String(b.dataset.taille === taille));
    });
  }
  document.querySelectorAll('.segments [data-taille]').forEach(function (b) {
    b.addEventListener('click', function () { taille = b.dataset.taille; ecrire('taille.' + D.mode, taille); appliquerReglages(); });
  });
  appliquerReglages();

  // Changer d'établissement ou de zone recharge la caisse
  document.querySelectorAll('[data-auto-envoi] select').forEach(function (s) {
    s.addEventListener('change', function () { s.form.submit(); });
  });

  // ---------- Montants ----------

  function formater(montant, code) {
    var d = devises[code];
    var decimales = Math.abs(montant - Math.round(montant)) < 0.005 ? 0 : d.decimales;
    var fr = D.langue === 'fr';
    var parties = montant.toFixed(decimales).split('.');
    parties[0] = parties[0].replace(/\B(?=(\d{3})+(?!\d))/g, fr ? ' ' : ',');
    var nombre = parties.join(fr ? ',' : '.');
    return d.symboleAvant ? d.symbole + nombre : nombre + ' ' + d.symbole;
  }
  function prix(format, code) {
    var p = format.prix[code];
    return typeof p === 'number' ? p : null;
  }

  // ---------- Ticket ----------

  var cleTicket = 'caisse.ticket.' + D.cleTicket;
  var ticket = [];   // [{ id: id du format, qte: n, rem?: précision, all?: allergie, temps?: 2 (à lancer) }]
  try {
    // Ticket gardé par un chargement précédent : on ne garde que les lignes dont le format est encore vendu
    // (un format passé à une taille garde son id) ; un contenu illisible vide le ticket.
    var garde = JSON.parse(sessionStorage.getItem(cleTicket));
    ticket = (Array.isArray(garde) ? garde : []).filter(function (l) {
      return l && formats[l.id] && typeof l.qte === 'number' && l.qte > 0 && Math.floor(l.qte) === l.qte;
    }).map(function (l) {
      var ligne = { id: l.id, qte: l.qte };
      if (typeof l.rem === 'string' && l.rem) { ligne.rem = l.rem.slice(0, 160); }
      if (typeof l.all === 'string' && l.all) { ligne.all = l.all.slice(0, 160); }
      if (l.temps === 2) { ligne.temps = 2; }
      // Formule : composants choisis [{id, qte}] ; entrée réduite d'un invité
      if (Array.isArray(l.comp) && l.comp.length) { ligne.comp = l.comp.filter(function (c) { return c && +c.id > 0 && +c.qte > 0; }).map(function (c) { return { id: +c.id, qte: +c.qte, nom: String(c.nom || '') }; }); }
      if (+l.inv > 0) { ligne.inv = +l.inv; ligne.invNom = String(l.invNom || ''); }
      return ligne;
    });
  } catch (e) { ticket = []; }
  if (ticket.length === 0) {
    try { sessionStorage.removeItem(cleTicket); } catch (e) { /* stockage indisponible */ }
  }

  function sauver() {
    try { sessionStorage.setItem(cleTicket, JSON.stringify(ticket)); } catch (e) { /* stockage indisponible */ }
  }
  // « Primus Grande 72 cl » pour un format d'une taille, sinon le nom du produit
  // (espace insécable entre le nombre et l'unité : « 65 cl » ne se coupe pas en fin de ligne)
  function nomLigne(f) {
    return f.format.taille ? f.produit.nom + ' ' + f.format.taille.replace(/(\d) (?=\D)/g, '$1\u00a0') : f.produit.nom;
  }
  function quantiteProduit(produit) {
    return ticket.reduce(function (n, l) { return formats[l.id].produit === produit ? n + l.qte : n; }, 0);
  }
  // Une ligne avec une précision, une allergie ou « à lancer » reste à part : un autre toucher crée une ligne simple
  function simple(l) { return !l.rem && !l.all && l.temps !== 2 && !l.comp && !l.inv; }
  function ajouter(formatId) {
    var ligne = ticket.find(function (l) { return l.id === formatId && simple(l); });
    if (ligne) { ligne.qte++; } else { ticket.push({ id: formatId, qte: 1 }); }
    changerTicket(formatId);
  }
  function modifier(index, delta) {
    var ligne = ticket[index];
    if (!ligne) { return; }
    ligne.qte += delta;
    if (ligne.qte <= 0) { ticket.splice(index, 1); }
    changerTicket(ligne.id);
  }
  function changerTicket(formatId) {
    sauver();
    dessinerTicket();
    var produit = formatId && formats[formatId] ? formats[formatId].produit : null;
    if (produit) { majTuile(produit); }
  }

  var elLignes = document.getElementById('lignes');
  var elVide = document.getElementById('ticket-vide');
  var elTotal = document.getElementById('total');
  var elAutres = document.getElementById('autres-devises');
  var elVider = document.getElementById('vider');
  var elResume = document.getElementById('resume');

  function totalEn(code) {
    var total = 0;
    for (var i = 0; i < ticket.length; i++) {
      var p = prix(formats[ticket[i].id].format, code);
      if (p === null) { return null; }
      total += p * ticket[i].qte;
    }
    return total;
  }
  // Note ouverte choisie (caisse-salle.js) : son reste à payer s'ajoute aux nouvelles lignes
  function noteActive() { return window.Caisse && window.Caisse.note && window.Caisse.note.actif() ? window.Caisse.note : null; }
  function totalAffiche(code) {
    var local = totalEn(code);
    var note = noteActive();
    if (!note) { return local; }
    var reste = note.totalEn(code);
    return local === null || reste === null ? null : local + reste;
  }

  function dessinerTicket() {
    elLignes.textContent = '';
    var nbArticles = 0;
    ticket.forEach(function (l, index) {
      var f = formats[l.id];
      nbArticles += l.qte;
      var pu = prix(f.format, D.principale);
      var li = el('li', 'ligne');
      li.style.setProperty('--cat', couleurs[f.produit.categorie]);
      var nom = nomLigne(f);
      // Avec la salle : toucher le nom ouvre les précisions (cuisson, allergie, à lancer plus tard)
      var options = window.Caisse && window.Caisse.optionsLigne;
      var texte = options ? bouton('ligne__texte ligne__texte--options', undefined, (T.precisions || '') + ' : ' + nom) : el('div', 'ligne__texte');
      texte.appendChild(el('span', 'ligne__nom', nom));
      if (!f.format.unite || f.format.freres > 1) { texte.appendChild(el('span', 'ligne__format', f.format.nom)); }
      if (l.rem) { texte.appendChild(el('span', 'ligne__precision', l.rem)); }
      if (l.all) { texte.appendChild(el('span', 'ligne__allergie', (T.allergie || '') + ' ' + l.all)); }
      if (l.temps === 2) { texte.appendChild(el('span', 'ligne__temps', T.aLancer || '')); }
      if (l.comp) { texte.appendChild(el('span', 'ligne__precision', l.comp.map(function (c) { return c.nom + ' ×' + c.qte; }).join(', '))); }
      if (l.inv) { texte.appendChild(el('span', 'ligne__precision', l.invNom)); }
      if (options) { texte.addEventListener('click', function () { options(index); }); }
      li.appendChild(texte);

      var pas = el('div', 'pas');
      var moins = bouton('pas__bouton', '−', T.retirer + ' : ' + nom);
      moins.addEventListener('click', function () { modifier(index, -1); });
      var plus = bouton('pas__bouton', '+', T.ajouter + ' : ' + nom);
      plus.addEventListener('click', function () { modifier(index, 1); });
      var qte = el('span', 'pas__qte', String(l.qte));
      qte.setAttribute('aria-label', T.quantite + ' ' + l.qte);
      pas.append(moins, qte, plus);
      li.appendChild(pas);

      li.appendChild(el('span', 'ligne__montant', pu === null ? '—' : formater(pu * l.qte, D.principale)));
      elLignes.appendChild(li);
    });

    var vide = ticket.length === 0;
    var note = noteActive();
    // Rien à payer : ni nouvelle ligne, ni reste sur la note choisie
    var videTout = vide && (!note || note.vide());
    if (note) { nbArticles += note.articles(); }
    elVide.hidden = !videTout;
    elVider.disabled = vide;
    var total = totalAffiche(D.principale);
    elTotal.textContent = videTout ? formater(0, D.principale) : (total === null ? '—' : formater(total, D.principale));

    var autres = D.devises.filter(function (d) { return d.code !== D.principale; }).map(function (d) {
      var t = totalAffiche(d.code);
      return t === null ? null : formater(videTout ? 0 : t, d.code);
    }).filter(Boolean);
    elAutres.textContent = autres.join('  ·  ');

    // Résumé en bas de l'écran sur téléphone
    elResume.hidden = videTout && !note;
    document.getElementById('resume-texte').textContent =
      nbArticles + ' ' + (nbArticles > 1 ? T.articles : T.article) + ' · ' + T.voirTicket;
    document.getElementById('resume-total').textContent = elTotal.textContent;
    document.getElementById('encaisser-total').textContent = videTout ? '' : elTotal.textContent;
    // Sessions de caisse demandées par l'établissement : rien à encaisser avant d'ouvrir sa caisse
    var peutEncaisser = !videTout && D.encaisser && (!D.sessions || !!D.session);
    document.getElementById('encaisser').disabled = !peutEncaisser;
    document.querySelectorAll('.paiements [data-compte], .paiements [data-ardoise]').forEach(function (b) { b.disabled = !peutEncaisser; });
    // Salle : lignes déjà envoyées de la note, boutons Envoyer, Addition, Partager, Lancer la suite
    if (window.Caisse && window.Caisse.note && window.Caisse.note.dessiner) { window.Caisse.note.dessiner(); }
  }

  elVider.addEventListener('click', function () {
    var produits = ticket.map(function (l) { return formats[l.id].produit; });
    ticket = [];
    sauver();
    dessinerTicket();
    produits.forEach(majTuile);
  });

  // Ticket en panneau sur téléphone
  elResume.addEventListener('click', function () { racine.classList.add('ticket-ouvert'); });
  document.getElementById('fermer-ticket').addEventListener('click', function () { racine.classList.remove('ticket-ouvert'); });

  // ---------- Grille ----------

  var elGrille = document.getElementById('grille');
  var elOnglets = document.getElementById('onglets');
  var elSous = document.getElementById('sous-onglets');
  var elAucun = document.getElementById('aucun');
  var elRecherche = document.getElementById('recherche');
  var categorieActive = 0;  // 0 = tout
  var sousActive = 0;       // 0 = toutes les sous-catégories de la catégorie choisie
  var tuiles = {};          // id produit → élément

  function dessinerOnglets() {
    var liste = [{ id: 0, nom: T.tout }].concat(D.categories);
    liste.forEach(function (c) {
      var b = bouton('onglet', c.nom);
      if (c.id) { b.style.setProperty('--cat', couleurs[c.id]); }
      b.setAttribute('aria-pressed', String(c.id === categorieActive));
      b.addEventListener('click', function () {
        categorieActive = c.id;
        sousActive = 0;
        elOnglets.querySelectorAll('.onglet').forEach(function (o) { o.setAttribute('aria-pressed', 'false'); });
        b.setAttribute('aria-pressed', 'true');
        dessinerSousOnglets();
        filtrer();
      });
      elOnglets.appendChild(b);
    });
  }

  // Seconde rangée : sous-catégories de la catégorie choisie (« Toutes », « Locales », « Importées »)
  function dessinerSousOnglets() {
    elSous.textContent = '';
    var categorie = categoriesParId[categorieActive];
    if (!categorie || !categorie.sous || !categorie.sous.length) { elSous.hidden = true; return; }
    elSous.setAttribute('aria-label', categorie.nom);
    [{ id: 0, nom: T.toutes }].concat(categorie.sous).forEach(function (s) {
      var b = bouton('sous-onglet', s.nom);
      b.style.setProperty('--cat', couleurs[categorie.id]);
      b.setAttribute('aria-pressed', String(s.id === sousActive));
      b.addEventListener('click', function () {
        sousActive = s.id;
        elSous.querySelectorAll('.sous-onglet').forEach(function (o) { o.setAttribute('aria-pressed', 'false'); });
        b.setAttribute('aria-pressed', 'true');
        filtrer();
      });
      elSous.appendChild(b);
    });
    elSous.hidden = false;
  }

  function texteFormat(p) {
    if (p.tailles > 1) { return p.tailles + ' ' + T.tailles; }
    if (p.formats.length > 1) { return p.formats.length + ' ' + T.formats; }
    return p.formats[0].unite ? (p.formats[0].taille || '') : p.formats[0].nom;
  }
  function textePrix(p) {
    var montants = p.formats.map(function (f) { return prix(f, D.principale); }).filter(function (m) { return m !== null; });
    if (!montants.length) { return '—'; }
    return formater(Math.min.apply(null, montants), D.principale) + (p.formats.length > 1 ? '+' : '');
  }

  function dessinerGrille() {
    D.produits.forEach(function (p) {
      var t = document.createElement('button');
      t.type = 'button';
      t.className = 'tuile';
      t.style.setProperty('--cat', couleurs[p.categorie]);
      if (!p.disponible) { t.disabled = true; t.classList.add('tuile--epuise'); }
      if (p.photo) {
        var img = document.createElement('img');
        img.className = 'tuile__photo'; img.src = p.photo; img.alt = ''; img.loading = 'lazy';
        t.appendChild(img);
      }
      var tete = el('span', 'tuile__tete');
      tete.append(el('span', 'tuile__nom', p.nom), el('span', 'tuile__qte'));
      t.appendChild(tete);
      var bas = el('span', 'tuile__bas');
      bas.appendChild(el('span', 'tuile__format', p.disponible ? texteFormat(p) : T.epuise));
      bas.appendChild(el('span', 'tuile__prix', textePrix(p)));
      t.appendChild(bas);
      t.addEventListener('click', function () { choisir(p); });
      tuiles[p.id] = t;
      elGrille.appendChild(t);
      majTuile(p);
    });
  }

  function majTuile(p) {
    var t = tuiles[p.id];
    if (!t) { return; }
    var q = quantiteProduit(p);
    var badge = t.querySelector('.tuile__qte');
    badge.textContent = q ? String(q) : '';
    t.classList.toggle('tuile--choisie', q > 0);
    t.setAttribute('aria-label', p.nom + ', ' + textePrix(p) + (q ? ', ' + T.quantite + ' ' + q : ''));
  }

  function filtrer() {
    var termes = normaliser(elRecherche.value.trim()).split(/\s+/).filter(Boolean);
    var visibles = 0;
    D.produits.forEach(function (p) {
      var ok = !p.cache && (categorieActive === 0 || p.categorie === categorieActive) &&
        (sousActive === 0 || p.sous === sousActive) &&
        termes.every(function (m) { return p.rechercheNormalisee.indexOf(m) !== -1; });
      tuiles[p.id].hidden = !ok;
      if (ok) { visibles++; }
    });
    elAucun.hidden = visibles !== 0;
    return visibles;
  }

  // ---------- Choix du format (et de la taille) ----------

  var dialogue = document.getElementById('formats');
  function choisir(p) {
    // Nuit (caisse-nuit.js) : une formule ouvre le choix de ses composants, le vestiaire demande une description
    if (window.Caisse.avantChoix && window.Caisse.avantChoix(p) === true) { return; }
    var vendables = p.formats.filter(function (f) { return prix(f, D.principale) !== null; });
    if (!vendables.length) { return; }
    if (vendables.length === 1 && vendables[0].disponible) {
      ajouter(vendables[0].id);
      pulser(tuiles[p.id]);
      return;
    }
    document.getElementById('formats-titre').textContent = p.nom;
    var liste = document.getElementById('formats-liste');
    liste.textContent = '';
    vendables.forEach(function (f) {
      var b = bouton('format');
      b.style.setProperty('--cat', couleurs[p.categorie]);
      // « Petite 33 cl · Bouteille » : une ligne par taille et par format vendu
      var nom = f.taille ? f.taille + ' · ' + f.nom : f.nom;
      b.append(el('span', 'format__nom', nom), el('span', 'format__prix', f.disponible ? formater(prix(f, D.principale), D.principale) : T.epuise));
      if (!f.disponible) { b.disabled = true; b.classList.add('format--epuise'); }
      b.addEventListener('click', function (ev) {
        ev.preventDefault();
        ajouter(f.id);
        dialogue.close();
        pulser(tuiles[p.id]);
      });
      liste.appendChild(b);
    });
    dialogue.showModal();
  }
  dialogue.addEventListener('click', function (ev) { if (ev.target === dialogue) { dialogue.close(); } });

  // ---------- Recherche et clavier ----------

  elRecherche.addEventListener('input', filtrer);
  elRecherche.addEventListener('keydown', function (ev) {
    // Entrée avec un seul résultat : ajout direct (utile avec un lecteur de codes-barres plus tard)
    if (ev.key === 'Enter') {
      var seuls = D.produits.filter(function (p) { return !tuiles[p.id].hidden && p.disponible; });
      // Sans preventDefault, la touche Entrée activerait aussitôt le premier choix de la fenêtre qui s'ouvre
      if (seuls.length === 1) { ev.preventDefault(); choisir(seuls[0]); elRecherche.select(); }
    }
    if (ev.key === 'Escape') { elRecherche.value = ''; filtrer(); }
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === '/' && document.activeElement !== elRecherche && !dialogue.open && !document.querySelector('dialog[open]')) {
      ev.preventDefault();
      elRecherche.focus();
    }
  });

  // ---------- Outils ----------

  function el(balise, classe, texte) {
    var e = document.createElement(balise);
    if (classe) { e.className = classe; }
    if (texte !== undefined) { e.textContent = texte; }
    return e;
  }
  // Outils partagés avec caisse-ardoise.js (ardoise, remboursement, garanties) et caisse-salle.js (notes ouvertes)
  window.Caisse = {
    D: D, T: T, devises: devises, TYPES: ['caisse', 'mobile_money', 'banque'],
    formater: function (m, c) { return formater(m, c); }, el: function (b, c, t) { return el(b, c, t); },
    bouton: function (c, t, l) { return bouton(c, t, l); }, cle: function () { return cle(); },
    comptesPour: function (c, t) { return comptesPour(c, t); }, imprimer: function (html) { imprimer(html); },
    // Ticket local (lignes pas encore envoyées) : lecture, changement d'une ligne, vidage, redessin
    lignes: function () { return ticket; },
    formatDe: function (id) { return formats[id] || null; },
    nomLigne: function (id) { return formats[id] ? nomLigne(formats[id]) : ''; },
    couleur: function (id) { return formats[id] ? couleurs[formats[id].produit.categorie] : ''; },
    changerLigne: function (index, options) {
      var l = ticket[index];
      if (!l) { return; }
      ['rem', 'all'].forEach(function (k) { if (options[k]) { l[k] = String(options[k]).slice(0, 160); } else { delete l[k]; } });
      if (options.temps === 2) { l.temps = 2; } else { delete l.temps; }
      changerTicket(l.id);
    },
    viderLocal: function () {
      var produits = ticket.map(function (l) { return formats[l.id].produit; });
      ticket = [];
      sauver();
      dessinerTicket();
      produits.forEach(majTuile);
    },
    redessiner: function () { dessinerTicket(); },
    // Ajoute une ligne à part (formule avec ses composants, entrée d'un invité, dépôt décrit)
    ajouterLigne: function (ligne) {
      if (!formats[ligne.id]) { return; }
      ticket.push(ligne);
      changerTicket(ligne.id);
      pulser(tuiles[formats[ligne.id].produit.id]);
    },
    produitDe: function (id) { return D.produits.filter(function (p) { return p.id === id; })[0] || null; },
    paiement: function () { return paiement; },
    reglement: function () { return reglement(); },
    redessinerPaiement: function () { if (paiement) { remplirAutomatiquement(); dessinerPaiement(); } },
    totalEn: function (code) { return totalEn(code); },
    ouvrirPaiement: function (compteId, ardoise, source) { ouvrirPaiement(compteId, ardoise, source); },
    disponibilites: function (indisponibles, epuises) { disponibilites(indisponibles, epuises); },
    fenetreOuverte: function () { return !!document.querySelector('dialog[open]'); }
  };
  // Rupture décidée en cuisine (ou « épuisé » dans le catalogue), stock épuisé au comptoir (mode « bloquer ») :
  // la tuile se grise tout de suite, sans recharger. indisponibles : produits (et tailles) en rupture ;
  // epuises : formats que le comptoir ne peut plus fournir ; null = inchangé.
  // Au chargement, la rupture vient des données de la page (un format épuisé par le stock n'est pas une rupture).
  D.produits.forEach(function (p) {
    p.formats.forEach(function (f) { f.hors = !f.disponible && !f.epuise; });
    p.hors = !p.disponible && !(p.formats.length && p.formats.every(function (f) { return f.epuise; }));
  });
  function disponibilites(indisponibles, epuises) {
    var hors = null;
    if (indisponibles) { hors = {}; indisponibles.forEach(function (id) { hors[id] = true; }); }
    var ep = null;
    if (epuises) { ep = {}; epuises.forEach(function (id) { ep[id] = true; }); }
    D.produits.forEach(function (p) {
      var avant = p.disponible + ':' + p.formats.map(function (f) { return f.disponible; }).join(',');
      if (hors) { p.hors = !!hors[p.id]; }
      p.formats.forEach(function (f) {
        if (hors) { f.hors = !!(hors[p.id] || hors[f.tailleId]); }
        if (ep) { f.epuise = !!ep[f.id]; }
        f.disponible = !f.hors && !f.epuise;
      });
      // Tous les formats épuisés par le stock : la tuile se grise comme un produit en rupture
      p.disponible = !p.hors && !(p.formats.length && p.formats.every(function (f) { return f.epuise; }));
      if (avant === p.disponible + ':' + p.formats.map(function (f) { return f.disponible; }).join(',')) { return; }
      var t = tuiles[p.id];
      if (!t) { return; }
      t.disabled = !p.disponible;
      t.classList.toggle('tuile--epuise', !p.disponible);
      var bas = t.querySelector('.tuile__format');
      if (bas) { bas.textContent = p.disponible ? texteFormat(p) : T.epuise; }
    });
  }
  function imprimer(html) {
    document.getElementById('zone-impression').innerHTML = html;
    window.print();
  }
  function bouton(classe, texte, libelle) {
    var b = el('button', classe, texte);
    b.type = 'button';
    if (libelle) { b.setAttribute('aria-label', libelle); }
    return b;
  }
  function normaliser(texte) {
    return texte.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  }
  function pulser(t) {
    if (!t) { return; }
    t.classList.remove('tuile--pulse');
    void t.offsetWidth;
    t.classList.add('tuile--pulse');
  }

  // Quitter la caisse : une icône discrète, confirmée dans une fenêtre (aucun menu sur la caisse)
  // ---------- Encaissement ----------
  // Payer en FC, en $ ou les deux ; pour chaque devise, le compte qui reçoit l'argent (Caisse du bar, M-Pesa, Rawbank…),
  // rangé sous son type. Un compte peut tenir plusieurs devises.
  // Même calcul que le serveur (app/Core/Reglement.php) : chaque paiement couvre une part du ticket dans sa devise.
  var fenetre = document.getElementById('paiement');
  var boutonEncaisser = document.getElementById('encaisser');
  // credit : part mise à l'ardoise d'un client (caisse-ardoise.js dessine son bloc), null sinon
  var paiement = null; // { cle, totaux, lignes: [{devise, compte, recu, saisi}], credit: {client, devise, montant, saisi, servi, garant, echeance, forcer}, rendu }

  function pasDe(code) {
    var d = devises[code];
    return d.arrondi > 0 ? d.arrondi : Math.pow(10, -d.decimales);
  }
  function versLeHaut(m, code) { var p = pasDe(code); return +(Math.ceil(+(m / p).toFixed(6)) * p).toFixed(devises[code].decimales); }
  function versLeBas(m, code) { var p = pasDe(code); return +(Math.floor(+(m / p).toFixed(6)) * p).toFixed(devises[code].decimales); }
  // L'ardoise compte comme un paiement dans sa devise ; sansArdoise : seulement ce qui est payé maintenant
  function reglement(sansArdoise) {
    var part = 0;
    var parts = paiement.lignes.slice();
    if (paiement.credit && !sansArdoise) { parts.push({ devise: paiement.credit.devise, recu: paiement.credit.montant }); }
    // Cartes cashless (caisse-nuit.js) : chacune couvre sa part, dans sa devise
    if (paiement.nuit && paiement.nuit.cartes) { paiement.nuit.cartes.forEach(function (c) { parts.push({ devise: c.devise, recu: c.montant }); }); }
    parts.forEach(function (l) {
      var t = paiement.totaux[l.devise];
      if (t > 0 && l.recu > 0) { part += l.recu / t; }
    });
    var principale = D.principale;
    var regle = (1 - part) * paiement.totaux[principale] < pasDe(principale) / 2 - 1e-9;
    var reste = {}, rendu = {};
    Object.keys(paiement.totaux).forEach(function (code) {
      var t = paiement.totaux[code];
      reste[code] = regle ? 0 : versLeHaut(Math.max(0, (1 - part) * t), code);
      rendu[code] = regle && part > 1 ? versLeBas((part - 1) * t, code) : 0;
    });
    if (!(rendu[principale] > 0)) { Object.keys(rendu).forEach(function (c) { rendu[c] = 0; }); }
    return { part: part, regle: regle, reste: reste, rendu: rendu };
  }
  var TYPES = ['caisse', 'mobile_money', 'banque'];
  // Comptes qui tiennent cette devise (déjà rangés par type, ceux de l'établissement d'abord)
  function comptesPour(code, type) {
    return (D.comptes || []).filter(function (c) { return c.devises.indexOf(code) !== -1 && (!type || c.type === type); });
  }
  function compteDe(id) {
    return (D.comptes || []).filter(function (c) { return c.id === id; })[0] || null;
  }
  // Devises où tout le ticket a un prix (et au moins un compte pour encaisser)
  function devisesPayables() {
    return D.devises.map(function (d) { return d.code; }).filter(function (code) {
      return totalEn(code) !== null && comptesPour(code).length > 0;
    });
  }
  // Compte d'une nouvelle ligne : celui choisi s'il tient la devise, sinon un compte du même type, sinon le premier
  function nouvelleLigne(code, compteId) {
    var choisi = compteDe(compteId);
    var compte = choisi && choisi.devises.indexOf(code) !== -1 ? choisi
      : (choisi && comptesPour(code, choisi.type)[0]) || comptesPour(code)[0];
    return { devise: code, compte: compte.id, recu: 0, saisi: false };
  }
  function cle() {
    if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16);
    });
  }
  function choisirDevises(codes) {
    var anciennes = paiement.lignes;
    paiement.lignes = codes.map(function (code) {
      return anciennes.filter(function (l) { return l.devise === code; })[0] || nouvelleLigne(code, anciennes[0] && anciennes[0].compte);
    });
    remplirAutomatiquement();
    dessinerPaiement();
  }
  // Une seule devise : le montant exact par défaut ; deux devises : la dernière complète le reste ;
  // avec une ardoise, c'est elle qui prend le reste (tant que son montant n'a pas été tapé)
  function remplirAutomatiquement() {
    var lignes = paiement.lignes;
    var credit = paiement.credit;
    if (credit) {
      if (!credit.saisi) {
        credit.montant = 0;
        var r = reglement();
        credit.montant = r.regle ? 0 : r.reste[credit.devise];
      }
      return;
    }
    if (lignes.length === 1 && !lignes[0].saisi) {
      if (paiement.nuit && paiement.nuit.cartes && paiement.nuit.cartes.length) {
        // Une carte paie une part : le compte prend le reste
        lignes[0].recu = 0;
        lignes[0].recu = reglement().reste[lignes[0].devise];
      } else {
        lignes[0].recu = paiement.totaux[lignes[0].devise];
      }
      return;
    }
    if (lignes.length > 1) {
      var derniere = lignes[lignes.length - 1];
      if (derniere.saisi) { return; }
      derniere.recu = 0;
      derniere.recu = reglement().reste[derniere.devise];
    }
  }
  function lireMontant(texte, code) {
    var n = parseFloat(String(texte).replace(/[\s  ]/g, '').replace(',', '.'));
    return isFinite(n) && n > 0 ? +n.toFixed(devises[code].decimales) : 0;
  }
  function montantSaisie(m, code) {
    if (!m) { return ''; }
    var s = String(+m.toFixed(devises[code].decimales));
    return D.langue === 'fr' ? s.replace('.', ',') : s;
  }
  // Montants rapides en espèces : exact, puis les billets ronds au-dessus
  function montantsRapides(code, du) {
    var pas = code === 'USD' ? [5, 10, 20, 50, 100] : (devises[code].arrondi >= 100 ? [5000, 10000, 20000, 50000] : [1, 5, 10, 50]);
    var choix = [du];
    pas.forEach(function (p) {
      var v = Math.ceil(du / p) * p;
      if (v > du && choix.indexOf(v) === -1 && choix.length < 4) { choix.push(v); }
    });
    return choix;
  }

  // source (facultative) : payer une note ouverte, ou une part de note (caisse-salle.js) :
  // { note: {id, mode, lignes?, parts?}, version, totaux: {code: montant}, servi: id, titre }
  function ouvrirPaiement(compteId, ardoise, source) {
    if (!D.encaisser || (!source && ticket.length === 0)) { return; }
    var payables = source
      ? Object.keys(source.totaux).filter(function (code) { return devises[code] && typeof source.totaux[code] === 'number' && source.totaux[code] > 0 && comptesPour(code).length > 0; })
      : devisesPayables();
    if (payables.length === 0) { return; }
    var totaux = {};
    payables.forEach(function (code) { totaux[code] = source ? source.totaux[code] : totalEn(code); });
    var depart = payables.indexOf(D.principale) !== -1 ? D.principale : payables[0];
    // Même ticket (ou même part de la même note) qu'un essai précédent : même clé, jamais deux ventes
    var empreinte = source ? JSON.stringify(source.note) + '@' + source.version : JSON.stringify(ticket);
    paiement = { cle: paiement && paiement.cleTicket === empreinte ? paiement.cle : cle(), cleTicket: empreinte, totaux: totaux, lignes: [], credit: null, rendu: D.principale, envoi: false, source: source || null };
    var titre = document.getElementById('paiement-titre');
    if (!titre.dataset.texte) { titre.dataset.texte = titre.textContent; }
    titre.textContent = titre.dataset.texte + (source && source.titre ? ' · ' + source.titre : '');
    var choisi = compteDe(compteId);
    // Un compte choisi sur le panneau qui ne tient pas la devise du ticket : commencer dans une devise qu'il tient
    if (choisi && choisi.devises.indexOf(depart) === -1) {
      depart = payables.filter(function (c) { return choisi.devises.indexOf(c) !== -1; })[0] || depart;
    }
    // Nuit : remises, minimum, fidélité et cartes cashless ; les totaux deviennent ceux après remises
    paiement.nuit = window.Caisse.nuit && window.Caisse.nuit.preparer ? window.Caisse.nuit.preparer(paiement, source || null) : null;
    if (paiement.nuit) { paiement.totaux = window.Caisse.nuit.totaux(paiement); }
    paiement.lignes = ardoise ? [] : [nouvelleLigne(depart, compteId)];
    if (ardoise) { ajouterArdoise(); }
    remplirAutomatiquement();
    document.getElementById('paiement-corps').hidden = false;
    document.getElementById('paiement-fin').hidden = true;
    document.getElementById('paiement-erreur').hidden = true;
    dessinerChoixDevises();
    dessinerPaiement();
    racine.classList.remove('ticket-ouvert');
    fenetre.showModal();
  }

  function dessinerChoixDevises() {
    var zone = document.getElementById('paiement-devises');
    zone.textContent = '';
    var payables = Object.keys(paiement.totaux);
    var options = payables.map(function (code) { return { codes: [code], texte: devises[code].symbole }; });
    // « Les deux » : la devise locale et le dollar ensemble
    if (payables.indexOf(D.principale) !== -1 && payables.indexOf('USD') !== -1 && D.principale !== 'USD') {
      options.push({ codes: ['USD', D.principale], texte: T.lesDeux });
    }
    options.forEach(function (o) {
      var b = el('button', '', o.texte);
      b.type = 'button';
      b.dataset.devises = o.codes.join(',');
      b.addEventListener('click', function () { choisirDevises(o.codes); });
      zone.appendChild(b);
    });
  }

  // Ardoise ajoutée : les montants proposés d'office repartent de zéro, l'ardoise prend tout le reste
  function ajouterArdoise() {
    paiement.lignes.forEach(function (l) { if (!l.saisi) { l.recu = 0; l.saisi = true; } });
    // Une note : « servi par » proposé = le serveur qui l'a ouverte (s'il fait partie du personnel)
    var servi = paiement.source && paiement.source.servi && (D.personnel || []).some(function (p) { return p.id === paiement.source.servi; }) ? paiement.source.servi : D.moi;
    paiement.credit = {
      client: null, devise: paiement.totaux[D.principale] !== undefined ? D.principale : Object.keys(paiement.totaux)[0],
      montant: 0, saisi: false, servi: servi, garant: null, echeance: dateDans(D.delai), forcer: false, peutForcer: false
    };
  }
  function retirerArdoise() {
    paiement.credit = null;
    if (paiement.lignes.length === 0) { paiement.lignes = [nouvelleLigne(Object.keys(paiement.totaux)[0], null)]; }
    paiement.lignes.forEach(function (l) { if (!l.recu) { l.saisi = false; } });
    remplirAutomatiquement();
    dessinerPaiement();
  }
  function dateDans(jours) {
    var d = new Date(D.aujourdhui + 'T12:00:00');
    d.setDate(d.getDate() + (jours || 0));
    return d.toISOString().slice(0, 10);
  }
  // Après un changement dans l'ardoise : recalcul du reste, sans redessiner le champ en cours de saisie
  function ardoiseChangee(redessiner) {
    remplirAutomatiquement();
    if (redessiner) { dessinerPaiement(); } else { dessinerBilan(); }
  }

  function afficherTotaux() {
    var totaux = paiement.totaux;
    var code = totaux[D.principale] !== undefined ? D.principale : Object.keys(totaux)[0];
    document.getElementById('paiement-total').textContent = formater(totaux[code] || 0, code);
    document.getElementById('paiement-autres').textContent = Object.keys(totaux).filter(function (c) { return c !== code; })
      .map(function (c) { return formater(totaux[c], c); }).join('  ·  ');
  }
  function dessinerPaiement() {
    afficherTotaux();
    var zoneNuit = document.getElementById('paiement-nuit');
    if (zoneNuit) {
      zoneNuit.textContent = '';
      if (paiement.nuit && window.Caisse.nuit.dessinerPaiement) { window.Caisse.nuit.dessinerPaiement(paiement, zoneNuit); }
    }
    var actives = paiement.lignes.map(function (l) { return l.devise; }).join(',');
    document.querySelectorAll('#paiement-devises button').forEach(function (b) {
      b.setAttribute('aria-pressed', b.dataset.devises === actives ? 'true' : 'false');
    });
    var zone = document.getElementById('paiement-lignes');
    zone.textContent = '';
    paiement.lignes.forEach(function (ligne, i) {
      var bloc = el('div', 'paiement-ligne');
      var tete = el('div', 'paiement-ligne__tete');
      tete.appendChild(el('span', 'paiement-ligne__devise', devises[ligne.devise].symbole));
      var champ = document.createElement('input');
      champ.className = 'paiement-ligne__montant';
      champ.inputMode = 'decimal';
      champ.autocomplete = 'off';
      champ.id = 'recu-' + ligne.devise;
      champ.value = montantSaisie(ligne.recu, ligne.devise);
      champ.setAttribute('aria-label', T.recu + ' (' + ligne.devise + ')');
      champ.addEventListener('input', function () {
        ligne.recu = lireMontant(champ.value, ligne.devise);
        ligne.saisi = true;
        if (paiement.credit) {
          remplirAutomatiquement();
          var champArdoise = document.getElementById('ardoise-montant');
          if (champArdoise && !paiement.credit.saisi) { champArdoise.value = montantSaisie(paiement.credit.montant, paiement.credit.devise); }
        } else if (paiement.lignes.length > 1 && i < paiement.lignes.length - 1) {
          paiement.lignes[paiement.lignes.length - 1].saisi = false;
          remplirAutomatiquement();
          var dernier = document.getElementById('recu-' + paiement.lignes[paiement.lignes.length - 1].devise);
          if (dernier) { dernier.value = montantSaisie(paiement.lignes[paiement.lignes.length - 1].recu, paiement.lignes[paiement.lignes.length - 1].devise); }
        }
        dessinerBilan();
      });
      tete.appendChild(champ);
      tete.appendChild(el('span', 'paiement-ligne__libelle', T.recu));
      bloc.appendChild(tete);

      // Comptes qui tiennent cette devise, sous leur type : on touche directement le compte
      var comptes = el('div', 'paiement-ligne__comptes');
      comptes.setAttribute('role', 'group');
      comptes.setAttribute('aria-label', T.compte + ' (' + ligne.devise + ')');
      TYPES.forEach(function (type) {
        var duType = comptesPour(ligne.devise, type);
        if (duType.length === 0) { return; }
        var groupe = el('div', 'paiement-type');
        groupe.appendChild(el('span', 'paiement-type__nom', T.types[type]));
        var puces = el('div', 'paiement-type__comptes');
        duType.forEach(function (c) {
          var b = el('button', 'puce', c.nom);
          b.type = 'button';
          b.dataset.compte = c.id;
          b.setAttribute('aria-pressed', c.id === ligne.compte ? 'true' : 'false');
          b.addEventListener('click', function () { ligne.compte = c.id; dessinerPaiement(); });
          puces.appendChild(b);
        });
        groupe.appendChild(puces);
        comptes.appendChild(groupe);
      });
      bloc.appendChild(comptes);

      if ((compteDe(ligne.compte) || {}).type === 'caisse') {
        var rapides = el('div', 'paiement-ligne__rapides');
        var du = paiement.lignes.length === 1 && !paiement.credit ? paiement.totaux[ligne.devise] : (function () {
          var r = ligne.recu; ligne.recu = 0;
          var reste = reglement(paiement.credit && !paiement.credit.saisi).reste[ligne.devise];
          ligne.recu = r; return reste;
        })();
        if (du > 0) {
          montantsRapides(ligne.devise, du).forEach(function (v, k) {
            var b = el('button', 'paiement-rapide', k === 0 ? T.montantExact : formater(v, ligne.devise));
            b.type = 'button';
            b.addEventListener('click', function () {
              ligne.recu = v; ligne.saisi = true;
              champ.value = montantSaisie(v, ligne.devise);
              champ.dispatchEvent(new Event('input'));
            });
            rapides.appendChild(b);
          });
        }
        bloc.appendChild(rapides);
      }
      zone.appendChild(bloc);
    });
    // Ardoise : bloc du client à crédit, ou le bouton pour en ajouter une
    if (paiement.credit && window.Caisse.ardoise) {
      zone.appendChild(window.Caisse.ardoise.blocCredit(paiement, { changee: ardoiseChangee, retirer: retirerArdoise, montantSaisie: montantSaisie, lireMontant: lireMontant }));
    } else if (D.credit && window.Caisse.ardoise && !(paiement.source && paiement.source.sansArdoise)) {
      var ajout = bouton('bouton bouton--secondaire paiement-ardoise__ajouter', T.ardoise.mettre);
      ajout.id = 'ajouter-ardoise';
      ajout.addEventListener('click', function () { ajouterArdoise(); remplirAutomatiquement(); dessinerPaiement(); });
      zone.appendChild(ajout);
    }
    dessinerBilan();
  }

  function dessinerBilan() {
    var r = reglement();
    var bilan = document.getElementById('paiement-bilan');
    bilan.textContent = '';
    bilan.className = 'paiement-fenetre__bilan' + (r.regle ? ' paiement-fenetre__bilan--regle' : '');
    var codes = Object.keys(paiement.totaux);
    if (!r.regle) {
      bilan.appendChild(el('span', '', T.resteAPayer));
      bilan.appendChild(el('strong', '', codes.map(function (c) { return formater(r.reste[c], c); }).join('  ·  ')));
    } else {
      var aRendre = codes.some(function (c) { return r.rendu[c] > 0; });
      bilan.appendChild(el('span', '', aRendre ? T.aRendre : T.regle));
      if (aRendre) {
        var code = r.rendu[paiement.rendu] > 0 ? paiement.rendu : codes.filter(function (c) { return r.rendu[c] > 0; })[0];
        paiement.rendu = code;
        bilan.appendChild(el('strong', '', formater(r.rendu[code], code)));
      }
    }
    // Monnaie : dans une devise que tient une caisse de l'établissement
    var zoneRendu = document.getElementById('paiement-rendu');
    var possibles = codes.filter(function (c) { return r.rendu[c] > 0 && comptesPour(c, 'caisse').length > 0; });
    zoneRendu.hidden = !(r.regle && possibles.length > 1);
    var choix = document.getElementById('paiement-rendu-devises');
    choix.textContent = '';
    possibles.forEach(function (c) {
      var b = el('button', '', formater(r.rendu[c], c));
      b.type = 'button';
      b.setAttribute('aria-pressed', c === paiement.rendu ? 'true' : 'false');
      b.addEventListener('click', function () { paiement.rendu = c; dessinerBilan(); });
      choix.appendChild(b);
    });
    // Ardoise : un client choisi, et pas plus que le reste (pas de monnaie sur une ardoise)
    var credit = paiement.credit;
    var aRendreArdoise = credit && codes.some(function (c) { return r.rendu[c] > 0; });
    var sansClient = credit && !credit.client;
    if (credit && (aRendreArdoise || sansClient)) {
      bilan.className = 'paiement-fenetre__bilan';
      bilan.textContent = '';
      bilan.appendChild(el('span', '', aRendreArdoise ? T.ardoise.trop_ardoise : T.ardoise.choisir_client));
      zoneRendu.hidden = true;
    }
    document.getElementById('paiement-valider').disabled = !r.regle || paiement.envoi || !!aRendreArdoise || !!sansClient;
  }

  function erreurPaiement(texte) {
    var p = document.getElementById('paiement-erreur');
    p.textContent = texte;
    p.hidden = false;
  }

  function validerPaiement() {
    if (!paiement || paiement.envoi || !reglement().regle || (paiement.credit && !paiement.credit.client)) { return; }
    paiement.envoi = true;
    var valider = document.getElementById('paiement-valider');
    valider.disabled = true;
    valider.textContent = T.envoi;
    document.getElementById('paiement-erreur').hidden = true;
    var corps = new FormData();
    corps.append('csrf', D.csrf);
    var nuit = paiement.nuit && window.Caisse.nuit ? window.Caisse.nuit.envoi(paiement) : {};
    corps.append('vente', JSON.stringify({
      cle: paiement.cle, etab: D.etab, zone: D.zone, poste: D.poste,
      remises: nuit.remises, cartes: nuit.cartes, fidelite: nuit.fidelite, minimum: nuit.minimum,
      extra: paiement.source ? paiement.source.extra : undefined,
      // Une note : ses lignes viennent du serveur ; sinon le ticket (avec précisions et allergies pour la cuisine)
      note: paiement.source ? paiement.source.note : undefined,
      lignes: paiement.source ? [] : ticket.map(function (l) {
        var ligne = { id: l.id, qte: l.qte };
        if (l.rem) { ligne.rem = l.rem; }
        if (l.all) { ligne.all = l.all; }
        // Formule : ses composants ; entrée réduite : l'invité pointé à la porte
        if (l.comp) { ligne.comp = l.comp.map(function (c) { return { id: c.id, qte: c.qte }; }); }
        if (l.inv) { ligne.invite = l.inv; }
        return ligne;
      }),
      paiements: paiement.lignes.filter(function (l) { return l.recu > 0; }).map(function (l) {
        return { devise: l.devise, compte: l.compte, recu: l.recu };
      }),
      rendu: paiement.rendu,
      credit: paiement.credit ? {
        client: paiement.credit.client.id, devise: paiement.credit.devise, montant: paiement.credit.montant,
        servi: paiement.credit.servi, garant: paiement.credit.garant, echeance: paiement.credit.echeance, forcer: paiement.credit.forcer
      } : null
    }));
    fetch((paiement.source && paiement.source.url) || '/caisse/encaisser', { method: 'POST', body: corps, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok) {
          // Plafond ou retard : le gérant peut forcer (case dans le bloc de l'ardoise)
          // Remise à valider (PIN d'un supérieur), carte refusée, minimum : caisse-nuit.js montre où corriger
          if (paiement.nuit && window.Caisse.nuit.erreurServeur && window.Caisse.nuit.erreurServeur(paiement, res.j)) {
            paiement.envoi = false;
            valider.textContent = valider.dataset.texte;
            dessinerPaiement();
            erreurPaiement(res.j.erreur || T.erreurReseau);
            return;
          }
          if (res.j.code === 'credit_refuse' && res.j.peutForcer && paiement.credit) {
            paiement.credit.peutForcer = true;
            paiement.envoi = false;
            valider.textContent = valider.dataset.texte;
            dessinerPaiement();
            erreurPaiement(res.j.erreur);
            return;
          }
          throw new Error(res.j.erreur || T.erreurReseau);
        }
        venteTerminee(res.j);
      })
      .catch(function (e) {
        paiement.envoi = false;
        valider.textContent = document.getElementById('paiement-valider').dataset.texte;
        erreurPaiement(e.message || T.erreurReseau);
        dessinerBilan();
      });
  }

  function venteTerminee(reponse) {
    if (reponse.csrf) { D.csrf = reponse.csrf; }
    // Le stock a baissé : ce qui vient de s'épuiser au comptoir se grise (mode « bloquer »)
    if (reponse.epuises) { disponibilites(null, reponse.epuises); }
    if (paiement.source && paiement.source.apres) {
      // Recharge cashless (ou autre opération sans le ticket) : le ticket local n'a pas bougé
      paiement.source.apres(reponse);
    } else if (paiement.source) {
      // Note payée (en entier ou une part) : le ticket local n'a pas bougé, la note se met à jour
      if (window.Caisse.note && window.Caisse.note.payee) { window.Caisse.note.payee(reponse, paiement.source); }
    } else {
      var produits = ticket.map(function (l) { return formats[l.id].produit; });
      ticket = [];
      sauver();
      dessinerTicket();
      produits.forEach(majTuile);
    }
    document.getElementById('paiement-corps').hidden = true;
    document.getElementById('paiement-fin').hidden = false;
    document.getElementById('paiement-recu').innerHTML = reponse.recu;
    // Codes QR des billets, tickets de vestiaire et cartes (caisse-nuit.js)
    if (window.Caisse.apresRecu) { window.Caisse.apresRecu(document.getElementById('paiement-recu')); }
    var suivant = document.getElementById('paiement-suivant');
    paiement.verrouille = reponse.verrouille;
    if (reponse.verrouille) {
      suivant.textContent = T.terminer;
      var note = el('p', 'paiement-fenetre__note', T.verrouillee);
      document.getElementById('paiement-recu').appendChild(note);
    }
    suivant.focus();
  }

  function terminer() {
    fenetre.close();
    if (paiement && paiement.verrouille) { window.location.reload(); }
    var source = paiement && paiement.source;
    paiement = null;
    // Addition partagée : la part suivante
    if (source && source.note && window.Caisse.note && window.Caisse.note.apresPaiement) { window.Caisse.note.apresPaiement(source); }
  }

  if (fenetre && boutonEncaisser) {
    var valider = document.getElementById('paiement-valider');
    valider.dataset.texte = valider.textContent;
    // Une note choisie : « Encaisser » paie la note (après avoir envoyé les nouvelles lignes)
    var demarrer = function (compteId, ardoise) {
      var note = noteActive();
      if (note) { note.payer(compteId, ardoise); } else { ouvrirPaiement(compteId, ardoise); }
    };
    boutonEncaisser.addEventListener('click', function () { demarrer(null); });
    document.querySelectorAll('.paiements [data-compte]').forEach(function (b) {
      b.addEventListener('click', function () { demarrer(+b.dataset.compte); });
    });
    var boutonArdoise = document.querySelector('.paiements [data-ardoise]');
    if (boutonArdoise) { boutonArdoise.addEventListener('click', function () { demarrer(null, true); }); }
    valider.addEventListener('click', validerPaiement);
    document.querySelector('[data-fermer-paiement]').addEventListener('click', function () { fenetre.close(); });
    document.getElementById('paiement-suivant').addEventListener('click', terminer);
    fenetre.addEventListener('cancel', function (ev) { if (paiement && paiement.verrouille) { ev.preventDefault(); terminer(); } });
    document.getElementById('paiement-imprimer').addEventListener('click', function () {
      imprimer(document.getElementById('paiement-recu').querySelector('.recu').outerHTML);
    });
  }

  // ---------- Verrouillage automatique (caisse partagée, délai choisi par l'administrateur) ----------
  // Sans toucher l'écran pendant le délai : avertissement 10 s avant, puis retour à « Qui êtes-vous ? ».
  var formVerrou = document.getElementById('form-verrouiller');
  if (D.verrou && D.verrou.minutes > 0 && formVerrou) {
    var delai = D.verrou.minutes * 60000;
    var avertissement = Math.min(10000, delai / 2);
    var minuterie = null, compte = null, bandeau = null;
    var effacerBandeau = function () {
      if (compte) { clearInterval(compte); compte = null; }
      if (bandeau) { bandeau.remove(); bandeau = null; }
    };
    var avertir = function () {
      var reste = Math.round(avertissement / 1000);
      bandeau = document.createElement('div');
      bandeau.className = 'verrou-bientot';
      bandeau.setAttribute('role', 'status');
      var ecrire = function () { bandeau.textContent = T.verrouBientot.replace('{n}', String(reste)); };
      ecrire();
      document.body.appendChild(bandeau);
      compte = setInterval(function () {
        reste--;
        if (reste <= 0) { effacerBandeau(); formVerrou.submit(); return; }
        ecrire();
      }, 1000);
    };
    var relancer = function () {
      effacerBandeau();
      clearTimeout(minuterie);
      minuterie = setTimeout(avertir, delai - avertissement);
    };
    ['pointerdown', 'keydown', 'touchstart', 'wheel'].forEach(function (type) {
      document.addEventListener(type, relancer, { passive: true, capture: true });
    });
    relancer();
  }

  var fenetreQuitter = document.getElementById('fenetre-quitter');
  document.getElementById('quitter').addEventListener('click', function () { fenetreQuitter.showModal(); });
  fenetreQuitter.addEventListener('click', function (ev) { if (ev.target === fenetreQuitter) { fenetreQuitter.close(); } });

  dessinerOnglets();
  dessinerGrille();
  filtrer();
  dessinerTicket();
})();
