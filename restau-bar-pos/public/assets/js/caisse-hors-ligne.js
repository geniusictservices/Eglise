// Mode hors ligne de la caisse (cahier des charges, sections 15 et 17), sur la caisse du bar et sur l'écran de
// verrouillage de la caisse partagée :
// - état du réseau (GET /caisse/etat, qui donne aussi l'heure du serveur : l'heure des ventes est corrigée du
//   décalage de l'horloge de l'appareil) et indicateur dans l'en-tête : en ligne / hors ligne, ventes en attente ;
// - vente sans réseau : la vente payée (même texte que /caisse/encaisser, avec sa clé unique, qui a vendu, la
//   session, l'heure) entre dans la file de l'appareil (IndexedDB), signée par la clé hors ligne de la personne ;
//   la caisse montre un reçu provisoire (« En attente d'envoi », numéro HL-…) et continue ;
// - envoi au retour du réseau (évènement « online », essais espacés, bouton « Envoyer maintenant ») : la même clé
//   ne crée jamais deux ventes ; session expirée (419/401) : la file reste, la caisse demande le PIN ou la connexion ;
// - sans réseau, les opérations qui demandent le réseau sont grisées avec une courte explication ;
// - une page servie depuis l'appareil reste en mode hors ligne ; au retour du réseau, la caisse envoie la file puis
//   se recharge (caisse partagée : elle se verrouille d'abord, chacun entre à nouveau son PIN).
(function () {
  'use strict';

  var source = document.getElementById('donnees-hors-ligne');
  var B = window.RbpHorsLigne;
  if (!source || !B) { return; }
  var H = JSON.parse(source.textContent);
  var T = H.textes;
  var C = H.page === 'vente' ? window.Caisse || null : null;
  var D = C ? C.D : null;
  var surCaisse = !!(C && D && D.poste === 'caisse');
  var base = B.disponible();
  var metaCache = document.querySelector('meta[name="rbp-hors-ligne"]');
  var depuisCache = !!metaCache;
  // Caisse partagée ouverte sans réseau (PIN vérifié sur l'appareil) : tout passe par la file jusqu'au PIN en ligne
  var pinHorsLigne = false;
  var enLigne = !depuisCache && navigator.onLine !== false;
  var file = [];
  var envoiEnCours = false;
  var connexionRequise = false;
  var decalage = 0;
  var delaiEssai = 3000;
  var minuteriePing = null;
  var derniereReussite = null;
  var pret;

  function texte(modele, valeurs) {
    return String(modele || '').replace(/\{(\w+)\}/g, function (m, cle) { return valeurs && valeurs[cle] !== undefined ? valeurs[cle] : m; });
  }
  function el(balise, classe, contenu) {
    var e = document.createElement(balise);
    if (classe) { e.className = classe; }
    if (contenu !== undefined) { e.textContent = contenu; }
    return e;
  }
  function csrf() { return D ? D.csrf : H.csrf; }
  // Texte changé seulement s'il diffère (l'observateur de la caisse ne doit pas boucler)
  function poser(n, t) { if (n && n.textContent !== t) { n.textContent = t; } }
  function cacher(n, v) { if (n && n.hidden !== v) { n.hidden = v; } }
  function nouveauJeton(j) {
    if (j && j.csrf) { if (D) { D.csrf = j.csrf; } H.csrf = j.csrf; }
  }
  // Mode hors ligne actif : pas de réseau, ou page qui ne peut pas parler au serveur au nom de la personne
  function actif() { return !enLigne || depuisCache || pinHorsLigne; }

  // ---------- Service worker ----------
  if ('serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register('/caisse/sw.js', { scope: '/caisse' }).then(function () {
      // Première visite : la page n'est pas encore passée par le service worker, il la garde maintenant
      if (!navigator.serviceWorker.controller && !depuisCache) {
        navigator.serviceWorker.ready.then(function (reg) {
          if (reg.active) { reg.active.postMessage({ type: 'garder', url: location.href }); }
        });
      }
    }).catch(function () { /* sans service worker : la file marche quand même tant que la page est ouverte */ });
  }

  // ---------- Réseau ----------

  function avecDelai(promesse, ms) {
    return new Promise(function (ok, ko) {
      var m = setTimeout(function () { ko(new Error('delai')); }, ms);
      promesse.then(function (r) { clearTimeout(m); ok(r); }, function (e) { clearTimeout(m); ko(e); });
    });
  }
  function ping() {
    clearTimeout(minuteriePing);
    var t0 = Date.now();
    return avecDelai(fetch('/caisse/etat', { cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' } }), 6000)
      .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.json(); })
      .then(function (j) {
        var t1 = Date.now();
        if (typeof j.heure === 'number') {
          decalage = Math.round(j.heure - (t0 + t1) / 2);
          if (base) { B.poserEtat('decalage', decalage).catch(function () {}); }
        }
        delaiEssai = 3000;
        changerReseau(true);
      })
      .catch(function () {
        changerReseau(false);
        delaiEssai = Math.min(delaiEssai * 2, 60000);
      })
      .then(function () { minuteriePing = setTimeout(ping, enLigne ? 30000 : delaiEssai); });
  }
  function changerReseau(v) {
    var avant = enLigne;
    enLigne = v;
    if (avant !== v) { appliquer(); marquerPersonnes(); }
    if (v && (file.some(function (i) { return i.statut !== 'refusee'; }) || depuisCache || pinHorsLigne)) {
      synchroniser(false).then(retourDuReseau);
    }
  }
  window.addEventListener('online', function () { delaiEssai = 3000; ping(); });
  window.addEventListener('offline', function () { changerReseau(false); });
  // Heure de l'appareil corrigée par celle du serveur (dernier contact)
  function heure() { return new Date(Date.now() + decalage).toISOString(); }

  // ---------- File des ventes en attente ----------

  function chargerFile() {
    if (!base) { return Promise.resolve(); }
    return B.tous('file').then(function (liste) {
      file = (liste || []).sort(function (a, b) { return a.cree - b.cree; });
    }).catch(function () { file = []; });
  }
  function attendues() { return file.filter(function (i) { return i.statut !== 'envoyee'; }); }

  // Envoie la file dans l'ordre. manuel : réessaie aussi les ventes refusées ou à envoyer par une autre personne
  function synchroniser(manuel) {
    if (envoiEnCours || !base) { return Promise.resolve(); }
    envoiEnCours = true;
    dessiner();
    // Un seul onglet envoie à la fois (le serveur ne compterait de toute façon jamais deux fois la même clé)
    var verrouEnvoi = navigator.locks && navigator.locks.request
      ? function (travail) { return navigator.locks.request('rbp-caisse-envoi', travail); }
      : function (travail) { return travail(); };
    return verrouEnvoi(function () { return envoyerFile(manuel); }).then(function () {
      envoiEnCours = false;
      dessiner();
    }, function () {
      envoiEnCours = false;
      dessiner();
    });
  }
  function envoyerFile(manuel) {
    return chargerFile().then(function () {
      // En attente, session à refaire, à envoyer par une autre personne : toujours ; refusées : seulement à la demande
      var liste = file.filter(function (i) { return i.statut !== 'refusee' || manuel; });
      connexionRequise = false;
      var suite = Promise.resolve(true);
      liste.forEach(function (item) {
        suite = suite.then(function (continuer) { return continuer ? envoyer(item) : false; });
      });
      return suite;
    });
  }
  // Une vente : true pour continuer avec la suivante
  function envoyer(item) {
    var corps = new FormData();
    corps.append('csrf', csrf());
    corps.append('vente', item.texte);
    if (item.signature) { corps.append('signature', item.signature); }
    return avecDelai(fetch('/caisse/encaisser', { method: 'POST', body: corps, credentials: 'same-origin', headers: { Accept: 'application/json' } }), 20000)
      .then(function (r) {
        return r.json().then(function (j) { return { s: r.status, j: j }; }, function () { return { s: r.status, j: null }; });
      })
      .then(function (res) {
        if (res.j === null || res.s === 502 || res.s === 503 || res.s === 504) { changerReseau(false); return false; }
        nouveauJeton(res.j);
        if (res.s === 200) {
          connexionRequise = false;
          derniereReussite = new Date();
          return B.effacer('file', item.cle).then(function () {
            return B.etat('envoyees').then(function (l) {
              return B.poserEtat('envoyees', (l || []).concat([{ cle: item.cle, provisoire: item.numero, numero: res.j.numero, deja: !!res.j.deja,
                ecart: res.j.ecart, principale: item.principale, quand: Date.now() }]).slice(-30));
            });
          }).then(function () { file = file.filter(function (i) { return i.cle !== item.cle; }); return true; });
        }
        if (res.s === 419 || res.s === 401) {
          connexionRequise = true;
          item.statut = 'connexion';
          return B.ecrire('file', item).then(function () { return false; });
        }
        item.statut = res.s === 403 && res.j.code === 'signature' ? 'bloquee' : 'refusee';
        item.message = res.j.erreur || '';
        return B.ecrire('file', item).then(function () { return true; });
      }, function () {
        changerReseau(false);
        return false;
      });
  }

  // Au retour du réseau, une page servie depuis l'appareil se recharge (caisse partagée : verrouillée d'abord)
  function retourDuReseau() {
    if (!(depuisCache || pinHorsLigne) || !enLigne || connexionRequise) { return; }
    var marque = 0;
    try { marque = +sessionStorage.getItem('hl.rechargement') || 0; } catch (e) { /* stockage indisponible */ }
    if (Date.now() - marque < 60000) { return; }
    var attendre = function () {
      if (document.querySelector('dialog[open]')) { setTimeout(attendre, 2000); return; }
      try { sessionStorage.setItem('hl.rechargement', String(Date.now())); } catch (e) { /* stockage indisponible */ }
      bandeau(H.partagee ? T.retour_pin : T.retour, null);
      var avant = H.partagee ? verrouiller() : Promise.resolve();
      avant.catch(function () {}).then(function () { setTimeout(function () { location.reload(); }, 800); });
    };
    attendre();
  }

  // ---------- Vendre sans réseau (appelé par caisse.js) ----------

  // null si la vente peut attendre sur l'appareil, sinon pourquoi elle demande le réseau
  function refusHorsLigne(paiement) {
    if (!surCaisse || !base) { return T.reseau_requis; }
    if (paiement.source || paiement.credit) { return T.reseau_requis; }
    var n = paiement.nuit;
    if (n && ((n.cartes && n.cartes.length) || n.fidelite || n.minimumLever)) { return T.reseau_requis; }
    if (n && n.besoinValidation) { return T.remise_validation; }
    return null;
  }

  // Enregistre la vente dans la file ; résout { recu, numero } (le reçu provisoire à montrer et imprimer)
  function vendre(paiement, vente, reglement) {
    var principale = D.principale;
    var codes = Object.keys(paiement.totaux);
    var renduCode = paiement.rendu;
    var rendu = reglement.rendu && reglement.rendu[renduCode] > 0 ? reglement.rendu[renduCode] : 0;
    return Promise.all([B.suivant('sequence'), B.lire('cles', D.moi).catch(function () { return null; }), H.appareil ? null : marqueNavigateur()]).then(function (r) {
      var sequence = r[0];
      var cle = r[1];
      // Caisse partagée : HL-<appareil>-0007 ; appareil personnel : HL-P<personne>-<navigateur>-0007
      var numero = 'HL-' + (H.appareil ? H.appareil.id : 'P' + D.moi + '-' + r[2]) + '-' + ('000' + sequence).slice(-4);
      var quand = heure();
      vente.moi = D.moi;
      // Prix vus par la caisse et libellés : le serveur en a besoin si un format a changé depuis
      vente.lignes.forEach(function (l) {
        var f = C.formatDe(l.id);
        if (f) { l.pu = f.format.prix; l.nom = C.nomLigne(l.id); }
      });
      vente.hors_ligne = {
        heure: quand, vendeur: D.moi, numero: numero, total: paiement.totaux,
        rendu: { devise: renduCode, montant: rendu }, session: D.session ? D.session.id : null, cle_id: cle ? cle.cleId : null
      };
      var corps = JSON.stringify(vente);
      return (cle && B.cryptoDisponible() ? B.signer(cle.hmac, corps) : Promise.resolve(null)).then(function (signature) {
        var recu = recuProvisoire(paiement, vente, numero, quand, rendu, codes, principale);
        var item = {
          cle: vente.cle, texte: corps, signature: signature, numero: numero, heure: quand, cree: Date.now(), etab: D.etab,
          vendeur: D.moi, vendeurNom: H.nom, statut: 'attente', total: paiement.totaux[principale], principale: principale, recu: recu
        };
        return B.ecrire('file', item).then(function () {
          file.push(item);
          dessiner();
          if (enLigne) { setTimeout(function () { synchroniser(false); }, 300); }
          return { recu: recu, numero: numero };
        });
      });
    });
  }

  // Quatre caractères propres à ce navigateur (deux téléphones d'une même personne n'impriment pas le même numéro)
  function marqueNavigateur() {
    return B.etat('marque').then(function (m) {
      if (m) { return m; }
      m = Math.random().toString(36).slice(2, 6).toUpperCase();
      return B.poserEtat('marque', m).then(function () { return m; });
    });
  }
  function echapper(t) {
    return String(t === null || t === undefined ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  // « 27/10/2026 14:05 », à l'heure de l'établissement (fuseau de l'instance)
  function dateLisible(iso) {
    var d = new Date(iso);
    try {
      var parts = {};
      new Intl.DateTimeFormat('en-GB', { timeZone: H.fuseau, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
        .formatToParts(d).forEach(function (x) { parts[x.type] = x.value; });
      return parts.day + '/' + parts.month + '/' + parts.year + ' ' + parts.hour + ':' + parts.minute;
    } catch (e) {
      var p = function (n) { return ('0' + n).slice(-2); };
      return p(d.getDate()) + '/' + p(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }
  }
  // Reçu provisoire (même présentation que views/caisse/_recu.php), marqué « En attente d'envoi »
  function recuProvisoire(paiement, vente, numero, quand, rendu, codes, principale) {
    var f = C.formater;
    var h = [];
    h.push('<article class="recu recu--provisoire" aria-label="' + echapper(texte(T.provisoire_n, { numero: numero })) + '">');
    h.push('<header class="recu__tete"><p class="recu__etab">' + echapper(H.etabNom) + '</p>');
    if (H.adresse) { h.push('<p class="recu__info">' + echapper(H.adresse) + '</p>'); }
    if (H.telephone) { h.push('<p class="recu__info">' + echapper(H.telephone) + '</p>'); }
    h.push('</header>');
    h.push('<p class="recu__attente">' + echapper(T.en_attente_envoi) + '</p>');
    h.push('<dl class="recu__meta"><div><dt>' + echapper(T.provisoire) + '</dt><dd>' + echapper(numero) + '</dd></div>');
    h.push('<div><dt>' + echapper(T.heure) + '</dt><dd>' + echapper(dateLisible(quand)) + '</dd></div>');
    if (H.nom) { h.push('<div><dt>' + echapper(T.vendeur) + '</dt><dd>' + echapper(H.nom) + '</dd></div>'); }
    if (H.zoneNom) { h.push('<div><dt>' + echapper(T.recu_zone) + '</dt><dd>' + echapper(H.zoneNom) + '</dd></div>'); }
    h.push('</dl><table class="recu__lignes"><tbody>');
    var brut = 0;
    vente.lignes.forEach(function (l) {
      var fo = C.formatDe(l.id);
      var pu = fo && typeof fo.format.prix[principale] === 'number' ? fo.format.prix[principale] : 0;
      brut += pu * l.qte;
      var format = fo && (!fo.format.unite || fo.format.freres > 1) ? fo.format.nom : '';
      h.push('<tr><td class="recu__qte">' + l.qte + ' ×</td><td>' + echapper(C.nomLigne(l.id)) + (format ? '<br><small>' + echapper(format) + '</small>' : '')
        + (l.qte !== 1 ? '<br><small>' + echapper(f(pu, principale)) + '</small>' : '') + '</td><td class="recu__montant">' + echapper(f(pu * l.qte, principale)) + '</td></tr>');
    });
    h.push('</tbody></table>');
    var total = paiement.totaux[principale];
    if (typeof total === 'number' && brut - total > 0.0005) {
      h.push('<div class="recu__sous-total"><span>' + echapper(T.recu_brut) + '</span><span>' + echapper(f(brut, principale)) + '</span></div>');
      h.push('<div class="recu__sous-total"><span>' + echapper(T.recu_remises) + '</span><span>−' + echapper(f(brut - total, principale)) + '</span></div>');
    }
    h.push('<div class="recu__total"><span>' + echapper(T.recu_total) + '</span><strong>' + echapper(f(total || 0, principale)) + '</strong></div>');
    var autres = codes.filter(function (c) { return c !== principale; }).map(function (c) { return f(paiement.totaux[c], c); });
    if (autres.length) { h.push('<p class="recu__equivalent">' + echapper(T.recu_soit + ' ' + autres.join(' · ')) + '</p>'); }
    h.push('<table class="recu__paiements"><tbody>');
    (vente.paiements || []).forEach(function (p) {
      var compte = (D.comptes || []).filter(function (c) { return c.id === p.compte; })[0];
      h.push('<tr><td>' + echapper(compte ? compte.nom : '') + '<br><small>' + echapper(compte ? T.types[compte.type] : '') + '</small></td><td class="recu__montant">'
        + echapper(f(p.recu, p.devise)) + '</td></tr>');
    });
    if (rendu > 0) { h.push('<tr class="recu__rendu"><td>' + echapper(T.recu_rendu) + '</td><td class="recu__montant">' + echapper(f(rendu, paiement.rendu)) + '</td></tr>'); }
    h.push('</tbody></table>');
    h.push('<p class="recu__info recu__info--provisoire">' + echapper(T.recu_note) + '</p>');
    // Plats qui partiraient en cuisine avec le réseau : à annoncer
    var plats = vente.lignes.some(function (l) {
      var fo = C.formatDe(l.id);
      return fo && (H.cuisine || []).indexOf(fo.produit.sous || fo.produit.categorie) !== -1;
    });
    if (plats) { h.push('<p class="recu__info recu__info--cuisine">' + echapper(T.recu_cuisine) + '</p>'); }
    h.push('</article>');
    return h.join('');
  }

  // ---------- Ce qui demande le réseau ----------

  var RESEAU = ['#ardoises', '#garanties', '#salle', '#nuit-cashless', '#nuit-controle', '#nuit-invites', '#nuit-deposer', '#nuit-rendre',
    '#nuit-restants', '.paiements [data-ardoise]', '#ouvrir-session', '#cloturer-session', '#actions-note button', '#ajouter-ardoise',
    '#paiement-carte', '#paiement-fidelite', '#minimum-lever', '#fenetre-quitter a[href="/gestion"]', '#fenetre-quitter [data-poste-lien]',
    '#fenetre-quitter .partager > summary', '#pointage-secours', '#garanties-liste button'];
  function appliquer() {
    var hors = actif();
    var racine = document.getElementById('caisse');
    if (racine) { racine.classList.toggle('caisse--hors-ligne', hors && surCaisse); }
    if (surCaisse) {
      document.querySelectorAll(RESEAU.join(',')).forEach(function (n) {
        if (hors) {
          if (n.dataset.hlReseau) { return; }
          n.dataset.hlReseau = n.disabled ? 'deja' : '1';
          if (n.tagName === 'BUTTON') { n.disabled = true; }
          n.setAttribute('aria-disabled', 'true');
          n.classList.add('hl-reseau');
          n.dataset.hlTitre = n.getAttribute('title') || '';
          n.setAttribute('title', T.reseau_court);
        } else if (n.dataset.hlReseau) {
          if (n.tagName === 'BUTTON' && n.dataset.hlReseau === '1') { n.disabled = false; }
          n.removeAttribute('aria-disabled');
          n.classList.remove('hl-reseau');
          if (n.dataset.hlTitre) { n.setAttribute('title', n.dataset.hlTitre); } else { n.removeAttribute('title'); }
          delete n.dataset.hlReseau;
          delete n.dataset.hlTitre;
        }
      });
      noteTicket(hors);
    }
    dessiner();
    if (C && !appliquer.enCours) {
      appliquer.enCours = true;
      try { C.redessiner(); if (C.paiement()) { C.redessinerPaiement(); } } finally { appliquer.enCours = false; }
    }
  }
  // Un toucher sur ce qui demande le réseau : la raison, au lieu de rien
  document.addEventListener('click', function (ev) {
    var n = ev.target.closest && ev.target.closest('.hl-reseau');
    if (n && actif()) {
      ev.preventDefault();
      ev.stopPropagation();
      bandeau(T.reseau_requis, null, 4000);
    }
  }, true);
  function noteTicket(hors) {
    var note = document.getElementById('hl-note');
    var ticket = document.getElementById('ticket');
    if (!ticket) { return; }
    if (!note) {
      note = el('p', 'note hl-note');
      note.id = 'hl-note';
      note.setAttribute('role', 'status');
      var encaisser = document.getElementById('encaisser');
      ticket.insertBefore(note, encaisser || null);
    }
    var sansSession = D.sessions && !D.session;
    poser(note, hors ? T.aide_court + (sansSession ? ' ' + T.session_hors_ligne : '') : '');
    cacher(note, !hors);
    // Téléphone : le ticket est replié, une bande au-dessus des produits le dit
    var bande = document.getElementById('hl-bande');
    var tete = document.querySelector('.catalogue__tete');
    if (!bande && tete) {
      bande = el('p', 'hl-bande');
      bande.id = 'hl-bande';
      bande.setAttribute('role', 'status');
      tete.insertBefore(bande, tete.firstChild);
    }
    poser(bande, hors ? T.bande : '');
    cacher(bande, !hors);
  }

  // ---------- Indicateur, fenêtre, bandeau ----------

  var bouton = document.getElementById('hl-etat');
  var fenetre = document.getElementById('hl-fenetre');
  var minuterieBandeau = null;
  function bandeau(message, action, duree) {
    var b = document.getElementById('hl-bandeau');
    if (!b) { return; }
    clearTimeout(minuterieBandeau);
    document.getElementById('hl-bandeau-texte').textContent = message || '';
    var a = document.getElementById('hl-bandeau-action');
    a.hidden = !action;
    if (action) { a.textContent = action.texte; a.onclick = action.faire; }
    b.hidden = !message;
    if (duree) { minuterieBandeau = setTimeout(function () { b.hidden = true; }, duree); }
  }
  function dessiner() {
    if (!bouton) { return; }
    var n = attendues().length;
    var hors = actif();
    cacher(bouton, false);
    if (bouton.dataset.etat !== (hors ? 'hors-ligne' : 'en-ligne')) { bouton.dataset.etat = hors ? 'hors-ligne' : 'en-ligne'; }
    poser(document.getElementById('hl-etat-texte'), hors ? T.hors_ligne : T.en_ligne);
    var nombre = document.getElementById('hl-etat-nombre');
    cacher(nombre, n === 0);
    poser(nombre, String(n));
    bouton.setAttribute('aria-label', (hors ? T.hors_ligne : T.en_ligne) + (n ? ' · ' + (n > 1 ? texte(T.en_attente_n, { n: n }) : T.en_attente_1) : ''));
    var b = document.getElementById('hl-bandeau');
    if (!connexionRequise && b && b.dataset.connexion) { delete b.dataset.connexion; b.hidden = true; }
    if (connexionRequise && n && b && !b.dataset.connexion) {
      b.dataset.connexion = '1';
      bandeau(texte(H.partagee ? T.connexion_partagee : T.connexion, { n: n }), {
        texte: H.partagee ? T.deverrouiller : T.reconnecter,
        faire: function () {
          if (H.partagee) {
            B.verrouillerLocal().catch(function () {}).then(function () { location.href = '/caisse'; });
          } else {
            location.href = '/gestion/connexion';
          }
        }
      });
    }
    if (fenetre && fenetre.open) { dessinerFenetre(); }
  }
  function dessinerFenetre() {
    var n = attendues().length;
    var statut = document.getElementById('hl-statut');
    var morceaux = [actif() ? T.hors_ligne : T.en_ligne, n ? (n > 1 ? texte(T.en_attente_n, { n: n }) : T.en_attente_1) : ''];
    if (derniereReussite) { morceaux.push(texte(T.derniere, { heure: dateLisible(derniereReussite.toISOString()) })); }
    if (depuisCache && metaCache.dataset.garde) { morceaux.push(texte(T.page_cache, { date: dateLisible(metaCache.dataset.garde) })); }
    statut.textContent = morceaux.filter(Boolean).join(' · ');
    var liste = document.getElementById('hl-liste');
    liste.textContent = '';
    attendues().forEach(function (item) {
      var li = el('li', 'hl-vente hl-vente--' + item.statut);
      var tete = el('div', 'hl-vente__tete');
      tete.appendChild(el('strong', '', item.numero));
      var montant = C ? C.formater(item.total || 0, item.principale) : String(item.total);
      tete.appendChild(el('span', 'hl-vente__montant', montant));
      li.appendChild(tete);
      li.appendChild(el('span', 'hl-vente__info', dateLisible(item.heure) + (item.vendeurNom ? ' · ' + item.vendeurNom : '')));
      var etiquette = { attente: T.en_attente_envoi, connexion: T.en_attente_envoi, refusee: T.refusee, bloquee: T.bloquee }[item.statut] || '';
      li.appendChild(el('span', 'badge ' + (item.statut === 'refusee' ? 'badge--erreur' : item.statut === 'bloquee' ? 'badge--braise' : 'badge--glace'), etiquette));
      if (item.message) { li.appendChild(el('p', 'hl-vente__message', item.message)); }
      if (item.recu && C) {
        var r = el('button', 'lien', T.reimprimer);
        r.type = 'button';
        r.addEventListener('click', function () { C.imprimer(item.recu); });
        li.appendChild(r);
      }
      liste.appendChild(li);
    });
    document.getElementById('hl-vide').hidden = n !== 0;
    var envoyerB = document.getElementById('hl-envoyer');
    envoyerB.disabled = envoiEnCours || n === 0;
    envoyerB.textContent = envoiEnCours ? T.envoi : T.envoyer;
  }
  if (bouton && fenetre) {
    bouton.addEventListener('click', function () { dessinerFenetre(); fenetre.showModal(); });
    fenetre.querySelector('[data-fermer-hl]').addEventListener('click', function () { fenetre.close(); });
    fenetre.addEventListener('click', function (ev) { if (ev.target === fenetre) { fenetre.close(); } });
    document.getElementById('hl-envoyer').addEventListener('click', function () {
      ping().then(function () { if (enLigne) { return synchroniser(true).then(retourDuReseau); } });
    });
  }

  // ---------- Clé hors ligne de la personne (en ligne) et vérificateurs de la caisse partagée ----------

  function rafraichirCles() {
    if (!base || !B.cryptoDisponible() || depuisCache || !enLigne) { return Promise.resolve(); }
    var travaux = [];
    if (H.partagee) {
      // Personnes de cet appareil et version de leur PIN : un vérificateur d'une personne retirée, bloquée ou dont le
      // PIN a changé est effacé ; les autres sont confirmés (valables 7 jours sans réseau)
      var versions = {};
      H.personnes.forEach(function (p) { versions[p.id] = p.version; });
      travaux.push(B.tous('verificateurs').then(function (liste) {
        return Promise.all(liste.map(function (v) {
          if (versions[v.uid] === undefined || (v.version && versions[v.uid] !== v.version)) {
            return Promise.all([B.effacer('verificateurs', v.uid), B.effacer('cles', v.uid)]);
          }
          v.confirme = Date.now();
          return B.ecrire('verificateurs', v);
        }));
      }));
    }
    if (!surCaisse || !H.moi) { return Promise.all(travaux); }
    return Promise.all(travaux).then(function () {
      return Promise.all([B.lire('cles', H.moi), B.lire('attente', H.moi)]);
    }).then(function (r) {
      var cle = r[0], attente = r[1];
      if (cle && !attente) { return; }
      var corps = new FormData();
      corps.append('csrf', csrf());
      corps.append('etab', H.etab);
      return fetch('/caisse/hors-ligne/cle', { method: 'POST', body: corps, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (rep) { return rep.ok ? rep.json() : null; })
        .then(function (j) { if (j && j.secret && j.utilisateur === H.moi) { return B.enregistrerCle(H.moi, j, attente); } });
    }).catch(function () { /* réessayé au prochain chargement */ });
  }
  // Le service worker prépare la clé du PIN pendant que la caisse se charge : on la guette quelques secondes
  function guetterAttente(n) {
    if (!H.partagee || !surCaisse || depuisCache || n <= 0) { return; }
    B.lire('attente', H.moi).then(function (a) {
      if (a) { rafraichirCles(); } else { setTimeout(function () { guetterAttente(n - 1); }, 700); }
    }).catch(function () {});
  }
  // Évènements vécus sans réseau (PIN faux ou juste, verrouillage) : envoyés au serveur
  function envoyerEvenements() {
    if (!base || !H.partagee || !enLigne) { return; }
    B.etat('evenements').then(function (liste) {
      if (!liste || !liste.length) { return; }
      var corps = new FormData();
      corps.append('csrf', csrf());
      corps.append('evenements', JSON.stringify(liste));
      return fetch('/caisse/hors-ligne/evenements', { method: 'POST', body: corps, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) {
          if (!r.ok) { return; }
          // Seuls les évènements envoyés sont retirés (d'autres ont pu arriver entre-temps)
          return B.etat('evenements').then(function (actuels) { return B.poserEtat('evenements', (actuels || []).slice(liste.length)); });
        });
    }).catch(function () {});
  }

  // ---------- Écran de verrouillage : qui peut entrer sans réseau ----------

  function marquerPersonnes() {
    if (H.page !== 'verrou' || !base) { return; }
    var hors = actif();
    var liens = document.querySelectorAll('.verrou__personne[data-personne]');
    Promise.all([B.tous('verificateurs').catch(function () { return []; }), 'caches' in window ? caches.keys() : Promise.resolve([])]).then(function (r) {
      var verifs = {};
      r[0].forEach(function (v) { verifs[v.uid] = true; });
      var noms = r[1].filter(function (n) { return n.indexOf('rbp-caisse-pages-') === 0; });
      return Promise.all(Array.prototype.map.call(liens, function (lien) {
        var uid = +lien.dataset.personne;
        if (!hors) {
          delete lien.dataset.hlIndisponible;
          lien.classList.remove('verrou__personne--reseau');
          var p = lien.querySelector('.verrou__poste');
          if (p && p.dataset.hlAvant !== undefined) { p.textContent = p.dataset.hlAvant; delete p.dataset.hlAvant; }
          return null;
        }
        var page = noms.length ? caches.open(noms[0]).then(function (c) { return c.match(B.clePage('/caisse?zone=' + (H.zone || '')) + '&p=' + uid); }) : Promise.resolve(null);
        return page.then(function (p) {
          var possible = !!(verifs[uid] && p);
          lien.classList.toggle('verrou__personne--reseau', !possible);
          var poste = lien.querySelector('.verrou__poste');
          if (possible) {
            delete lien.dataset.hlIndisponible;
            if (poste && poste.dataset.hlAvant !== undefined) { poste.textContent = poste.dataset.hlAvant; delete poste.dataset.hlAvant; }
          } else {
            lien.dataset.hlIndisponible = '1';
            // Le poste laisse la place à la raison (« Réseau nécessaire »)
            if (poste && poste.dataset.hlAvant === undefined) { poste.dataset.hlAvant = poste.textContent; poste.textContent = T.pin_court; }
          }
        });
      }));
    }).catch(function () {});
  }

  // ---------- Démarrage ----------

  pret = (base ? Promise.all([chargerFile(), B.etat('decalage').then(function (d) { if (typeof d === 'number') { decalage = d; } }), B.etat('deverrouille')]) : Promise.resolve([null, null, null]))
    .then(function (r) {
      var d = r[2];
      pinHorsLigne = !!(H.partagee && depuisCache && d && d.horsLigne && d.uid === H.moi);
      if (!depuisCache && enLigne && typeof H.heure === 'number') {
        // Page tout juste servie par le serveur : premier décalage de l'horloge (affiné par /caisse/etat)
        decalage = Math.round(H.heure - Date.now());
      }
    }).catch(function () {}).then(function () {
      appliquer();
      marquerPersonnes();
      ping().then(function () {
        if (enLigne) { rafraichirCles(); guetterAttente(10); envoyerEvenements(); marquerPersonnes(); }
      });
    });

  // La caisse redessine son ticket et sa fenêtre de paiement : on regrise ce qui demande le réseau
  var observe = document.getElementById('caisse');
  if (observe && 'MutationObserver' in window) {
    var planifie = false;
    new MutationObserver(function () {
      if (planifie || !actif()) { return; }
      planifie = true;
      requestAnimationFrame(function () { planifie = false; if (actif()) { appliquerSansRedessin(); } });
    }).observe(observe, { childList: true, subtree: true });
  }
  function appliquerSansRedessin() {
    appliquer.enCours = true;
    try { appliquer(); } finally { appliquer.enCours = false; }
  }

  // Verrouiller la caisse partagée après une vente faite sans réseau : l'appareil oublie la personne ; le serveur est
  // prévenu tout de suite s'il répond, sinon au retour du réseau (le service worker ne montre plus sa caisse d'ici là)
  function verrouiller() {
    if (!base) { return Promise.resolve(); }
    return B.verrouillerLocal().then(function (d) {
      return Promise.all([B.poserEtat('verrouAEnvoyer', true), d && d.uid && !enLigne ? B.evenement({ type: 'verrou', uid: d.uid }) : null]);
    }).then(function () {
      if (!enLigne) { return; }
      var corps = new FormData();
      corps.append('csrf', csrf());
      corps.append('ecran', 'caisse');
      return avecDelai(fetch('/caisse/verrouiller', { method: 'POST', body: corps, credentials: 'same-origin', redirect: 'manual' }), 5000)
        .then(function () { return B.poserEtat('verrouAEnvoyer', null); });
    }).catch(function () {});
  }

  window.RbpCaisseHorsLigne = {
    actif: actif, refus: refusHorsLigne, vendre: vendre, pret: function () { return pret; },
    enLigne: function () { return enLigne; }, reseauPerdu: function () { changerReseau(false); },
    // Le serveur répond-il (3 s) ? Après un échec d'envoi : réponse perdue (on réessaie) ou serveur injoignable (file)
    joignable: function () {
      return avecDelai(fetch('/caisse/etat', { cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' } }), 3000)
        .then(function (r) { return r.ok; }, function () { return false; });
    },
    verrouiller: verrouiller,
    synchroniser: synchroniser, file: function () { return attendues(); }, bandeau: bandeau
  };
  if (C) { C.horsLigne = window.RbpCaisseHorsLigne; }
})();
