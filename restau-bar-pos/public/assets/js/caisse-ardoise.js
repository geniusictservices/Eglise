// Ardoises à la caisse (avec caisse.js, qui expose window.Caisse) :
// - le bloc « Ardoise » de la fenêtre d'encaissement : client (recherche ou création rapide), montant,
//   qui a servi (la dette l'engage), garant facultatif (il confirmera plus tard), échéance ;
// - « Ardoises » : un client rembourse à la caisse, sur un compte et dans une devise ;
// - « Garanties à confirmer » : la personne désignée garante accepte ou refuse.
(function () {
  'use strict';

  var C = window.Caisse;
  if (!C) { return; }
  var D = C.D, T = C.T, el = C.el, bouton = C.bouton;
  var TA = T.ardoise, TG = T.garantie, TR = T.rembourser;

  function texte(modele, valeurs) {
    return modele.replace(/\{(\w+)\}/g, function (m, cle) { return valeurs[cle] !== undefined ? valeurs[cle] : m; });
  }
  function envoyer(url, champs) {
    var corps = new FormData();
    corps.append('csrf', D.csrf);
    corps.append('etab', D.etab);
    Object.keys(champs).forEach(function (k) { corps.append(k, champs[k]); });
    return fetch(url, { method: 'POST', body: corps, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { if (j.csrf) { D.csrf = j.csrf; } return { ok: r.ok, j: j }; }); });
  }
  function lire(url) {
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); });
  }

  // ---------- Choix du client : recherche par nom ou téléphone, création rapide ----------

  function choixClient(options) {
    var bloc = el('div', 'ardoise-choix');
    var champ = document.createElement('input');
    champ.type = 'search';
    champ.className = 'ardoise-choix__recherche';
    champ.id = options.id || 'ardoise-recherche';
    champ.placeholder = TA.chercher;
    champ.autocomplete = 'off';
    champ.setAttribute('aria-label', TA.chercher);
    bloc.appendChild(champ);
    var liste = el('div', 'ardoise-choix__liste');
    liste.setAttribute('role', 'list');
    bloc.appendChild(liste);

    var minuterie = null, demande = 0;
    champ.addEventListener('input', function () {
      clearTimeout(minuterie);
      var q = champ.value.trim();
      if (q.length < 2) { liste.textContent = ''; return; }
      minuterie = setTimeout(function () {
        var numero = ++demande;
        lire('/caisse/clients?etab=' + encodeURIComponent(D.etab) + '&q=' + encodeURIComponent(q)).then(function (res) {
          if (numero !== demande) { return; }
          liste.textContent = '';
          var clients = res.ok ? res.j.clients : [];
          if (clients.length === 0) { liste.appendChild(el('p', 'ardoise-choix__vide', TA.aucun)); }
          clients.forEach(function (c) {
            var b = bouton('ardoise-client');
            b.setAttribute('role', 'listitem');
            b.dataset.client = c.id;
            b.appendChild(el('span', 'ardoise-client__nom', c.nom));
            b.appendChild(el('span', 'ardoise-client__detail', [c.telephone, c.encours.length ? texte(TA.doit, { montant: c.encours.join(' · ') }) : TA.rien_du].filter(Boolean).join(' · ')));
            if (c.retard) { b.appendChild(el('span', 'badge badge--erreur', texte(TA.en_retard, { date: c.retard }))); }
            if (c.bloque) { b.appendChild(el('span', 'badge badge--erreur', TA.bloque)); }
            b.addEventListener('click', function () { options.choisi(c); });
            liste.appendChild(b);
          });
        });
      }, 220);
    });

    if (options.creation) {
      var nouveau = el('details', 'ardoise-nouveau');
      var resume = el('summary', 'lien', TA.nouveau);
      nouveau.appendChild(resume);
      var formulaire = el('div', 'ardoise-nouveau__champs');
      var nom = champSaisie('ardoise-nom', TA.nom, 'text', 'name');
      var telephone = champSaisie('ardoise-telephone', TA.telephone, 'tel', 'tel');
      var whatsapp = el('label', 'ardoise-case');
      var caseWhatsapp = document.createElement('input');
      caseWhatsapp.type = 'checkbox';
      caseWhatsapp.id = 'ardoise-whatsapp';
      whatsapp.appendChild(caseWhatsapp);
      whatsapp.appendChild(document.createTextNode(' ' + TA.whatsapp));
      var erreur = el('p', 'paiement-fenetre__erreur');
      erreur.hidden = true;
      var creer = bouton('bouton', TA.creer);
      creer.id = 'ardoise-creer';
      creer.addEventListener('click', function () {
        creer.disabled = true;
        envoyer('/caisse/clients', { nom: nom.champ.value, telephone: telephone.champ.value, canal: caseWhatsapp.checked ? 'whatsapp' : 'sms', langue: D.langue })
          .then(function (res) {
            creer.disabled = false;
            if (!res.ok) { erreur.textContent = res.j.erreur; erreur.hidden = false; return; }
            options.choisi(res.j.client);
          })
          .catch(function () { creer.disabled = false; erreur.textContent = T.erreurReseau; erreur.hidden = false; });
      });
      [nom.bloc, telephone.bloc, whatsapp, erreur, creer].forEach(function (n) { formulaire.appendChild(n); });
      nouveau.appendChild(formulaire);
      nouveau.addEventListener('toggle', function () {
        if (nouveau.open) {
          // Ce qui a été tapé dans la recherche sert de nom ou de numéro
          var q = champ.value.trim();
          if (q && !nom.champ.value && !telephone.champ.value) { (/^[\d\s+]+$/.test(q) ? telephone : nom).champ.value = q; }
          nom.champ.focus();
        }
      });
      bloc.appendChild(nouveau);
    }
    setTimeout(function () { champ.focus(); }, 0);
    return bloc;
  }

  function champSaisie(id, libelle, type, complete) {
    var bloc = el('div', 'ardoise-champ');
    var etiquette = el('label', 'paiement-fenetre__etiquette', libelle);
    etiquette.htmlFor = id;
    var champ = document.createElement('input');
    champ.id = id;
    champ.type = type;
    if (complete) { champ.autocomplete = 'off'; }
    bloc.appendChild(etiquette);
    bloc.appendChild(champ);
    return { bloc: bloc, champ: champ };
  }

  function carteClient(c, changer) {
    var carte = el('div', 'ardoise-carte');
    var tete = el('div', 'ardoise-carte__tete');
    var noms = el('div', 'ardoise-carte__noms');
    noms.appendChild(el('strong', 'ardoise-carte__nom', c.nom));
    noms.appendChild(el('span', 'ardoise-carte__detail', [c.telephone, c.encours.length ? texte(TA.doit, { montant: c.encours.join(' · ') }) : TA.rien_du].filter(Boolean).join(' · ')));
    tete.appendChild(noms);
    if (changer) {
      var b = bouton('lien', TA.changer);
      b.addEventListener('click', changer);
      tete.appendChild(b);
    }
    carte.appendChild(tete);
    if (c.retard || c.bloque) {
      var alertes = el('div', 'ardoise-carte__alertes');
      if (c.retard) { alertes.appendChild(el('span', 'badge badge--erreur', texte(TA.en_retard, { date: c.retard }))); }
      if (c.bloque) { alertes.appendChild(el('span', 'badge badge--erreur', TA.bloque)); }
      carte.appendChild(alertes);
    }
    return carte;
  }

  // ---------- Bloc « Ardoise » de la fenêtre d'encaissement ----------

  function blocCredit(paiement, rappels) {
    var credit = paiement.credit;
    var bloc = el('section', 'paiement-ligne paiement-ardoise');
    bloc.id = 'ardoise';
    bloc.setAttribute('aria-labelledby', 'ardoise-titre');
    var tete = el('div', 'paiement-ardoise__tete');
    var titre = el('h3', 'paiement-ardoise__titre', TA.titre);
    titre.id = 'ardoise-titre';
    tete.appendChild(titre);
    var retirer = bouton('lien', TA.retirer);
    retirer.id = 'retirer-ardoise';
    retirer.addEventListener('click', rappels.retirer);
    tete.appendChild(retirer);
    bloc.appendChild(tete);

    if (!credit.client) {
      bloc.appendChild(choixClient({ creation: true, choisi: function (c) { credit.client = c; rappels.changee(true); } }));
      return bloc;
    }
    bloc.appendChild(carteClient(credit.client, function () { credit.client = null; credit.forcer = false; credit.peutForcer = false; rappels.changee(true); }));

    // Montant à l'ardoise, dans une des devises du ticket
    var ligne = el('div', 'paiement-ligne__tete');
    var codes = Object.keys(paiement.totaux);
    if (codes.length > 1) {
      var segments = el('div', 'segments paiement-ardoise__devises');
      segments.setAttribute('role', 'group');
      codes.forEach(function (code) {
        var b = bouton('', C.devises[code].symbole);
        b.setAttribute('aria-pressed', code === credit.devise ? 'true' : 'false');
        b.addEventListener('click', function () { credit.devise = code; credit.saisi = false; rappels.changee(true); });
        segments.appendChild(b);
      });
      ligne.appendChild(segments);
    } else {
      ligne.appendChild(el('span', 'paiement-ligne__devise', C.devises[credit.devise].symbole));
    }
    var champ = document.createElement('input');
    champ.className = 'paiement-ligne__montant';
    champ.id = 'ardoise-montant';
    champ.inputMode = 'decimal';
    champ.autocomplete = 'off';
    champ.value = rappels.montantSaisie(credit.montant, credit.devise);
    champ.setAttribute('aria-label', TA.montant + ' (' + credit.devise + ')');
    champ.addEventListener('input', function () {
      credit.montant = rappels.lireMontant(champ.value, credit.devise);
      credit.saisi = champ.value.trim() !== '';
      rappels.changee(false);
    });
    ligne.appendChild(champ);
    ligne.appendChild(el('span', 'paiement-ligne__libelle', TA.montant));
    bloc.appendChild(ligne);

    // Qui a servi : la dette l'engage (sauf garant qui accepte)
    var servis = D.personnel.filter(function (p) { return p.vend || p.id === credit.servi; });
    var groupeServi = el('div', 'paiement-type');
    groupeServi.appendChild(el('span', 'paiement-type__nom', TA.servi_par));
    var puces = el('div', 'paiement-type__comptes');
    puces.id = 'ardoise-servi';
    servis.forEach(function (p) {
      var b = bouton('puce', p.nom);
      b.dataset.personne = p.id;
      b.setAttribute('aria-pressed', p.id === credit.servi ? 'true' : 'false');
      b.addEventListener('click', function () {
        credit.servi = p.id;
        if (credit.garant === p.id) { credit.garant = null; }
        rappels.changee(true);
      });
      puces.appendChild(b);
    });
    groupeServi.appendChild(puces);
    bloc.appendChild(groupeServi);
    bloc.appendChild(el('p', 'paiement-ardoise__aide', TA.servi_aide));

    // Garant facultatif, échéance
    var champs = el('div', 'paiement-ardoise__champs');
    var garant = champSaisie('ardoise-garant', TA.garant, 'text');
    var choix = document.createElement('select');
    choix.id = 'ardoise-garant';
    var aucun = document.createElement('option');
    aucun.value = '';
    aucun.textContent = TA.sans_garant;
    choix.appendChild(aucun);
    D.personnel.forEach(function (p) {
      if (p.id === credit.servi) { return; }
      var o = document.createElement('option');
      o.value = p.id;
      o.textContent = p.nom;
      o.selected = p.id === credit.garant;
      choix.appendChild(o);
    });
    choix.addEventListener('change', function () { credit.garant = choix.value ? +choix.value : null; rappels.changee(true); });
    garant.bloc.replaceChild(choix, garant.champ);
    champs.appendChild(garant.bloc);
    var echeance = champSaisie('ardoise-echeance', TA.echeance, 'date');
    echeance.champ.value = credit.echeance;
    echeance.champ.min = D.aujourdhui;
    echeance.champ.addEventListener('change', function () { credit.echeance = echeance.champ.value || credit.echeance; });
    champs.appendChild(echeance.bloc);
    bloc.appendChild(champs);
    if (credit.garant) { bloc.appendChild(el('p', 'paiement-ardoise__aide', TA.garant_aide)); }

    // Plafond ou retard : le gérant peut passer outre (le serveur l'a dit au premier essai)
    if (credit.peutForcer) {
      var forcer = el('label', 'ardoise-case ardoise-case--forcer');
      var caseForcer = document.createElement('input');
      caseForcer.type = 'checkbox';
      caseForcer.id = 'ardoise-forcer';
      caseForcer.checked = credit.forcer;
      caseForcer.addEventListener('change', function () { credit.forcer = caseForcer.checked; });
      forcer.appendChild(caseForcer);
      forcer.appendChild(document.createTextNode(' ' + TA.forcer));
      bloc.appendChild(forcer);
    }
    return bloc;
  }

  // ---------- Garanties à confirmer ----------

  var fenetreGaranties = document.getElementById('fenetre-garanties');
  var boutonGaranties = document.getElementById('garanties');

  function dessinerGaranties() {
    var n = D.garanties.length;
    if (boutonGaranties) {
      boutonGaranties.hidden = n === 0;
      boutonGaranties.querySelector('span').textContent = n === 1 ? TG.une : texte(TG.plusieurs, { n: n });
    }
    if (!fenetreGaranties) { return; }
    var liste = document.getElementById('garanties-liste');
    liste.textContent = '';
    if (n === 0) { liste.appendChild(el('p', 'quitter__texte', TG.aucune)); }
    D.garanties.forEach(function (g) {
      var carte = el('article', 'garantie');
      carte.dataset.dette = g.id;
      carte.appendChild(el('p', 'garantie__texte', texte(TG.texte, { servi: g.servi || '—', client: g.client, montant: g.montant, echeance: g.echeance, etablissement: g.etablissement })));
      var actions = el('div', 'garantie__actions');
      [['refuse', TG.refuser, 'bouton bouton--secondaire'], ['accepte', TG.accepter, 'bouton']].forEach(function (a) {
        var b = bouton(a[2], a[1]);
        b.dataset.reponse = a[0];
        b.addEventListener('click', function () {
          actions.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
          envoyer('/caisse/garanties', { dette: g.id, reponse: a[0] }).then(function (res) {
            D.garanties = res.j.garanties || D.garanties.filter(function (x) { return x.id !== g.id; });
            dessinerGaranties();
          }).catch(function () { actions.querySelectorAll('button').forEach(function (x) { x.disabled = false; }); });
        });
        actions.appendChild(b);
      });
      carte.appendChild(actions);
      liste.appendChild(carte);
    });
  }
  if (fenetreGaranties) {
    dessinerGaranties();
    if (boutonGaranties) { boutonGaranties.addEventListener('click', function () { fenetreGaranties.showModal(); }); }
    // À l'arrivée de la personne : une fois par session de caisse
    var deja = false;
    try { deja = sessionStorage.getItem('caisse.garanties.' + D.cleTicket) === '1'; sessionStorage.setItem('caisse.garanties.' + D.cleTicket, '1'); } catch (e) { /* stockage indisponible */ }
    if (D.garanties.length > 0 && !deja) { fenetreGaranties.showModal(); }
  }

  // ---------- Rembourser une ardoise ----------

  var fenetreR = document.getElementById('fenetre-ardoises');
  var etat = null; // { client, devise, compte, montant, cle, envoi }

  function ouvrirRemboursement() {
    // Sessions de caisse : le remboursement entre dans la session, il faut d'abord ouvrir sa caisse
    if (D.sessions && !D.session && document.getElementById('ouvrir-session')) { document.getElementById('ouvrir-session').click(); return; }
    etat = { client: null, devise: D.principale, compte: null, montant: 0, cle: C.cle(), envoi: false };
    dessinerRemboursement();
    fenetreR.showModal();
  }

  function choisirCompte() {
    var comptes = C.comptesPour(etat.devise);
    if (!comptes.some(function (c) { return c.id === etat.compte; })) { etat.compte = comptes[0] ? comptes[0].id : null; }
  }

  function dessinerRemboursement(recu) {
    var corps = document.getElementById('ardoises-corps');
    corps.textContent = '';
    if (recu) {
      var zone = el('div', 'ardoises-recu');
      zone.innerHTML = recu;
      corps.appendChild(zone);
      var actions = el('div', 'paiement-fenetre__actions');
      var imprimer = bouton('bouton bouton--secondaire', T.imprimerRecu);
      imprimer.addEventListener('click', function () { C.imprimer(zone.querySelector('.recu').outerHTML); });
      var autre = bouton('bouton', TR.autre);
      autre.id = 'ardoises-autre';
      autre.addEventListener('click', ouvrirRemboursement);
      actions.appendChild(imprimer);
      actions.appendChild(autre);
      corps.appendChild(actions);
      return;
    }
    if (!etat.client) {
      corps.appendChild(choixClient({ id: 'ardoises-recherche', creation: false, choisi: function (c) { chargerClient(c.id); } }));
      return;
    }
    var c = etat.client;
    corps.appendChild(carteClient(c, function () { etat.client = null; dessinerRemboursement(); }));
    if (c.dettes && c.dettes.length) {
      var liste = el('ul', 'ardoises-dettes');
      liste.setAttribute('aria-label', TR.dettes);
      c.dettes.forEach(function (d) {
        var li = el('li', 'ardoises-dette' + (d.enRetard ? ' ardoises-dette--retard' : ''));
        li.appendChild(el('span', '', texte(TR.du_le, { date: d.date }) + ' · ' + texte(TR.echeance, { date: d.echeance })));
        li.appendChild(el('strong', '', d.reste));
        liste.appendChild(li);
      });
      corps.appendChild(liste);
      corps.appendChild(el('p', 'paiement-ardoise__aide', TR.ordre));
    }
    if (!c.encours.length) { return; }

    // Devise, compte, montant
    var codes = D.devises.map(function (d) { return d.code; }).filter(function (code) { return C.comptesPour(code).length > 0 && c.du[code] !== undefined; });
    var segments = el('div', 'segments segments--large');
    segments.setAttribute('role', 'group');
    segments.setAttribute('aria-label', T.payerEn);
    codes.forEach(function (code) {
      var b = bouton('', C.devises[code].symbole);
      b.setAttribute('aria-pressed', code === etat.devise ? 'true' : 'false');
      b.addEventListener('click', function () { etat.devise = code; etat.montant = 0; dessinerRemboursement(); });
      segments.appendChild(b);
    });
    corps.appendChild(segments);
    choisirCompte();
    var bloc = el('div', 'paiement-ligne');
    var tete = el('div', 'paiement-ligne__tete');
    tete.appendChild(el('span', 'paiement-ligne__devise', C.devises[etat.devise].symbole));
    var champ = document.createElement('input');
    champ.className = 'paiement-ligne__montant';
    champ.id = 'ardoises-montant';
    champ.inputMode = 'decimal';
    champ.autocomplete = 'off';
    champ.setAttribute('aria-label', T.recu + ' (' + etat.devise + ')');
    champ.value = etat.montant ? String(etat.montant).replace('.', D.langue === 'fr' ? ',' : '.') : '';
    champ.addEventListener('input', function () {
      var n = parseFloat(champ.value.replace(/[\s  ]/g, '').replace(',', '.'));
      etat.montant = isFinite(n) && n > 0 ? +n.toFixed(C.devises[etat.devise].decimales) : 0;
      valider.disabled = !(etat.montant > 0 && etat.compte) || etat.envoi;
    });
    tete.appendChild(champ);
    tete.appendChild(el('span', 'paiement-ligne__libelle', T.recu));
    bloc.appendChild(tete);
    var comptes = el('div', 'paiement-ligne__comptes');
    C.TYPES.forEach(function (type) {
      var duType = C.comptesPour(etat.devise, type);
      if (!duType.length) { return; }
      var groupe = el('div', 'paiement-type');
      groupe.appendChild(el('span', 'paiement-type__nom', T.types[type]));
      var puces = el('div', 'paiement-type__comptes');
      duType.forEach(function (compte) {
        var b = bouton('puce', compte.nom);
        b.dataset.compte = compte.id;
        b.setAttribute('aria-pressed', compte.id === etat.compte ? 'true' : 'false');
        b.addEventListener('click', function () { etat.compte = compte.id; dessinerRemboursement(); });
        puces.appendChild(b);
      });
      groupe.appendChild(puces);
      comptes.appendChild(groupe);
    });
    bloc.appendChild(comptes);
    if (c.du[etat.devise] > 0) {
      var rapides = el('div', 'paiement-ligne__rapides');
      var tout = bouton('paiement-rapide', texte(TR.tout, { montant: C.formater(c.du[etat.devise], etat.devise) }));
      tout.id = 'ardoises-tout';
      tout.addEventListener('click', function () { etat.montant = c.du[etat.devise]; dessinerRemboursement(); });
      rapides.appendChild(tout);
      bloc.appendChild(rapides);
    }
    corps.appendChild(bloc);
    var erreur = el('p', 'paiement-fenetre__erreur');
    erreur.id = 'ardoises-erreur';
    erreur.setAttribute('role', 'alert');
    erreur.hidden = true;
    corps.appendChild(erreur);
    var valider = bouton('bouton bouton--large paiement-fenetre__valider', TR.valider);
    valider.id = 'ardoises-valider';
    valider.disabled = !(etat.montant > 0 && etat.compte);
    valider.addEventListener('click', function () {
      if (etat.envoi || !(etat.montant > 0)) { return; }
      etat.envoi = true;
      valider.disabled = true;
      valider.textContent = T.envoi;
      envoyer('/caisse/rembourser', { remboursement: JSON.stringify({ cle: etat.cle, etab: D.etab, client: c.id, devise: etat.devise, compte: etat.compte, montant: etat.montant }) })
        .then(function (res) {
          etat.envoi = false;
          if (!res.ok) { throw new Error(res.j.erreur || T.erreurReseau); }
          dessinerRemboursement(res.j.recu);
        })
        .catch(function (e) {
          etat.envoi = false;
          valider.disabled = false;
          valider.textContent = TR.valider;
          erreur.textContent = e.message || T.erreurReseau;
          erreur.hidden = false;
        });
    });
    corps.appendChild(valider);
  }

  function chargerClient(id) {
    lire('/caisse/clients/' + id + '?etab=' + encodeURIComponent(D.etab)).then(function (res) {
      if (!res.ok) { return; }
      etat.client = res.j.client;
      etat.cle = C.cle();
      dessinerRemboursement();
    });
  }

  if (fenetreR) {
    document.getElementById('ardoises').addEventListener('click', ouvrirRemboursement);
    fenetreR.querySelector('[data-fermer-ardoises]').addEventListener('click', function () { fenetreR.close(); });
  }

  C.ardoise = { blocCredit: blocCredit };
})();
