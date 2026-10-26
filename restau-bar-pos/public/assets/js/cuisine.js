// Écran de cuisine : un bon par envoi d'une note à un poste, en grandes cartes, actualisé toutes les 5 secondes.
// Reçu → en préparation → prêt → servi (un toucher sur le gros bouton) ; toucher un plat le marque prêt seul.
// Temps écoulé depuis l'envoi, alerte de retard (réglée par poste), plats « à suivre » (envoi en plusieurs temps),
// précisions et ALLERGIES en évidence, son et impression automatique facultatifs (mémorisés sur l'appareil),
// ruptures : bloquer un plat le fait disparaître des caisses.
(function () {
  'use strict';

  var D = JSON.parse(document.getElementById('donnees-cuisine').textContent);
  var T = D.textes;
  var elBons = document.getElementById('bons');
  var elVide = document.getElementById('bons-vide');
  var decalage = 0;          // heure du serveur − heure de l'appareil
  var bons = [];
  var connus = null;         // bons déjà vus (son et impression des nouveaux)
  var SUIVANT = { recu: 'preparation', preparation: 'pret', pret: 'servi' };
  var PRECEDENT = { preparation: 'recu', pret: 'preparation', servi: 'pret' };

  function lire(cle, defaut) { try { var v = localStorage.getItem('cuisine.' + cle); return v === null ? defaut : v; } catch (e) { return defaut; } }
  function ecrire(cle, v) { try { localStorage.setItem('cuisine.' + cle, v); } catch (e) { /* stockage indisponible */ } }
  function el(balise, classe, texte) {
    var e = document.createElement(balise);
    if (classe) { e.className = classe; }
    if (texte !== undefined && texte !== null) { e.textContent = texte; }
    return e;
  }
  function bouton(classe, texte) { var b = el('button', classe, texte); b.type = 'button'; return b; }
  function date(texte) { return new Date(texte.replace(' ', 'T')); }

  // ---------- Réglages de l'appareil ----------

  var reglages = { son: lire('son', '0') === '1', impression: lire('impression', '0') === '1' };
  [['son', 'cuisine-son'], ['impression', 'cuisine-impression']].forEach(function (r) {
    var b = document.getElementById(r[1]);
    b.setAttribute('aria-pressed', String(reglages[r[0]]));
    b.addEventListener('click', function () {
      reglages[r[0]] = !reglages[r[0]];
      ecrire(r[0], reglages[r[0]] ? '1' : '0');
      b.setAttribute('aria-pressed', String(reglages[r[0]]));
      if (r[0] === 'son' && reglages.son) { bip(); }
    });
  });
  var audio = null;
  function bip() {
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      [0, 0.18].forEach(function (t) {
        var o = audio.createOscillator(), g = audio.createGain();
        o.frequency.value = 880; o.connect(g); g.connect(audio.destination);
        g.gain.setValueAtTime(0.25, audio.currentTime + t); g.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + t + 0.15);
        o.start(audio.currentTime + t); o.stop(audio.currentTime + t + 0.16);
      });
    } catch (e) { /* son indisponible */ }
  }

  // ---------- Requêtes ----------

  function requete(methode, url, champs) {
    var options = { method: methode, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (methode === 'POST') {
      var corps = new FormData();
      corps.append('csrf', D.csrf);
      corps.append('etab', D.etab);
      Object.keys(champs || {}).forEach(function (k) { corps.append(k, champs[k]); });
      options.body = corps;
    } else {
      url += (url.indexOf('?') === -1 ? '?' : '&') + 'etab=' + encodeURIComponent(D.etab) + '&poste=' + D.poste;
    }
    return fetch(url, options).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (j.csrf) { D.csrf = j.csrf; }
        if (!r.ok) { var e = new Error(j.erreur || T.erreur); e.statut = r.status; throw e; }
        return j;
      });
    });
  }

  // ---------- Bons ----------

  function minutesDepuis(texte) { return Math.max(0, Math.floor((Date.now() + decalage - date(texte).getTime()) / 60000)); }

  function dessiner() {
    elBons.textContent = '';
    elVide.hidden = bons.length > 0;
    bons.forEach(function (b) { elBons.appendChild(carte(b)); });
    majTemps();
  }
  function carte(b) {
    var c = el('article', 'bon-carte bon-carte--' + b.statut);
    c.dataset.bon = b.id;
    c.setAttribute('aria-label', b.titre + ' · ' + T['statut_' + b.statut]);
    var tete = el('header', 'bon-carte__tete');
    var titres = el('div', 'bon-carte__titres');
    titres.appendChild(el('h2', 'bon-carte__titre', b.titre));
    titres.appendChild(el('p', 'bon-carte__info', [b.zone, b.serveur, D.poste ? null : b.posteNom].filter(Boolean).join(' · ')));
    tete.appendChild(titres);
    var temps = el('div', 'bon-carte__temps');
    temps.dataset.depuis = b.depuis;
    temps.dataset.alerte = b.alerte;
    temps.appendChild(el('span', 'bon-carte__minutes'));
    temps.appendChild(el('span', 'badge bon-carte__statut', T['statut_' + b.statut]));
    tete.appendChild(temps);
    c.appendChild(tete);

    var liste = el('ul', 'bon-carte__lignes');
    b.lignes.forEach(function (l) {
      var li = el('li', 'bon-ligne bon-ligne--' + l.statut);
      var zone = bouton('bon-ligne__bouton');
      zone.dataset.ligne = l.id;
      zone.setAttribute('aria-label', l.qte + ' × ' + l.libelle + ' · ' + T['statut_' + l.statut] + ' · ' + (l.statut === 'pret' ? T.ligne_preparation : T.ligne_prete));
      zone.appendChild(el('span', 'bon-ligne__qte', String(l.qte)));
      var texte = el('span', 'bon-ligne__texte');
      texte.appendChild(el('span', 'bon-ligne__nom', l.libelle + (l.format ? ' · ' + l.format : '')));
      if (l.remarque) { texte.appendChild(el('span', 'bon-ligne__remarque', l.remarque)); }
      if (l.allergie) { texte.appendChild(el('span', 'bon-ligne__allergie', T.allergie.replace('{texte}', l.allergie))); }
      zone.appendChild(texte);
      zone.appendChild(el('span', 'bon-ligne__statut', T['statut_' + l.statut]));
      zone.addEventListener('click', function () {
        if (l.statut === 'servi') { return; }
        changer({ lignes: JSON.stringify([l.id]) }, l.statut === 'pret' ? 'preparation' : 'pret');
      });
      li.appendChild(zone);
      liste.appendChild(li);
    });
    c.appendChild(liste);
    if (b.suivants.length) {
      var suite = el('div', 'bon-carte__suite');
      suite.appendChild(el('p', 'bon-carte__suite-titre', T.a_suivre));
      var ul = el('ul', '');
      b.suivants.forEach(function (s) {
        var li = el('li', '', s.qte + ' × ' + s.libelle);
        if (s.allergie) { li.appendChild(el('span', 'bon-ligne__allergie', T.allergie.replace('{texte}', s.allergie))); }
        ul.appendChild(li);
      });
      suite.appendChild(ul);
      c.appendChild(suite);
    }
    var pied = el('footer', 'bon-carte__pied');
    if (SUIVANT[b.statut]) {
      var principal = bouton('bouton bon-carte__avancer', b.statut === 'recu' ? T.commencer : b.statut === 'preparation' ? T.pret : T.servi);
      principal.addEventListener('click', function () { changer({ bon: b.id }, SUIVANT[b.statut]); });
      pied.appendChild(principal);
    }
    if (PRECEDENT[b.statut]) {
      var retour = bouton('bouton bouton--secondaire bon-carte__retour', T.retour);
      retour.addEventListener('click', function () { changer({ bon: b.id }, PRECEDENT[b.statut]); });
      pied.appendChild(retour);
    }
    var imprimerBon = bouton('bouton bouton--secondaire bon-carte__imprimer', T.imprimer);
    imprimerBon.addEventListener('click', function () { imprimer([b.id]); });
    pied.appendChild(imprimerBon);
    c.appendChild(pied);
    return c;
  }
  function majTemps() {
    document.querySelectorAll('.bon-carte__temps').forEach(function (t) {
      var m = minutesDepuis(t.dataset.depuis);
      t.querySelector('.bon-carte__minutes').textContent = T.minutes.replace('{n}', String(m));
      var carte = t.closest('.bon-carte');
      var retard = m >= +t.dataset.alerte && (carte.classList.contains('bon-carte--recu') || carte.classList.contains('bon-carte--preparation'));
      carte.classList.toggle('bon-carte--retard', retard);
      var badge = t.querySelector('.bon-carte__retard');
      if (retard && !badge) { t.appendChild(el('span', 'badge badge--erreur bon-carte__retard', T.retard)); }
      if (!retard && badge) { badge.remove(); }
    });
    var h = new Date(Date.now() + decalage);
    document.getElementById('cuisine-heure').textContent = String(h.getHours()).padStart(2, '0') + ':' + String(h.getMinutes()).padStart(2, '0');
  }
  function changer(champs, statut) {
    champs.statut = statut;
    requete('POST', '/caisse/cuisine/statut', champs).then(charger).catch(function (e) { alerte(e.message); });
  }
  function charger() {
    return requete('GET', '/caisse/cuisine/donnees').then(function (j) {
      decalage = date(j.maintenant).getTime() - Date.now();
      var nouveaux = [];
      if (connus !== null) {
        j.bons.forEach(function (b) { if (!connus[b.id]) { nouveaux.push(b.id); } });
      }
      connus = {};
      j.bons.forEach(function (b) { connus[b.id] = true; });
      bons = j.bons;
      dessiner();
      if (nouveaux.length) {
        if (reglages.son) { bip(); }
        if (reglages.impression) { imprimer(nouveaux); }
      }
    }).catch(function (e) { if (e.statut === 401) { window.location.reload(); } });
  }
  function imprimer(ids) {
    Promise.all(ids.map(function (id) { return requete('GET', '/caisse/cuisine/bon/' + id).then(function (j) { return j.bon; }); }))
      .then(function (html) { document.getElementById('zone-impression').innerHTML = html.join(''); window.print(); })
      .catch(function (e) { alerte(e.message); });
  }
  var elAlerte = null;
  function alerte(texte) {
    if (!elAlerte) { elAlerte = el('p', 'cuisine__alerte cuisine__alerte--erreur'); elAlerte.setAttribute('role', 'alert'); elBons.parentNode.insertBefore(elAlerte, elBons); }
    elAlerte.textContent = texte;
    setTimeout(function () { if (elAlerte) { elAlerte.remove(); elAlerte = null; } }, 5000);
  }

  // ---------- Ruptures ----------

  var fenetre = document.getElementById('fenetre-ruptures');
  if (fenetre) {
    var dessinerRuptures = function (produits) {
      var liste = document.getElementById('ruptures-liste');
      liste.textContent = '';
      if (!produits.length) { liste.appendChild(el('p', 'aide', T.aucun_plat)); }
      var groupe = null, ul = null;
      produits.forEach(function (p) {
        if (p.categorie !== groupe) {
          groupe = p.categorie;
          liste.appendChild(el('h3', 'cuisine-ruptures__groupe', groupe));
          ul = el('ul', 'cuisine-ruptures__produits');
          liste.appendChild(ul);
        }
        var li = el('li', 'cuisine-rupture' + (p.disponible ? '' : ' cuisine-rupture--hors'));
        li.appendChild(el('span', 'cuisine-rupture__nom', p.nom));
        var b = bouton('puce cuisine-rupture__bouton', p.disponible ? T.disponible : T.rupture);
        b.dataset.produit = p.id;
        b.setAttribute('aria-pressed', String(!p.disponible));
        b.addEventListener('click', function () {
          requete('POST', '/caisse/cuisine/rupture', { produit: p.id, disponible: p.disponible ? '0' : '1' }).then(function () {
            p.disponible = !p.disponible;
            dessinerRuptures(produits);
          }).catch(function (e) { alerte(e.message); });
        });
        li.appendChild(b);
        ul.appendChild(li);
      });
    };
    document.getElementById('cuisine-ruptures').addEventListener('click', function () {
      requete('GET', '/caisse/cuisine/ruptures').then(function (j) { dessinerRuptures(j.produits); fenetre.showModal(); }).catch(function (e) { alerte(e.message); });
    });
  }

  charger();
  setInterval(function () { if (!document.hidden) { charger(); } }, D.intervalle);
  setInterval(majTemps, 15000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { charger(); } });
})();
