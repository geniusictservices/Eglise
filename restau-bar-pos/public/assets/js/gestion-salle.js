// Salle > Plan de salle : glisser-déposer des tables sur la grille de la zone (cases de 24 × 16), poignée en bas à
// droite pour la taille, flèches du clavier (Maj + flèches : taille). Chaque changement s'enregistre seul
// (/gestion/salle/placer) et met à jour le formulaire de la table, qui reste utilisable sans JavaScript.
(function () {
  'use strict';

  var plan = document.getElementById('sal-plan');
  if (!plan) { return; }
  var modifiable = plan.dataset.modifier === '1';
  var colonnes = +plan.dataset.colonnes;
  var rangees = +plan.dataset.rangees;
  var etat = document.getElementById('sal-plan-etat');
  var aEnregistrer = {};
  var minuterie = null;

  function borner(t) {
    t.l = Math.max(1, Math.min(colonnes, t.l));
    t.h = Math.max(1, Math.min(rangees, t.h));
    t.x = Math.max(0, Math.min(colonnes - t.l, t.x));
    t.y = Math.max(0, Math.min(rangees - t.h, t.y));
    return t;
  }
  function lire(b) { return { id: +b.dataset.id, x: +b.dataset.x, y: +b.dataset.y, l: +b.dataset.l, h: +b.dataset.h }; }
  function appliquer(b, t) {
    ['x', 'y', 'l', 'h'].forEach(function (k) { b.dataset[k] = t[k]; b.style.setProperty('--' + k, t[k]); });
    var form = document.querySelector('form[data-table="' + t.id + '"]');
    if (form) {
      ['x', 'y', 'l', 'h'].forEach(function (k) { var champ = form.querySelector('[data-champ="' + k + '"]'); if (champ) { champ.value = t[k]; } });
    }
  }
  function enregistrer(t) {
    aEnregistrer[t.id] = t;
    clearTimeout(minuterie);
    minuterie = setTimeout(function () {
      var positions = Object.keys(aEnregistrer).map(function (k) { return aEnregistrer[k]; });
      aEnregistrer = {};
      var corps = new FormData();
      corps.append('csrf', plan.dataset.csrf);
      corps.append('positions', JSON.stringify(positions));
      fetch('/gestion/salle/placer', { method: 'POST', body: corps, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { if (!r.ok) { throw new Error(); } etat.textContent = plan.dataset.enregistre; })
        .catch(function () { etat.textContent = plan.dataset.erreur; });
    }, 400);
  }

  if (!modifiable) { return; }

  plan.querySelectorAll('.sal-table').forEach(function (b) {
    var depart = null;
    b.addEventListener('pointerdown', function (ev) {
      if (ev.button !== 0) { return; }
      var rect = plan.getBoundingClientRect();
      depart = {
        px: ev.clientX, py: ev.clientY, t: lire(b), case: { l: rect.width / colonnes, h: rect.height / rangees },
        taille: ev.target.classList.contains('sal-table__poignee'), bouge: false
      };
      b.setPointerCapture(ev.pointerId);
      b.classList.add('sal-table--deplace');
    });
    b.addEventListener('pointermove', function (ev) {
      if (!depart) { return; }
      var dx = Math.round((ev.clientX - depart.px) / depart.case.l);
      var dy = Math.round((ev.clientY - depart.py) / depart.case.h);
      if (dx || dy) { depart.bouge = true; }
      var t = { id: depart.t.id, x: depart.t.x, y: depart.t.y, l: depart.t.l, h: depart.t.h };
      if (depart.taille) { t.l += dx; t.h += dy; } else { t.x += dx; t.y += dy; }
      appliquer(b, borner(t));
    });
    var finir = function () {
      if (!depart) { return; }
      b.classList.remove('sal-table--deplace');
      var t = lire(b);
      if (depart.bouge && (t.x !== depart.t.x || t.y !== depart.t.y || t.l !== depart.t.l || t.h !== depart.t.h)) { enregistrer(t); }
      else if (!depart.bouge) {
        // Simple toucher : le formulaire de la table
        var ligne = document.getElementById('table-' + t.id);
        if (ligne) { var details = ligne.querySelector('details'); if (details) { details.open = true; } ligne.scrollIntoView({ behavior: 'smooth', block: 'center' }); var champ = ligne.querySelector('input[name="nom"]'); if (champ) { champ.focus({ preventScroll: true }); } }
      }
      depart = null;
    };
    b.addEventListener('pointerup', finir);
    b.addEventListener('pointercancel', finir);
    b.addEventListener('keydown', function (ev) {
      var d = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[ev.key];
      if (!d) { return; }
      ev.preventDefault();
      var t = lire(b);
      if (ev.shiftKey) { t.l += d[0]; t.h += d[1]; } else { t.x += d[0]; t.y += d[1]; }
      appliquer(b, borner(t));
      enregistrer(lire(b));
    });
  });
})();
