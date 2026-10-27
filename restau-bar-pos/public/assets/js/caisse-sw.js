// Service worker de la caisse (servi par /caisse/sw.js avec sa version et ses fichiers, portée /caisse).
// - Fichiers de la caisse (CSS, JS, images) : réseau d'abord, cache si le réseau ne répond pas (ou vient de manquer).
// - Page de la caisse du bar (/caisse?e=…&zone=…) : réseau d'abord ; chaque page reçue est gardée par personne
//   (en-têtes X-Caisse-Page / X-Caisse-Personne), l'écran de verrouillage de la caisse partagée aussi. Sans réseau :
//   la page de la personne entrée (caisse partagée) ou de la dernière personne (appareil personnel), sinon l'écran de
//   verrouillage, sinon la page de secours. Jamais une page de la gestion (hors portée), jamais une réponse à un POST.
// - PIN de la caisse partagée (POST /caisse/pin) : en ligne, le serveur décide (et la clé tirée du PIN juste est
//   préparée en arrière-plan) ; sans réseau, le PIN est vérifié sur l'appareil (caisse-hors-ligne-base.js).
// - Verrouiller (POST /caisse/verrouiller) : l'appareil oublie la personne, même sans réseau.
// Une nouvelle version (code mis à jour) remplace tous les caches de la caisse.
/* global RbpHorsLigne */
importScripts('/assets/js/caisse-hors-ligne-base.js');
(function () {
  'use strict';

  var HL = self.RbpHorsLigne;
  var VERSION = self.RBP_VERSION || 'dev';
  var FICHIERS = 'rbp-caisse-fichiers-' + VERSION;
  var PAGES = 'rbp-caisse-pages-' + VERSION;
  var SECOURS = '/caisse/hors-ligne/page';
  var dernierEchec = 0;

  self.addEventListener('install', function (e) {
    e.waitUntil(caches.open(FICHIERS).then(function (cache) {
      return Promise.all((self.RBP_FICHIERS || []).map(function (f) {
        return fetch(f, { cache: 'no-cache', credentials: 'same-origin' }).then(function (r) {
          if (r.ok) { return cache.put(f, r); }
        }).catch(function () { /* gardé plus tard */ });
      }));
    }).then(function () { return self.skipWaiting(); }));
  });

  self.addEventListener('activate', function (e) {
    e.waitUntil(caches.keys().then(function (noms) {
      return Promise.all(noms.filter(function (n) { return n.indexOf('rbp-caisse-') === 0 && n !== FICHIERS && n !== PAGES; })
        .map(function (n) { return caches.delete(n); }));
    }).then(function () { return self.clients.claim(); }));
  });

  // Première visite (la page n'était pas encore servie par le service worker) : la garder tout de suite
  self.addEventListener('message', function (e) {
    var m = e.data || {};
    if (m.type === 'garder' && typeof m.url === 'string') {
      var url = new URL(m.url);
      if (url.origin === self.location.origin && url.pathname === '/caisse') {
        e.waitUntil(fetch(url.href, { credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Caisse-Garde': '1' } }).then(function (r) { return garder(r, url); }).catch(function () {}));
      }
    }
  });

  self.addEventListener('fetch', function (e) {
    var req = e.request;
    var url = new URL(req.url);
    if (url.origin !== self.location.origin) { return; }
    if (req.mode === 'navigate') {
      if (req.method === 'GET' && url.pathname === '/caisse') { e.respondWith(page(e, url)); return; }
      if (req.method === 'POST' && url.pathname === '/caisse/pin') { e.respondWith(pin(e)); return; }
      if (req.method === 'POST' && url.pathname === '/caisse/verrouiller') { e.respondWith(verrou(e)); return; }
      if (req.method === 'GET' && url.pathname.indexOf('/caisse/') === 0) { e.respondWith(autre(e)); }
      return;
    }
    if (req.method === 'GET' && (url.pathname.indexOf('/assets/') === 0 || url.pathname.indexOf('/uploads/') === 0)) {
      e.respondWith(fichier(e));
    }
  });

  function enLigneProbable() { return self.navigator.onLine !== false; }
  function recemmentHorsLigne() { return !enLigneProbable() || Date.now() - dernierEchec < 30000; }
  // Réponse du réseau dans le délai, sinon une erreur (la requête continue : sa réponse sera gardée)
  function avecDelai(promesse, ms) {
    return new Promise(function (ok, ko) {
      var minuterie = setTimeout(function () { ko(new Error('delai')); }, ms);
      promesse.then(function (r) { clearTimeout(minuterie); ok(r); }, function (err) { clearTimeout(minuterie); ko(err); });
    });
  }

  // ---------- Pages ----------

  function garder(r, url) {
    if (!r || !r.ok || r.type !== 'basic') { return Promise.resolve(); }
    var type = r.headers.get('X-Caisse-Page');
    if (type !== 'vente' && type !== 'verrou') { return Promise.resolve(); }
    var partagee = r.headers.get('X-Caisse-Partagee') === '1';
    return caches.open(PAGES).then(function (cache) {
      var travaux = [HL.poserEtat('appareil', { partagee: partagee })];
      if (type === 'vente') {
        var uid = +r.headers.get('X-Caisse-Personne');
        var cle = HL.clePage(url);
        travaux.push(cache.put(cle + '&p=' + uid, r), HL.poserEtat('dernier:' + cle, uid));
        if (partagee) { travaux.push(HL.poserEtat('deverrouille', { uid: uid, horsLigne: false, depuis: Date.now() })); }
      } else {
        // Le serveur dit « verrouillé » : l'appareil aussi
        if (!url.searchParams.has('personne')) { travaux.push(cache.put(HL.cleVerrou(), r)); }
        travaux.push(HL.verrouillerLocal(), HL.poserEtat('verrouAEnvoyer', null));
      }
      return Promise.all(travaux);
    });
  }

  function page(e, url) {
    if (!enLigneProbable()) { return depuisCache(url); }
    var traite = fetch(e.request).then(function (r) { return verifier(r, url); });
    // Même arrivée après le délai, la page reçue est gardée (verifier() la garde)
    e.waitUntil(traite.catch(function () {}));
    return avecDelai(traite, 10000).then(function (r) { return r; }, function () {
      dernierEchec = Date.now();
      return depuisCache(url);
    });
  }

  // Caisse partagée verrouillée sans réseau (ou ouverte sans réseau par une autre personne) : le serveur, qui ne l'a
  // pas su, garde peut-être la personne d'avant. Sa caisse n'est jamais montrée : la session du serveur est verrouillée
  // d'abord, puis l'écran de verrouillage est demandé à nouveau.
  function verifier(r, url) {
    var vente = r.ok && r.type === 'basic' && r.headers.get('X-Caisse-Page') === 'vente' && r.headers.get('X-Caisse-Partagee') === '1';
    if (!vente) { return garder(r.clone(), url).then(function () { return r; }); }
    var uid = +r.headers.get('X-Caisse-Personne');
    return Promise.all([HL.etat('verrouAEnvoyer'), HL.etat('deverrouille')]).then(function (x) {
      var perime = !!x[0] || !!(x[1] && x[1].horsLigne && x[1].uid !== uid);
      if (!perime) { return garder(r.clone(), url).then(function () { return r; }); }
      return r.text().then(function (html) {
        var m = html.match(/"csrf":"([0-9a-f]{64})"/);
        var corps = new FormData();
        corps.append('csrf', m ? m[1] : '');
        corps.append('ecran', 'caisse');
        return fetch('/caisse/verrouiller', { method: 'POST', body: corps, credentials: 'same-origin', redirect: 'manual' });
      }).then(function () {
        return Promise.all([HL.verrouillerLocal(), HL.poserEtat('verrouAEnvoyer', null)]);
      }).then(function () {
        return fetch(url.href, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
      }).then(function (r2) {
        if (r2.redirected) { return Response.redirect(r2.url, 303); }
        return garder(r2.clone(), url).then(function () { return r2; });
      });
    });
  }

  function depuisCache(url) {
    return Promise.all([caches.open(PAGES), HL.etat('appareil'), HL.etat('deverrouille'), HL.etat('dernier:' + HL.clePage(url))]).then(function (r) {
      var cache = r[0], appareil = r[1] || {}, entre = r[2], dernier = r[3];
      var cle = HL.clePage(url);
      var essais = [];
      if (appareil.partagee) {
        if (entre && entre.uid) { essais.push(cle + '&p=' + entre.uid); }
        else { essais.push(HL.cleVerrou()); }
      } else if (dernier) {
        essais.push(cle + '&p=' + dernier);
      }
      return essais.reduce(function (p, k) {
        return p.then(function (trouve) { return trouve || cache.match(k); });
      }, Promise.resolve(null)).then(function (trouve) {
        return trouve ? marquer(trouve) : secours();
      });
    });
  }

  // Page servie depuis l'appareil : la caisse le sait (elle reste en mode hors ligne jusqu'au retour du réseau)
  function marquer(r) {
    var garde = r.headers.get('Date') || '';
    return r.text().then(function (html) {
      var meta = '<meta name="rbp-hors-ligne" content="cache" data-garde="' + garde.replace(/"/g, '') + '">';
      return new Response(html.replace(/<head>/i, '<head>' + meta), {
        status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8', 'X-Caisse-Depuis-Cache': '1' }
      });
    });
  }

  function secours() {
    return caches.open(FICHIERS).then(function (cache) { return cache.match(SECOURS); }).then(function (r) {
      return r || new Response('<!doctype html><meta charset="utf-8"><title>Offline</title><p>Pas de réseau · No network</p>',
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
    });
  }

  function autre(e) {
    if (!enLigneProbable()) { return secours(); }
    return avecDelai(fetch(e.request), 10000).catch(function () { dernierEchec = Date.now(); return secours(); });
  }

  // ---------- PIN et verrouillage de la caisse partagée ----------

  function pin(e) {
    var req = e.request;
    return req.clone().formData().then(function (form) {
      var uid = +form.get('utilisateur_id');
      var code = String(form.get('pin') || '');
      var zone = String(form.get('zone') || '');
      var suite = zone && /^[a-z0-9-]+$/.test(zone) ? '&zone=' + zone : '';
      var horsLigne = function () {
        return HL.deverrouiller(uid, code, function (id) {
          return caches.open(PAGES).then(function (cache) {
            return cache.match(HL.clePage('/caisse?zone=' + zone) + '&p=' + id).then(function (r) { return r || cache.match(HL.clePage('/caisse') + '&p=' + id); });
          }).then(function (r) { return !!r; });
        }).then(function (res) {
          if (res.ok) { return Response.redirect('/caisse' + (suite ? '?' + suite.slice(1) : ''), 303); }
          return Response.redirect('/caisse?personne=' + uid + '&hl=' + res.code + (res.reste ? '&reste=' + res.reste : '') + suite, 303);
        });
      };
      if (!enLigneProbable()) { return horsLigne(); }
      return avecDelai(fetch(req), 10000).then(function (r) {
        if (r.type === 'opaqueredirect' || (r.status >= 300 && r.status < 400)) {
          // PIN accepté par le serveur : la clé tirée de ce PIN, pour le vérifier plus tard sans réseau
          e.waitUntil(HL.preparerAttente(uid, code).catch(function () {}));
          return Promise.all([HL.poserEtat('deverrouille', { uid: uid, horsLigne: false, depuis: Date.now() }), HL.poserEtat('verrouAEnvoyer', null)])
            .catch(function () {}).then(function () { return r; });
        }
        return r;
      }, function () {
        dernierEchec = Date.now();
        return horsLigne();
      });
    });
  }

  function verrou(e) {
    var req = e.request;
    return req.clone().formData().then(function (form) {
      var ecran = form.get('ecran');
      var chemin = '/caisse' + (ecran === 'entree' || ecran === 'vestiaire' ? '/' + ecran : '');
      return HL.verrouillerLocal().then(function (avant) {
        // Sans réseau : le serveur sera verrouillé au retour du réseau, avant de montrer une caisse
        var local = function () {
          return Promise.all([avant && avant.uid ? HL.evenement({ type: 'verrou', uid: avant.uid }) : null, HL.poserEtat('verrouAEnvoyer', true)]).then(function () {
            return Response.redirect(chemin, 303);
          });
        };
        if (!enLigneProbable()) { return local(); }
        return avecDelai(fetch(req), 8000).then(function (r) {
          return HL.poserEtat('verrouAEnvoyer', null).then(function () { return r; });
        }, function () { dernierEchec = Date.now(); return local(); });
      });
    });
  }

  // ---------- Fichiers (CSS, JS, images) ----------

  function fichier(e) {
    var req = e.request;
    return caches.open(FICHIERS).then(function (cache) {
      var duCache = function () { return cache.match(req, { ignoreSearch: true }); };
      var reseau = function () {
        return avecDelai(fetch(req), 4000).then(function (r) {
          if (r.ok && r.type === 'basic' && r.status === 200) { e.waitUntil(cache.put(req, r.clone()).catch(function () {})); }
          return r;
        }, function (err) {
          dernierEchec = Date.now();
          return duCache().then(function (c) { if (c) { return c; } throw err; });
        });
      };
      if (recemmentHorsLigne()) {
        return duCache().then(function (c) { return c || reseau(); });
      }
      return reseau();
    });
  }
})();
