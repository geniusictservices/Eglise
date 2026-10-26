/* Médiathèque : grande zone de dépôt (glisser-déposer, choix, collage), envoi de chaque image avec sa
   barre de progression et un message clair en cas de refus, nouvelles images ajoutées à la grille ;
   tri appliqué dès le choix ; fiche : copie de l'adresse. Sans JavaScript, le formulaire d'envoi classique
   et les liens fonctionnent. S'appuie sur window.EnvoiImages (champ-image.js). */
(function () {
  'use strict';

  // Tri : appliqué dès le choix
  document.querySelectorAll('select[data-auto]').forEach(function (liste) {
    liste.addEventListener('change', function () { liste.form.submit(); });
  });

  // Fiche : copier l'adresse de l'image
  var copier = document.querySelector('[data-copier]');
  var adresse = document.querySelector('[data-adresse]');
  var retour = document.querySelector('[data-copie]');
  if (copier && adresse && retour) {
    copier.hidden = false;
    var annoncerCopie = function (ok) { retour.textContent = ok ? copier.dataset.ok : copier.dataset.echec; };
    var copieManuelle = function () {
      adresse.focus();
      adresse.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      annoncerCopie(ok);
    };
    copier.addEventListener('click', function () {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(adresse.value).then(function () { annoncerCopie(true); }, copieManuelle);
      } else {
        copieManuelle();
      }
    });
    adresse.addEventListener('focus', function () { adresse.select(); });
  }

  // Zone de dépôt
  var depot = document.querySelector('form[data-depot]');
  var outils = window.EnvoiImages;
  if (!depot || !outils) { return; }

  var entree = depot.querySelector('[data-fichier]');
  var liste = document.querySelector('[data-envois]');
  var annonce = document.querySelector('[data-annonce]');
  var grille = document.querySelector('[data-grille]');
  var textes = outils.config.textes;
  var MAX_FICHIERS = 30;

  depot.classList.add('depot--actif');
  depot.querySelector('[data-invite]').hidden = false;
  depot.querySelectorAll('[data-repli]').forEach(function (el) { el.hidden = true; });

  var file = Promise.resolve();

  function ajouterALaGrille(media) {
    if (!grille || !grille.hasAttribute('data-inserer') || !media.html) { return; }
    var tampon = document.createElement('template');
    tampon.innerHTML = media.html.trim();
    var carte = tampon.content.firstElementChild;
    if (!carte) { return; }
    carte.classList.add('med-carte--nouvelle');
    grille.insertBefore(carte, grille.firstChild);
    var vide = document.querySelector('[data-vide]');
    if (vide) { vide.remove(); }
    ['toutes', 'non-utilisees'].forEach(function (cle) {
      var compteur = document.querySelector('[data-compteur="' + cle + '"]');
      if (compteur) { compteur.textContent = String(Number(compteur.textContent) + 1); }
    });
  }

  function envoyerTous(fichiers) {
    if (!fichiers.length) { return; }
    liste.hidden = false;
    if (fichiers.length > MAX_FICHIERS) {
      annonce.textContent = outils.texte('trop_de_fichiers', { n: MAX_FICHIERS });
      fichiers = fichiers.slice(0, MAX_FICHIERS);
    }
    fichiers.forEach(function (fichier) {
      var ligne = outils.ligneEnvoi(fichier);
      liste.insertBefore(ligne.li, liste.firstChild);
      var erreur = outils.verifier(fichier);
      if (erreur) {
        ligne.refuser(erreur);
        annonce.textContent = (fichier.name || '') + ' : ' + textes.refusee + ' ' + erreur;
        return;
      }
      // Un envoi après l'autre : chaque barre avance à son tour
      file = file.then(function () {
        return outils.envoyer(fichier, ligne.progression).then(function (media) {
          var lien = outils.element('a', { href: media.fiche, 'class': 'lien', texte: media.nom });
          ligne.reussir(textes.ajoutee_court, lien);
          annonce.textContent = outils.texte('ajoutee', { nom: media.nom });
          ajouterALaGrille(media);
        }, function (err) {
          ligne.refuser(err.message);
          annonce.textContent = (fichier.name || '') + ' : ' + textes.refusee + ' ' + err.message;
        });
      });
    });
  }

  depot.querySelector('[data-parcourir]').addEventListener('click', function (evt) {
    evt.stopPropagation();
    entree.click();
  });
  // Toucher la zone (en dehors du bouton) ouvre aussi le choix des fichiers
  depot.addEventListener('click', function (evt) {
    if (evt.target.closest('button, a, input, label')) { return; }
    entree.click();
  });
  entree.addEventListener('change', function () {
    var fichiers = outils.fichiersDe(entree);
    entree.value = '';
    envoyerTous(fichiers);
  });
  // Toute la page accepte le dépôt ; la zone s'allume pendant le survol
  outils.brancherDepot(document.querySelector('main') || document.body, envoyerTous, depot, 'depot--survol');
  // Coller une image (Ctrl+V) n'importe où dans la page
  document.addEventListener('paste', function (evt) {
    var fichiers = outils.fichiersDe(evt.clipboardData);
    if (!fichiers.length) { return; }
    evt.preventDefault();
    envoyerTous(fichiers);
  });
})();
