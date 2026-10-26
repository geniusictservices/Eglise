// Écran de caisse : grille de produits, ticket en cours, totaux dans chaque devise.
// Les ventes ne sont pas encore enregistrées (phase 3) : le ticket est gardé dans l'onglet du navigateur.
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

  var cleTicket = 'caisse.ticket.' + D.cle;
  var ticket = [];   // [{ id: id du format, qte: n }]
  try {
    // Ticket gardé par un chargement précédent : on ne garde que les lignes dont le format est encore vendu
    // (un format passé à une taille garde son id) ; un contenu illisible vide le ticket.
    var garde = JSON.parse(sessionStorage.getItem(cleTicket));
    ticket = (Array.isArray(garde) ? garde : []).filter(function (l) {
      return l && formats[l.id] && typeof l.qte === 'number' && l.qte > 0 && Math.floor(l.qte) === l.qte;
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
  function ajouter(formatId) {
    var ligne = ticket.find(function (l) { return l.id === formatId; });
    if (ligne) { ligne.qte++; } else { ticket.push({ id: formatId, qte: 1 }); }
    changerTicket(formatId);
  }
  function modifier(formatId, delta) {
    ticket = ticket.map(function (l) { return l.id === formatId ? { id: l.id, qte: l.qte + delta } : l; })
      .filter(function (l) { return l.qte > 0; });
    changerTicket(formatId);
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

  function dessinerTicket() {
    elLignes.textContent = '';
    var nbArticles = 0;
    ticket.forEach(function (l) {
      var f = formats[l.id];
      nbArticles += l.qte;
      var pu = prix(f.format, D.principale);
      var li = el('li', 'ligne');
      li.style.setProperty('--cat', couleurs[f.produit.categorie]);
      var texte = el('div', 'ligne__texte');
      var nom = nomLigne(f);
      texte.appendChild(el('span', 'ligne__nom', nom));
      if (!f.format.unite || f.format.freres > 1) { texte.appendChild(el('span', 'ligne__format', f.format.nom)); }
      li.appendChild(texte);

      var pas = el('div', 'pas');
      var moins = bouton('pas__bouton', '−', T.retirer + ' : ' + nom);
      moins.addEventListener('click', function () { modifier(l.id, -1); });
      var plus = bouton('pas__bouton', '+', T.ajouter + ' : ' + nom);
      plus.addEventListener('click', function () { modifier(l.id, 1); });
      var qte = el('span', 'pas__qte', String(l.qte));
      qte.setAttribute('aria-label', T.quantite + ' ' + l.qte);
      pas.append(moins, qte, plus);
      li.appendChild(pas);

      li.appendChild(el('span', 'ligne__montant', pu === null ? '—' : formater(pu * l.qte, D.principale)));
      elLignes.appendChild(li);
    });

    var vide = ticket.length === 0;
    elVide.hidden = !vide;
    elVider.disabled = vide;
    var total = totalEn(D.principale);
    elTotal.textContent = vide ? formater(0, D.principale) : (total === null ? '—' : formater(total, D.principale));

    var autres = D.devises.filter(function (d) { return d.code !== D.principale; }).map(function (d) {
      var t = totalEn(d.code);
      return t === null ? null : formater(vide ? 0 : t, d.code);
    }).filter(Boolean);
    elAutres.textContent = autres.join('  ·  ');

    // Résumé en bas de l'écran sur téléphone
    elResume.hidden = vide;
    document.getElementById('resume-texte').textContent =
      nbArticles + ' ' + (nbArticles > 1 ? T.articles : T.article) + ' · ' + T.voirTicket;
    document.getElementById('resume-total').textContent = elTotal.textContent;
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
      t.appendChild(el('span', 'tuile__nom', p.nom));
      var bas = el('span', 'tuile__bas');
      bas.appendChild(el('span', 'tuile__format', p.disponible ? texteFormat(p) : T.epuise));
      bas.appendChild(el('span', 'tuile__prix', textePrix(p)));
      t.appendChild(bas);
      t.appendChild(el('span', 'tuile__qte'));
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
      var ok = (categorieActive === 0 || p.categorie === categorieActive) &&
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
    if (ev.key === '/' && document.activeElement !== elRecherche && !dialogue.open) {
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
  var fenetreQuitter = document.getElementById('fenetre-quitter');
  document.getElementById('quitter').addEventListener('click', function () { fenetreQuitter.showModal(); });
  fenetreQuitter.addEventListener('click', function (ev) { if (ev.target === fenetreQuitter) { fenetreQuitter.close(); } });

  dessinerOnglets();
  dessinerGrille();
  filtrer();
  dessinerTicket();
})();
