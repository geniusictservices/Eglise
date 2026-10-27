// Mode hors ligne de la caisse : base commune à la page (caisse-hors-ligne.js) et au service worker (caisse-sw.js).
// - IndexedDB « rbp-caisse » (une base par instance, l'origine étant le sous-domaine du client) :
//   file : ventes payées sans réseau, en attente d'envoi (le texte exact envoyé, sa signature, le reçu provisoire) ;
//   etat : petits états (personne entrée, décalage de l'horloge, numéro provisoire, essais de PIN, évènements…) ;
//   verificateurs : caisse partagée, clé hors ligne de chaque personne chiffrée par une clé tirée de son PIN ;
//   cles : clé de signature (HMAC, non extractible) de la personne entrée ; attente : clé tirée d'un PIN juste
//   (le service worker la prépare pendant que la caisse se charge, la caisse s'en sert pour chiffrer la clé hors ligne).
// - PIN sans réseau : PBKDF2-SHA256 (150 000 tours, sel aléatoire) → AES-GCM. Le PIN n'est jamais gardé ; un
//   déchiffrement réussi prouve le PIN. 5 essais faux pour une personne : son vérificateur est effacé (il faudra le
//   réseau) ; 15 essais faux sur l'appareil en 10 minutes : plus aucun PIN pendant 10 minutes (comme en ligne).
(function (racine) {
  'use strict';

  var NOM = 'rbp-caisse';
  var ITERATIONS = 150000;
  var ESSAIS = 5;
  var ECHECS_APPAREIL = 15;
  var FENETRE_ECHECS = 600000;
  // Un vérificateur qui n'a pas été confirmé en ligne depuis 7 jours ne sert plus
  var VALIDITE = 7 * 86400000;
  var base = null;

  function disponible() { return typeof indexedDB !== 'undefined'; }
  function cryptoDisponible() { return !!(racine.crypto && racine.crypto.subtle); }

  function ouvrir() {
    if (base) { return base; }
    base = new Promise(function (ok, ko) {
      var r;
      try { r = indexedDB.open(NOM, 1); } catch (e) { base = null; ko(e); return; }
      r.onupgradeneeded = function () {
        var db = r.result;
        [['file', 'cle'], ['etat', 'k'], ['verificateurs', 'uid'], ['cles', 'uid'], ['attente', 'uid']].forEach(function (s) {
          if (!db.objectStoreNames.contains(s[0])) { db.createObjectStore(s[0], { keyPath: s[1] }); }
        });
      };
      r.onsuccess = function () {
        var db = r.result;
        db.onversionchange = function () { db.close(); base = null; };
        ok(db);
      };
      r.onerror = function () { base = null; ko(r.error); };
    });
    return base;
  }
  // Une opération sur un magasin ; la promesse se résout quand la transaction est terminée
  function operation(magasin, mode, travail) {
    return ouvrir().then(function (db) {
      return new Promise(function (ok, ko) {
        var t = db.transaction(magasin, mode);
        var resultat;
        var requete = travail(t.objectStore(magasin));
        if (requete) { requete.onsuccess = function () { resultat = requete.result; }; }
        t.oncomplete = function () { ok(resultat); };
        t.onerror = function () { ko(t.error); };
        t.onabort = function () { ko(t.error); };
      });
    });
  }
  function lire(magasin, cle) { return operation(magasin, 'readonly', function (s) { return s.get(cle); }); }
  function ecrire(magasin, valeur) { return operation(magasin, 'readwrite', function (s) { return s.put(valeur); }); }
  function effacer(magasin, cle) { return operation(magasin, 'readwrite', function (s) { return s.delete(cle); }); }
  function tous(magasin) { return operation(magasin, 'readonly', function (s) { return s.getAll(); }); }
  function etat(k) { return lire('etat', k).then(function (e) { return e ? e.v : null; }); }
  function poserEtat(k, v) { return v === null || v === undefined ? effacer('etat', k) : ecrire('etat', { k: k, v: v }); }
  // Compteur de l'appareil (numéros des reçus provisoires) : jamais deux fois le même, même avec deux onglets
  function suivant(k) {
    return ouvrir().then(function (db) {
      return new Promise(function (ok, ko) {
        var t = db.transaction('etat', 'readwrite');
        var s = t.objectStore('etat');
        var n = 0;
        var r = s.get(k);
        r.onsuccess = function () { n = (r.result ? r.result.v : 0) + 1; s.put({ k: k, v: n }); };
        t.oncomplete = function () { ok(n); };
        t.onerror = function () { ko(t.error); };
      });
    });
  }

  // ---------- Clés des pages gardées par le service worker ----------
  // La caisse du bar d'une personne, par établissement et par zone ; l'écran de verrouillage de l'appareil
  function clePage(url) {
    var u = typeof url === 'string' ? new URL(url, racine.location.href) : url;
    return '/caisse/__hl/page?e=' + encodeURIComponent(u.searchParams.get('e') || '') + '&zone=' + encodeURIComponent(u.searchParams.get('zone') || '');
  }
  function cleVerrou() { return '/caisse/__hl/verrou'; }

  // ---------- Chiffrement ----------
  function texteEnOctets(t) { return new TextEncoder().encode(t); }
  function versHex(tampon) {
    return Array.prototype.map.call(new Uint8Array(tampon), function (o) { return ('0' + o.toString(16)).slice(-2); }).join('');
  }
  function depuisHex(hex) {
    var o = new Uint8Array(hex.length / 2);
    for (var i = 0; i < o.length; i++) { o[i] = parseInt(hex.substr(i * 2, 2), 16); }
    return o;
  }
  function aleatoire(n) { var o = new Uint8Array(n); racine.crypto.getRandomValues(o); return o; }
  function deriver(pin, sel) {
    var s = racine.crypto.subtle;
    return s.importKey('raw', texteEnOctets(String(pin)), 'PBKDF2', false, ['deriveKey']).then(function (k) {
      return s.deriveKey({ name: 'PBKDF2', salt: sel, iterations: ITERATIONS, hash: 'SHA-256' }, k, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
    });
  }
  function cleHmac(octets) {
    return racine.crypto.subtle.importKey('raw', octets, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  }
  function signer(cle, texte) {
    return racine.crypto.subtle.sign('HMAC', cle, texteEnOctets(texte)).then(versHex);
  }

  // ---------- Caisse partagée : PIN vérifié sur l'appareil ----------

  // Service worker, PIN juste en ligne : la clé tirée de ce PIN, gardée le temps que la caisse chiffre la clé hors ligne
  function preparerAttente(uid, pin) {
    if (!cryptoDisponible() || !(uid > 0) || !/^\d{6}$/.test(String(pin))) { return Promise.resolve(); }
    var sel = aleatoire(16);
    return deriver(pin, sel).then(function (cle) {
      return Promise.all([ecrire('attente', { uid: uid, sel: sel, cle: cle, cree: Date.now() }), poserEtat('essais:' + uid, null)]);
    });
  }
  // Caisse (en ligne) : clé hors ligne reçue du serveur → vérificateur (chiffré par la clé du PIN) et clé de signature
  function enregistrerCle(uid, reponse, attente) {
    var secret = depuisHex(reponse.secret);
    return cleHmac(secret).then(function (hmac) {
      var travaux = [ecrire('cles', { uid: uid, cleId: reponse.id, hmac: hmac })];
      if (attente) {
        var iv = aleatoire(12);
        travaux.push(racine.crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv }, attente.cle, secret).then(function (chiffre) {
          return ecrire('verificateurs', { uid: uid, version: reponse.version, sel: attente.sel, iv: iv, chiffre: chiffre, cleId: reponse.id, confirme: Date.now() });
        }).then(function () { return effacer('attente', uid); }));
      }
      return Promise.all(travaux);
    });
  }
  function evenement(ev) {
    return etat('evenements').then(function (liste) {
      liste = (liste || []).concat([Object.assign({ heure: new Date().toISOString() }, ev)]).slice(-200);
      return poserEtat('evenements', liste);
    });
  }
  // PIN tapé sans réseau : { ok } ou { ok: false, code: indisponible|incorrect|bloque|appareil, reste }
  function deverrouiller(uid, pin, pageExiste) {
    var maintenant = Date.now();
    return Promise.all([lire('verificateurs', uid), etat('essais:' + uid), etat('echecs')]).then(function (r) {
      var verif = r[0];
      var essais = r[1] || 0;
      var echecs = (r[2] || []).filter(function (t) { return t > maintenant - FENETRE_ECHECS; });
      if (echecs.length >= ECHECS_APPAREIL) { return { ok: false, code: 'appareil' }; }
      if (!verif || !cryptoDisponible() || maintenant - (verif.confirme || 0) > VALIDITE) { return { ok: false, code: 'indisponible' }; }
      if (essais >= ESSAIS) { return { ok: false, code: 'bloque' }; }
      return Promise.resolve(pageExiste ? pageExiste(uid) : true).then(function (existe) {
        if (!existe) { return { ok: false, code: 'indisponible' }; }
        return deriver(pin, verif.sel).then(function (cle) {
          return racine.crypto.subtle.decrypt({ name: 'AES-GCM', iv: verif.iv }, cle, verif.chiffre);
        }).then(function (secret) {
          return cleHmac(new Uint8Array(secret)).then(function (hmac) {
            return Promise.all([
              ecrire('cles', { uid: uid, cleId: verif.cleId, hmac: hmac }),
              poserEtat('essais:' + uid, null),
              poserEtat('deverrouille', { uid: uid, horsLigne: true, depuis: maintenant }),
              evenement({ type: 'pin_ok', uid: uid })
            ]);
          }).then(function () { return { ok: true }; });
        }, function () {
          // Mauvais PIN (le déchiffrement échoue) : un essai de moins ; au 5e, le vérificateur est effacé
          essais++;
          echecs.push(maintenant);
          var travaux = [poserEtat('essais:' + uid, essais), poserEtat('echecs', echecs), evenement({ type: 'pin_echec', uid: uid })];
          if (essais >= ESSAIS) { travaux.push(effacer('verificateurs', uid)); }
          return Promise.all(travaux).then(function () {
            return essais >= ESSAIS ? { ok: false, code: 'bloque' } : { ok: false, code: 'incorrect', reste: ESSAIS - essais };
          });
        });
      });
    });
  }
  // Verrouiller sur l'appareil : plus personne d'entré, sa clé de signature effacée (caisse partagée)
  function verrouillerLocal() {
    return etat('deverrouille').then(function (d) {
      var travaux = [poserEtat('deverrouille', null)];
      if (d && d.uid) { travaux.push(effacer('cles', d.uid)); }
      return Promise.all(travaux).then(function () { return d; });
    });
  }

  racine.RbpHorsLigne = {
    ESSAIS: ESSAIS, disponible: disponible, cryptoDisponible: cryptoDisponible,
    lire: lire, ecrire: ecrire, effacer: effacer, tous: tous, etat: etat, poserEtat: poserEtat, suivant: suivant,
    clePage: clePage, cleVerrou: cleVerrou, signer: signer, cleHmac: cleHmac, depuisHex: depuisHex,
    preparerAttente: preparerAttente, enregistrerCle: enregistrerCle, deverrouiller: deverrouiller, verrouillerLocal: verrouillerLocal,
    evenement: evenement
  };
})(typeof self !== 'undefined' ? self : window);
