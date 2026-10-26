/* Gestion des établissements : adresse web et codes proposés à partir du nom, devises,
   couleur, éditeur d'horaires, rappel des modifications non enregistrées.
   Tout fonctionne aussi sans JavaScript. */
(function () {
  'use strict';

  // « Le Comptoir du Lac » → « le-comptoir-du-lac »
  function slugifier(texte, max) {
    var s = String(texte || '').toLowerCase();
    if (s.normalize) { s = s.normalize('NFD').replace(/[̀-ͯ]/g, ''); }
    s = s.replace(/æ/g, 'ae').replace(/œ/g, 'oe').replace(/ß/g, 'ss').replace(/&/g, '-et-')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    return s.slice(0, max).replace(/-+$/, '');
  }

  // Adresse web proposée à partir du nom, tant que l'utilisateur ne l'a pas modifiée
  function lierCode(source, cible, max) {
    if (!source || !cible) { return; }
    cible.addEventListener('input', function () { cible.removeAttribute('data-slug-auto'); cible.removeAttribute('data-code-auto'); });
    source.addEventListener('input', function () {
      if (cible.hasAttribute('data-slug-auto') || cible.hasAttribute('data-code-auto')) {
        // data-code-source="prix|price" : mots retirés du début (« Prix VIP » → « vip »)
        var retirer = source.getAttribute('data-code-source');
        var texte = retirer ? source.value.replace(new RegExp('^\\s*(' + retirer + ')\\s+', 'i'), '') : source.value;
        cible.value = slugifier(texte, max);
      }
    });
  }
  lierCode(document.querySelector('[data-slug-source]'), document.querySelector('[data-slug]'), 64);
  document.querySelectorAll('form').forEach(function (form) {
    lierCode(form.querySelector('[data-code-source]'), form.querySelector('[data-code]'), 32);
  });
  // Saisie de l'adresse : minuscules et tirets au fil de la frappe
  document.querySelectorAll('[data-slug], [data-code]').forEach(function (champ) {
    champ.addEventListener('blur', function () {
      if (champ.value !== '') { champ.value = slugifier(champ.value, champ.hasAttribute('data-slug') ? 64 : 32); }
    });
  });

  // Devise affichée : seulement parmi les devises acceptées
  var cases = document.querySelectorAll('[data-devises] input[type="checkbox"]');
  var affichage = document.querySelector('[data-devise-affichage]');
  function majDevises() {
    if (!affichage) { return; }
    var acceptees = {};
    cases.forEach(function (c) { if (c.checked) { acceptees[c.value] = true; } });
    var premiere = null;
    Array.prototype.forEach.call(affichage.options, function (option) {
      option.disabled = !acceptees[option.value];
      if (!option.disabled && premiere === null) { premiere = option; }
    });
    var choisie = affichage.options[affichage.selectedIndex];
    if ((!choisie || choisie.disabled) && premiere) { premiere.selected = true; }
  }
  cases.forEach(function (c) { c.addEventListener('change', majDevises); });
  majDevises();

  // Couleur d'accent et son aperçu
  var perso = document.querySelector('[data-couleur-perso]');
  var couleur = document.querySelector('[data-couleur]');
  var apercu = document.querySelector('[data-couleur-apercu]');
  if (perso && couleur && apercu) {
    var defaut = apercu.dataset.defaut;
    var majCouleur = function () {
      apercu.style.setProperty('--etb-apercu', perso.checked ? couleur.value : defaut);
      couleur.closest('.etb-couleur__choix').classList.toggle('etb-couleur__choix--inactif', !perso.checked);
    };
    couleur.addEventListener('input', function () { perso.checked = true; majCouleur(); });
    perso.addEventListener('change', majCouleur);
    majCouleur();
  }

  // Logo et couverture : voir champ-image.js (glisser-déposer, médiathèque, aperçu, taille vérifiée)

  // Éditeur d'horaires
  var editeur = document.querySelector('[data-editeur-horaires]');
  if (editeur) {
    var liste = editeur.querySelector('[data-lignes]');
    var modele = editeur.querySelector('[data-modele-horaire]');
    var prochain = liste.querySelectorAll('[data-horaire]').length;

    var estVide = function (ligne) {
      var vide = true;
      ligne.querySelectorAll('input').forEach(function (champ) {
        if ((champ.type === 'checkbox' && champ.checked) || (champ.type !== 'checkbox' && champ.value !== '')) { vide = false; }
      });
      return vide;
    };
    var majNotes = function (ligne) {
      var ouv = ligne.querySelector('[data-ouverture]').value;
      var ferm = ligne.querySelector('[data-fermeture]').value;
      ligne.querySelector('[data-note-lendemain]').hidden = !(ouv && ferm && ferm < ouv);
      ligne.querySelector('[data-note-journee]').hidden = !(ouv && ferm && ferm === ouv);
    };
    var preparer = function (ligne) {
      ligne.querySelectorAll('[data-retirer-horaire], [data-tous-jours]').forEach(function (b) { b.hidden = false; });
      majNotes(ligne);
    };
    var marquer = function () { editeur.dispatchEvent(new Event('change', { bubbles: true })); };

    liste.querySelectorAll('[data-horaire]').forEach(preparer);
    // La ligne vide de secours est inutile quand des services existent déjà
    var lignes = liste.querySelectorAll('[data-horaire]');
    if (lignes.length > 1 && estVide(lignes[lignes.length - 1])) { lignes[lignes.length - 1].remove(); }
    editeur.querySelectorAll('.etb-sans-js').forEach(function (n) { n.hidden = true; });

    var ajouter = editeur.querySelector('[data-ajouter-horaire]');
    ajouter.hidden = false;
    ajouter.addEventListener('click', function () {
      var html = modele.innerHTML.replace(/__N__/g, String(prochain++));
      var tampon = document.createElement('div');
      tampon.innerHTML = html.trim();
      var ligne = tampon.firstElementChild;
      liste.appendChild(ligne);
      preparer(ligne);
      ligne.querySelector('input').focus();
      marquer();
    });

    liste.addEventListener('click', function (evt) {
      var bouton = evt.target.closest('button');
      if (!bouton) { return; }
      var ligne = bouton.closest('[data-horaire]');
      if (bouton.hasAttribute('data-retirer-horaire')) {
        if (liste.querySelectorAll('[data-horaire]').length > 1) {
          ligne.remove();
        } else {
          ligne.querySelectorAll('input').forEach(function (c) { if (c.type === 'checkbox') { c.checked = false; } else { c.value = ''; } });
          majNotes(ligne);
        }
        ajouter.focus();
        marquer();
      } else if (bouton.hasAttribute('data-tous-jours')) {
        var jours = ligne.querySelectorAll('.etb-horaire__jours input');
        var tous = Array.prototype.every.call(jours, function (c) { return c.checked; });
        jours.forEach(function (c) { c.checked = !tous; });
        marquer();
      }
    });
    liste.addEventListener('input', function (evt) {
      var ligne = evt.target.closest('[data-horaire]');
      if (ligne) { majNotes(ligne); }
    });
  }

  // Modifications non enregistrées
  var envoi = false;
  var suivis = document.querySelectorAll('form[data-suivi]');
  suivis.forEach(function (form) {
    var marquerForm = function () {
      form.dataset.modifie = '1';
      var note = form.querySelector('.etb-modifie');
      if (note) { note.hidden = false; }
    };
    form.addEventListener('input', marquerForm);
    form.addEventListener('change', marquerForm);
    form.addEventListener('submit', function () { envoi = true; });
  });
  window.addEventListener('beforeunload', function (evt) {
    if (envoi) { return; }
    for (var i = 0; i < suivis.length; i++) {
      if (suivis[i].dataset.modifie) { evt.preventDefault(); evt.returnValue = ''; return; }
    }
  });
})();
